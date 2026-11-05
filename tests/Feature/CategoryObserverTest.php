<?php

use App\Models\AdminUser;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/**
 * Real commits are required to verify image cleanup at the outermost transaction.
 */
uses(DatabaseMigrations::class);

test('failed category creation cleans up the newly uploaded image and returns 500', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_category_insert BEFORE INSERT ON categories BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to create the category. Please try again later.')
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    $this->assertDatabaseCount('categories', 0);
    expect(Storage::disk('public')->allFiles('categories'))->toBe([]);
    Exceptions::assertReported(QueryException::class);
});

test('failed category updates preserve the old image and remove the replacement', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['title' => 'Original', 'image' => $oldPath]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_category_update BEFORE UPDATE ON categories BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/categories/'.$category->id, [
        '_method' => 'PATCH', 'title' => 'Changed', 'image' => UploadedFile::fake()->image('new.png'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to update the category. Please try again later.');
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'title' => 'Original', 'image' => $oldPath]);
    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->allFiles('categories'))->toBe([$oldPath]);
    Exceptions::assertReported(QueryException::class);
});

test('slug conflicts after validation return 409 and clean up the uploaded image', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER conflicting_category_insert BEFORE INSERT ON categories BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: categories.slug'); END");

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertConflict()->assertJsonPath('message', 'A record with these details already exists.');
    $this->assertDatabaseCount('categories', 0);
    expect(Storage::disk('public')->allFiles('categories'))->toBe([]);
});

test('replaced images are removed only after the outermost transaction commits', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $category = Category::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($category): void {
        $category->uploadedImage = UploadedFile::fake()->image('new.png');
        $category->save();
    });

    $newPath = $category->image;
    Storage::disk('public')->assertExists([$oldPath, $newPath]);
    DB::commit();
    Storage::disk('public')->assertExists($newPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => $newPath]);
});

test('outer transaction rollbacks restore old image paths and clean up committed savepoint uploads', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $category = Category::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();

    DB::transaction(function () use ($category): void {
        $category->uploadedImage = UploadedFile::fake()->image('new.png');
        $category->save();
    });

    $newPath = $category->image;
    DB::rollBack();
    Storage::disk('public')->assertExists($oldPath)->assertMissing($newPath);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => $oldPath]);
});

test('rolling back a later savepoint preserves earlier uploads until the outer commit', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $category = Category::factory()->create(['image' => $oldPath]);
    DB::beginTransaction();
    DB::transaction(function () use ($category): void {
        $category->uploadedImage = UploadedFile::fake()->image('first.png');
        $category->save();
    });
    $firstPath = $category->image;

    DB::beginTransaction();
    $category->uploadedImage = UploadedFile::fake()->image('second.png');
    $category->save();
    $secondPath = $category->image;
    DB::rollBack();

    Storage::disk('public')->assertExists([$oldPath, $firstPath])->assertMissing($secondPath);
    DB::commit();
    Storage::disk('public')->assertExists($firstPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => $firstPath]);
});

test('failed category deletion preserves both the category and its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_category_delete BEFORE DELETE ON categories BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/categories/'.$category->id);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to delete the category. Please try again later.');
    $this->assertModelExists($category);
    Storage::disk('public')->assertExists($path);
    Exceptions::assertReported(QueryException::class);
});

test('successful category deletion removes its image', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/categories/'.$category->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($category);
    Storage::disk('public')->assertMissing($path);
});

test('missing category images do not prevent successful deletion', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['image' => 'categories/missing.jpg']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/categories/'.$category->id);

    $response->assertOk();
    $this->assertModelMissing($category);
    expect(Storage::disk('public')->allFiles('categories'))->toBe([]);
});

test('deletion retains the image until commit and preserves it on rollback', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $category = Category::factory()->create(['image' => $path]);
    DB::beginTransaction();

    $category->delete();

    Storage::disk('public')->assertExists($path);
    DB::rollBack();
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => $path]);
    Storage::disk('public')->assertExists($path);
});

test('category images use the configured storage disk and URL', function () {
    config(['filesystems.category_images_disk' => 'category_media']);
    Storage::fake('category_media');
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $path = $response->json('data.image');
    Storage::disk('category_media')->assertExists($path);
    expect(Storage::disk('public')->allFiles('categories'))->toBe([]);
    $response->assertJsonPath('data.image_url', Storage::disk('category_media')->url($path));
    $this->assertDatabaseHas('categories', ['slug' => 'heritage-tours', 'image' => $path]);
});

test('failed storage writes clean up partial uploads and create no category', function () {
    $disk = Storage::fake('public');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('putFileAs')->once()->andReturnUsing(
        function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
            $disk->putFileAs($directory, $file, $filename, $options);

            return false;
        },
    );
    Storage::set('public', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
    ]);
    $this->assertDatabaseCount('categories', 0);
    expect($disk->allFiles('categories'))->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('image cleanup failures are reported after a successful category deletion', function () {
    $disk = Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('Storage is unavailable.'));
    Storage::set('public', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Exceptions::fake();

    $response = $this->withToken($token)->deleteJson('/api/admin/categories/'.$category->id);

    $response->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($category);
    $disk->assertExists($path);
    Exceptions::assertReported(RuntimeException::class);
});

test('reusing an upload for another category keeps their images independent', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $firstCategory = Category::factory()->create();
    $secondCategory = Category::factory()->create();
    DB::transaction(function () use ($firstCategory, $secondCategory, $upload): void {
        $firstCategory->uploadedImage = $upload;
        $firstCategory->save();
        $secondCategory->uploadedImage = $upload;
        $secondCategory->save();
    });
    $firstPath = $firstCategory->image;
    $secondPath = $secondCategory->image;

    DB::transaction(fn (): ?bool => $firstCategory->delete());

    $this->assertModelMissing($firstCategory);
    $this->assertDatabaseHas('categories', ['id' => $secondCategory->id, 'image' => $secondPath]);
    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath)->assertExists($secondPath);
});

test('rolling back a replacement with a reused upload preserves the persisted image', function () {
    Storage::fake('public');
    $upload = UploadedFile::fake()->image('photo.jpg');
    $category = Category::factory()->create();
    DB::transaction(function () use ($category, $upload): void {
        $category->uploadedImage = $upload;
        $category->save();
    });
    $oldPath = $category->image;
    DB::beginTransaction();
    $category->uploadedImage = $upload;
    $category->save();
    $replacementPath = $category->image;

    DB::rollBack();

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => $oldPath]);
    expect($replacementPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath)->assertMissing($replacementPath);
});

test('deletion removes the persisted image instead of an unsaved image path', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $unrelatedPath = UploadedFile::fake()->image('unrelated.jpg')->store('categories', 'public');
    $category = Category::factory()->create(['image' => $oldPath]);
    $category->image = $unrelatedPath;

    DB::transaction(fn (): ?bool => $category->delete());

    $this->assertModelMissing($category);
    Storage::disk('public')->assertMissing($oldPath)->assertExists($unrelatedPath);
});
