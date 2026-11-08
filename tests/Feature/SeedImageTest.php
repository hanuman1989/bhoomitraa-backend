<?php

use App\Models\AdminUser;
use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedImage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/** Gallery deletion must run after real outermost commits. */
uses(DatabaseMigrations::class);

function seedUpload(string $extension = 'jpg'): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->image('seed.'.$extension, 20, 20);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), 'seed.'.$extension, null, null, true);
}

function seedInvalidUpload(string $name, string $content): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->createWithContent($name, $content);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), $name, null, null, true);
}

test('seed gallery accepts JPEG PNG and WebP with generated public paths and first image as primary', function (string $extension) {
    Storage::fake('public', ['url' => 'https://media.example.test/storage']);
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending',
            'images' => ['front' => seedUpload($extension), 'back' => seedUpload($extension)],
            'image_order' => ['new:back', 'new:front'], 'alt_texts' => ['new:back' => 'Back view']], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonCount(2, 'data.images')->assertJsonPath('data.images.0.sort_order', 1)
        ->assertJsonPath('data.images.0.alt_text', 'Back view')->assertJsonPath('data.completed_step', 3);
    $paths = $seed->images()->pluck('image_path')->all();
    foreach ($paths as $path) {
        expect($path)->toStartWith('seeds/'.$seed->id.'/')->not->toContain('seed.'.$extension);
        Storage::disk('public')->assertExists($path);
        expect(Storage::disk('public')->getVisibility($path))->toBe('public');
    }
    expect($response->json('data.primary_image.id'))->toBe($response->json('data.images.0.id'));
    expect($response->getContent())->not->toContain('image_path', 'is_primary');
    $this->getJson('/api/admin/seeds/'.$seed->id)->assertOk()->assertJsonPath('data.images.0.id', $response->json('data.images.0.id'));
})->with(['jpg', 'png', 'webp']);

test('seed gallery reorders without uploading and deletes the first file only after commit', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $first = SeedImage::factory()->for($seed)->create(['image_path' => 'seeds/'.$seed->id.'/first.jpg', 'sort_order' => 1]);
    $second = SeedImage::factory()->for($seed)->create(['image_path' => 'seeds/'.$seed->id.'/second.jpg', 'sort_order' => 2]);
    Storage::disk('public')->put($first->image_path, 'first');
    Storage::disk('public')->put($second->image_path, 'second');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->putJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending', 'image_order' => ['id:'.$second->id, 'id:'.$first->id]])
        ->assertOk()->assertJsonPath('data.primary_image.id', $second->id)->assertJsonPath('data.images.0.sort_order', 1);
    $this->assertDatabaseCount('seed_images', 2);
    DB::beginTransaction();
    $this->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending', 'remove_image_ids' => [$second->id]])
        ->assertOk()->assertJsonPath('data.primary_image.id', $first->id)->assertJsonPath('data.images.0.sort_order', 1);
    Storage::disk('public')->assertExists($second->image_path);
    DB::commit();
    Storage::disk('public')->assertMissing($second->image_path);
    Storage::disk('public')->assertExists($first->image_path);
    $this->assertDatabaseCount('seed_images', 1);
});

test('gallery ownership ordering and alt text validation never alters existing images', function (string $kind) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $image = SeedImage::factory()->for($seed)->create();
    $foreign = SeedImage::factory()->create();
    $payload = ['verification_status' => 'pending', ...match ($kind) {
        'foreign retained' => ['retain_image_ids' => [$foreign->id]], 'foreign removed' => ['remove_image_ids' => [$foreign->id]],
        'foreign order' => ['image_order' => ['id:'.$foreign->id]], 'duplicates' => ['image_order' => ['id:'.$image->id, 'id:'.$image->id]],
        'incomplete order' => ['image_order' => []], 'unknown new file' => ['image_order' => ['new:missing']],
        'conflicting lists' => ['retain_image_ids' => [$image->id], 'remove_image_ids' => [$image->id]],
        'unknown alt text' => ['alt_texts' => ['new:missing' => 'Bad']], 'raw path' => ['image_path' => '/tmp/private.jpg'],
    }];
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/step-3', $payload)->assertUnprocessable();
    $this->assertModelExists($image);
    $this->assertModelExists($foreign);
    $this->assertDatabaseCount('seed_images', 2);
    expect($seed->fresh()->completed_step)->toBe(2);
})->with(['foreign retained', 'foreign removed', 'foreign order', 'duplicates', 'incomplete order', 'unknown new file', 'conflicting lists', 'unknown alt text', 'raw path']);

test('seed gallery rejects invalid formats and oversized files before storage', function (string $kind) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $file = match ($kind) {
        'gif' => seedUpload('gif'), 'oversized' => UploadedFile::fake()->image('large.png')->size(5121),
        'svg' => seedInvalidUpload('seed.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'disguised' => seedInvalidUpload('seed.jpg', 'This is not an image.'),
        'executable extension' => UploadedFile::fake()->image('seed.php'),
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seeds/'.$seed->id.'/step-3', [
        'verification_status' => 'pending', 'images' => ['front' => $file]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('images.front');
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseCount('seed_images', 0);
})->with(['gif', 'oversized', 'svg', 'disguised', 'executable extension']);

test('eight image limit includes retained images and rejects extra files atomically', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    SeedImage::factory()->for($seed)->count(8)->sequence(fn ($sequence): array => ['sort_order' => $sequence->index + 1])->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seeds/'.$seed->id.'/step-3', [
        'verification_status' => 'pending', 'images' => ['extra' => seedUpload()]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('images');
    $this->assertDatabaseCount('seed_images', 8);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('failed image persistence rolls back metadata and cleans new files while preserving removed files', function () {
    Storage::fake('public');
    Exceptions::fake();
    $admin = AdminUser::factory()->superUser()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $image = SeedImage::factory()->for($seed)->create(['image_path' => 'seeds/'.$seed->id.'/original.jpg']);
    Storage::disk('public')->put($image->image_path, 'original');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seeds/'.$seed->id.'/step-3', [
        'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->create()->id,
        'remove_image_ids' => [$image->id], 'images' => ['new' => seedUpload()]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');
    $this->assertModelExists($image);
    $this->assertDatabaseCount('seed_images', 1);
    expect(Storage::disk('public')->allFiles())->toBe([$image->image_path]);
    expect($seed->fresh()->completed_step)->toBe(2);
});

test('an upload storage failure cannot advance wizard progress or leave image metadata', function () {
    Storage::fake('public');
    Exceptions::fake();
    $disk = Storage::disk('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $spy = Mockery::mock($disk);
    $spy->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
        $disk->putFileAs($directory, $file, $filename, $options);

        return false;
    });
    Storage::set('public', $spy);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seeds/'.$seed->id.'/step-3', [
        'verification_status' => 'pending', 'images' => ['front' => seedUpload()]], ['Accept' => 'application/json'])->assertStatus(500);
    $this->assertDatabaseCount('seed_images', 0);
    expect($seed->fresh()->completed_step)->toBe(2);
    Exceptions::assertReported(RuntimeException::class);
    expect($disk->allFiles())->toBe([]);
});

test('finalization rejects missing files and invalid stored ordering', function (bool $missing) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 3]);
    $image = SeedImage::factory()->for($seed)->create(['image_path' => 'seeds/'.$seed->id.'/image.jpg', 'sort_order' => $missing ? 1 : 4]);
    if (! $missing) {
        Storage::disk('public')->put($image->image_path, 'image');
    }
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/finalize')
        ->assertUnprocessable()->assertJsonValidationErrors('images');
})->with(['missing file' => true, 'wrong order' => false]);

test('omitting gallery fields preserves images while explicit retain lists remove only omitted images', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $first = SeedImage::factory()->for($seed)->create(['image_path' => 'seeds/'.$seed->id.'/first.jpg']);
    $second = SeedImage::factory()->for($seed)->create(['image_path' => 'seeds/'.$seed->id.'/second.jpg', 'sort_order' => 2]);
    Storage::disk('public')->put($first->image_path, 'first');
    Storage::disk('public')->put($second->image_path, 'second');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending'])
        ->assertOk()->assertJsonCount(2, 'data.images');
    $this->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending', 'retain_image_ids' => [$second->id]])
        ->assertOk()->assertJsonPath('data.primary_image.id', $second->id)->assertJsonPath('data.images.0.sort_order', 1);
    Storage::disk('public')->assertMissing($first->image_path);
    Storage::disk('public')->assertExists($second->image_path);
});
