<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropCategory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Physical cleanup must be exercised through real outermost commits. */
uses(DatabaseMigrations::class);

function cropImageFile(string $extension = 'jpg'): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->image('crop.'.$extension, 20, 20);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), 'crop.'.$extension, null, null, true);
}

function cropInvalidUpload(string $name, string $content): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->createWithContent($name, $content);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), $name, null, null, true);
}

test('crop images accept real JPEG PNG and WebP files with generated paths and public URLs', function (string $extension) {
    config(['filesystems.disks.public.url' => 'https://media.example.test/storage']);
    Storage::fake('public', ['url' => 'https://media.example.test/storage']);
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, ['_method' => 'PATCH', 'image' => cropImageFile($extension)], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonMissingPath('data.image')->assertJsonPath('pagination', null);
    $path = $crop->fresh()->image;
    expect($path)->toStartWith('crops/'.$crop->id.'/')->not->toContain('crop.'.$extension);
    expect($path)->not->toMatch('/^[A-Za-z]:|^\//');
    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->getVisibility($path))->toBe('public');
    $response->assertJsonPath('data.image_url', 'https://media.example.test/storage/'.$path);
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => $path]);
})->with(['jpg', 'png', 'webp']);

test('invalid crop images return 422 without storing files', function (string $kind) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $file = match ($kind) {
        'svg' => cropInvalidUpload('crop.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'disguised' => cropInvalidUpload('crop.jpg', 'This is text, not an image.'),
        'executable' => cropInvalidUpload('crop.php', '<?php echo "unsafe";'),
        'gif' => cropImageFile('gif'),
        'oversized' => UploadedFile::fake()->image('large.png')->size(5121),
    };

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, ['_method' => 'PATCH', 'image' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('image');

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => null]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['svg', 'disguised', 'executable', 'gif', 'oversized']);

test('omitting an image on update preserves the stored image', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put('crops/old.jpg', 'old');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, ['scientific_name' => 'Updated scientific name'])->assertOk();

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => 'crops/old.jpg', 'scientific_name' => 'Updated scientific name']);
    Storage::disk('public')->assertExists('crops/old.jpg');
});

test('successful image replacement removes the old file after commit and retains the new file', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, ['_method' => 'PATCH', 'image' => cropImageFile('png')], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('public')->assertMissing('crops/old.jpg')->assertExists($crop->fresh()->image);
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

test('replacing an image waits until the outermost database commit', function () {
    Storage::fake('public');
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put('crops/old.jpg', 'old');
    Storage::disk('public')->put('crops/new.jpg', 'new');
    DB::beginTransaction();
    DB::transaction(function () use ($crop): void {
        $crop->image = 'crops/new.jpg';
        $crop->save();
    });
    Storage::disk('public')->assertExists(['crops/old.jpg', 'crops/new.jpg']);

    DB::commit();

    Storage::disk('public')->assertMissing('crops/old.jpg')->assertExists('crops/new.jpg');
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => 'crops/new.jpg']);
});

test('rolling back image replacement preserves the persisted old image and discards cleanup callbacks', function () {
    Storage::fake('public');
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put('crops/old.jpg', 'old');
    DB::beginTransaction();
    $crop->image = 'crops/replacement.jpg';
    $crop->save();

    DB::rollBack();

    DB::transaction(function () use ($crop): void {
        $restored = $crop->fresh();
        $restored->scientific_name = 'Unrelated subsequent update';
        $restored->save();
    });
    Storage::disk('public')->assertExists('crops/old.jpg');
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => 'crops/old.jpg']);
});

test('failed image persistence cleans newly uploaded files and preserves the old image', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');
    Crop::updating(function (Crop $crop): void {
        if ($crop->isDirty('image')) {
            throw new RuntimeException('Simulated persistence failure.');
        }
    });
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, ['_method' => 'PATCH', 'image' => cropImageFile()], ['Accept' => 'application/json'])
        ->assertInternalServerError()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
        ]);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => 'crops/old.jpg']);
    Storage::disk('public')->assertExists('crops/old.jpg');
    expect(Storage::disk('public')->allFiles())->toBe(['crops/old.jpg']);
    Exceptions::assertReported(RuntimeException::class);
});

test('failed storage writes remove partially written crop images without changing the database', function () {
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
    $crop = Crop::factory()->create();
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, ['_method' => 'PATCH', 'image' => cropImageFile()], ['Accept' => 'application/json'])->assertInternalServerError();

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => null]);
    expect($disk->allFiles())->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('removing a crop image clears its database path and file', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, ['image' => null])->assertOk()->assertJsonPath('data.image_url', null);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => null]);
    Storage::disk('public')->assertMissing('crops/old.jpg');
});

test('removing an already missing crop image is safe', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create(['image' => 'crops/missing.jpg']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, ['image' => null])->assertOk()->assertJsonPath('data.image_url', null);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => null]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('rolling back image removal retains its file and database path', function () {
    Storage::fake('public');
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');
    DB::beginTransaction();
    $crop->image = null;
    $crop->save();

    DB::rollBack();

    Storage::disk('public')->assertExists('crops/old.jpg');
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => 'crops/old.jpg']);
});

test('successful crop deletion removes the image and translations', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crops/'.$crop->id)->assertOk();

    $this->assertModelMissing($crop);
    $this->assertDatabaseCount('crop_translations', 0);
    Storage::disk('public')->assertMissing('crops/old.jpg');
});

test('crop deletion rollback preserves its image and database record', function () {
    Storage::fake('public');
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');
    DB::beginTransaction();

    $crop->delete();

    Storage::disk('public')->assertExists('crops/old.jpg');
    DB::rollBack();
    $this->assertModelExists($crop);
    Storage::disk('public')->assertExists('crops/old.jpg');
});

test('referenced crops retain their images when deletion is refused', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put($crop->image, 'old');
    Schema::create('retained_crop_images', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('crop_id')->constrained('crops')->restrictOnDelete();
    });
    try {
        DB::table('retained_crop_images')->insert(['crop_id' => $crop->id]);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/crops/'.$crop->id)->assertConflict();

        $this->assertModelExists($crop);
        Storage::disk('public')->assertExists('crops/old.jpg');
    } finally {
        Schema::dropIfExists('retained_crop_images');
    }
});

test('images still referenced by another crop are retained until their final owner is deleted', function () {
    Storage::fake('public');
    $first = Crop::factory()->create(['image' => 'crops/shared.jpg']);
    $second = Crop::factory()->create(['image' => 'crops/shared.jpg']);
    Storage::disk('public')->put('crops/shared.jpg', 'shared');

    DB::transaction(fn (): ?bool => $first->delete());

    Storage::disk('public')->assertExists('crops/shared.jpg');
    $this->assertModelExists($second);
    DB::transaction(fn (): ?bool => $second->delete());
    Storage::disk('public')->assertMissing('crops/shared.jpg');
});

test('crop deletion uses its persisted image rather than an unsaved path', function () {
    Storage::fake('public');
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    Storage::disk('public')->put('crops/old.jpg', 'old');
    Storage::disk('public')->put('crops/unrelated.jpg', 'unrelated');
    $crop->image = 'crops/unrelated.jpg';

    DB::transaction(fn (): ?bool => $crop->delete());

    Storage::disk('public')->assertMissing('crops/old.jpg')->assertExists('crops/unrelated.jpg');
});

test('cleanup failures are logged safely without undoing committed crop changes', function () {
    $disk = Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create(['image' => 'crops/old.jpg']);
    $disk->put('crops/old.jpg', 'old');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('delete')->once()->with('crops/old.jpg')->andThrow(new RuntimeException('Unavailable private server path.'));
    Storage::set('public', $faultyDisk);
    Log::shouldReceive('warning')->once()->with('Crop image cleanup failed.', ['crop_id' => $crop->id, 'exception_type' => RuntimeException::class]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, ['image' => null])->assertOk()->assertJsonPath('data.image_url', null);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'image' => null]);
    $disk->assertExists('crops/old.jpg');
});

test('configured crop image disk is used for uploads URLs and cleanup', function () {
    config(['filesystems.crop_images_disk' => 'crop_media', 'filesystems.disks.crop_media.url' => 'https://cdn.example.test']);
    Storage::fake('crop_media', ['url' => 'https://cdn.example.test']);
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $response = $this->withToken($token)
        ->post('/api/admin/crops/'.$crop->id, ['_method' => 'PATCH', 'image' => cropImageFile()], ['Accept' => 'application/json']);
    $path = $crop->fresh()->image;

    $response->assertOk()->assertJsonPath('data.image_url', 'https://cdn.example.test/'.$path);
    Storage::disk('crop_media')->assertExists($path);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id, ['image' => null])->assertOk();
    Storage::disk('crop_media')->assertMissing($path);
});

test('crop creation saves metadata translations and a main image together', function (string $extension) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops', [
            'category_id' => $category->id, 'slug' => 'wheat', 'scientific_name' => 'Triticum aestivum',
            'crop_code' => 'WHEAT', 'image' => cropImageFile($extension),
            'translations' => ['en' => ['name' => 'Wheat'], 'hi' => ['name' => 'गेहूं']],
        ], ['Accept' => 'application/json']);

    $response->assertCreated()->assertJsonPath('data.name', 'Wheat')->assertJsonPath('data.translations.hi.name', 'गेहूं')
        ->assertJsonPath('data.scientific_name', 'Triticum aestivum')->assertJsonMissingPath('data.image');
    $crop = Crop::query()->sole();
    Storage::disk('public')->assertExists($crop->image);
    expect($crop->image)->toStartWith('crops/'.$crop->id.'/');
    expect($response->json('data.image_url'))->toEndWith('/'.$crop->image);
    $this->assertDatabaseCount('crop_translations', 2);
})->with(['jpg', 'png', 'webp']);

test('one multipart update replaces image and edits metadata and translations', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create(['image' => 'crops/old.jpg', 'slug' => 'wheat']);
    Storage::disk('public')->put('crops/old.jpg', 'old');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, [
            '_method' => 'PATCH', 'image' => cropImageFile('png'), 'scientific_name' => 'Updated scientific name',
            'translations' => ['hi' => ['name' => 'नया गेहूं']],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.translations.hi.name', 'नया गेहूं')
        ->assertJsonPath('data.translations.en.name', 'Wheat')->assertJsonPath('data.slug', 'wheat')->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'scientific_name' => 'Updated scientific name']);
    Storage::disk('public')->assertMissing('crops/old.jpg')->assertExists($crop->fresh()->image);
});

test('failed creation after storing its image rolls back metadata translations and the file', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    Crop::updating(function (Crop $crop): void {
        if ($crop->isDirty('image')) {
            throw new RuntimeException('Simulated image persistence failure.');
        }
    });
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops', [
            'category_id' => $category->id, 'image' => cropImageFile(),
            'translations' => ['en' => ['name' => 'Wheat'], 'hi' => ['name' => 'गेहूं']],
        ], ['Accept' => 'application/json'])->assertInternalServerError();

    $this->assertDatabaseCount('crops', 0);
    $this->assertDatabaseCount('crop_translations', 0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('invalid image prevents submitted metadata and translations from being persisted', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['image' => 'crops/old.jpg', 'scientific_name' => 'Original name']);
    Storage::disk('public')->put('crops/old.jpg', 'old');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/crops/'.$crop->id, [
            '_method' => 'PATCH', 'scientific_name' => 'Changed name',
            'translations' => ['hi' => ['name' => 'नया नाम']],
            'image' => cropInvalidUpload('fake.jpg', 'Not an image'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'scientific_name' => 'Original name', 'image' => 'crops/old.jpg']);
    $this->assertDatabaseHas('crop_translations', ['crop_id' => $crop->id, 'locale' => 'hi', 'name' => 'गेहूं']);
    Storage::disk('public')->assertExists('crops/old.jpg');
});

test('unparsed multipart crop updates return 422 with method override instructions', function (string $method) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['scientific_name' => 'Original name']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $body = "--crop-boundary\r\nContent-Disposition: form-data; name=\"scientific_name\"\r\n\r\nChanged name\r\n--crop-boundary--\r\n";

    $this->call($method, '/api/admin/crops/'.$crop->id, [], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=crop-boundary',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], $body)->assertUnprocessable()->assertJsonValidationErrors([
        '_method' => 'Send form-data updates using POST with _method set to PUT or PATCH, or send a JSON body with PUT or PATCH.',
    ])->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'scientific_name' => 'Original name']);
})->with(['PUT', 'PATCH']);
