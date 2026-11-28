<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\SowingMethod;
use App\Models\SowingMethodImage;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/** Real commits are needed; existing forward-only migrations prohibit DatabaseMigrations teardown. */
beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
});

function sowingImageUpload(string $extension = 'jpg'): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->image('method.'.$extension, 20, 20);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), 'method.'.$extension, null, null, true);
}

function sowingImageToken(): string
{
    return AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
}

function sowingInvalidImageUpload(string $name, string $content): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->createWithContent($name, $content);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), $name, null, null, true);
}

test('uploads ordered JPEG PNG and WebP images with generated paths and derived cover', function (string $extension) {
    Storage::fake('public', ['url' => 'https://media.example.test/storage']);
    $method = SowingMethod::factory()->withTranslations()->create();
    $response = $this->withToken(sowingImageToken())->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'images' => ['front' => sowingImageUpload($extension), 'back' => sowingImageUpload($extension)],
        'image_order' => ['new:back', 'new:front'],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.0.sort_order', 1)->assertJsonPath('data.images.1.sort_order', 2);
    expect($response->json('data.cover_image.id'))->toBe($response->json('data.images.0.id'));
    expect($response->getContent())->not->toContain('"image":', 'image_path', 'is_cover', 'alt_text');
    foreach ($method->images()->get() as $image) {
        expect($image->image)->toStartWith('sowing-methods/'.$method->id.'/')->not->toContain('method.'.$extension);
        Storage::disk('public')->assertExists($image->image);
        expect(Storage::disk('public')->getVisibility($image->image))->toBe('public');
    }
    $this->getJson('/api/admin/sowing-methods?per_page=1')->assertOk()->assertJsonPath('data.0.cover_image.id', $response->json('data.images.0.id'));
})->with(['jpg', 'jpeg', 'png', 'webp']);

test('reorders mixed retained and new images promotes cover and delays deletion until commit', function () {
    Storage::fake('public');
    $method = SowingMethod::factory()->withTranslations()->create();
    $first = SowingMethodImage::factory()->for($method)->create();
    $second = SowingMethodImage::factory()->for($method)->create(['sort_order' => 2]);
    Storage::disk('public')->put($first->image, 'first');
    Storage::disk('public')->put($second->image, 'second');
    $this->withToken(sowingImageToken())->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'images' => ['extra' => sowingImageUpload()],
        'image_order' => ['id:'.$second->id, 'new:extra', 'id:'.$first->id],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.cover_image.id', $second->id)
        ->assertJsonPath('data.images.2.id', $first->id);
    $this->putJson('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'image_order' => ['id:'.$first->id, 'id:'.$second->id, 'id:'.$method->images()->whereNotIn('id', [$first->id, $second->id])->sole()->id],
    ])->assertOk()->assertJsonPath('data.cover_image.id', $first->id);
    DB::beginTransaction();
    $this->postJson('/api/admin/sowing-methods/'.$method->id.'/step-3', ['remove_image_ids' => [$first->id]])
        ->assertOk()->assertJsonPath('data.cover_image.id', $second->id)->assertJsonPath('data.images.0.sort_order', 1);
    Storage::disk('public')->assertExists($first->image);
    DB::commit();
    Storage::disk('public')->assertMissing($first->image);
    Storage::disk('public')->assertExists($second->image);
});

test('preserves omitted galleries and supports an explicit empty retain list', function () {
    Storage::fake('public');
    $method = SowingMethod::factory()->withTranslations()->create();
    $image = SowingMethodImage::factory()->for($method)->create();
    Storage::disk('public')->put($image->image, 'image');
    $this->withToken(sowingImageToken())->postJson('/api/admin/sowing-methods/'.$method->id.'/step-3')->assertOk()->assertJsonCount(1, 'data.images');
    $this->postJson('/api/admin/sowing-methods/'.$method->id.'/step-3', ['retain_image_ids' => []])->assertOk()
        ->assertJsonPath('data.cover_image', null)->assertJsonCount(0, 'data.images');
    Storage::disk('public')->assertMissing($image->image);
    $this->assertDatabaseCount('sowing_method_images', 0);
});

test('rejects cross-method ownership invalid order and out-of-scope fields with 422', function (string $kind) {
    Storage::fake('public');
    $method = SowingMethod::factory()->withTranslations()->create();
    $image = SowingMethodImage::factory()->for($method)->create();
    $foreign = SowingMethodImage::factory()->create();
    $input = match ($kind) {
        'foreign retain' => ['retain_image_ids' => [$foreign->id]], 'foreign remove' => ['remove_image_ids' => [$foreign->id]],
        'foreign order' => ['image_order' => ['id:'.$foreign->id]], 'duplicate' => ['image_order' => ['id:'.$image->id, 'id:'.$image->id]],
        'missing' => ['image_order' => []], 'unknown upload' => ['image_order' => ['new:unknown']],
        'conflicting' => ['retain_image_ids' => [$image->id], 'remove_image_ids' => [$image->id]],
        'crop selection' => ['crop_ids' => [Crop::factory()->create()->id]], 'raw path' => ['image' => '/tmp/image.jpg'],
        'alt text' => ['alt_texts' => ['id:'.$image->id => 'Alt']], 'cover' => ['is_cover' => true],
    };
    $this->withToken(sowingImageToken())->postJson('/api/admin/sowing-methods/'.$method->id.'/step-3', $input)->assertUnprocessable();
    $this->assertModelExists($image);
    $this->assertModelExists($foreign);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['foreign retain', 'foreign remove', 'foreign order', 'duplicate', 'missing', 'unknown upload', 'conflicting', 'crop selection', 'raw path', 'alt text', 'cover']);

test('rejects invalid MIME oversized files executable extensions and external URLs before storage', function (string $kind) {
    Storage::fake('public');
    $method = SowingMethod::factory()->create();
    $file = match ($kind) {
        'gif' => sowingImageUpload('gif'), 'oversized' => UploadedFile::fake()->image('large.png')->size(5121),
        'disguised' => sowingInvalidImageUpload('image.jpg', 'Not an image.'),
        'executable' => UploadedFile::fake()->image('image.php'),
        'svg' => sowingInvalidImageUpload('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'URL' => 'https://example.test/image.jpg',
    };
    $this->withToken(sowingImageToken())->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'images' => ['front' => $file],
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('images.front');
    $this->assertDatabaseCount('sowing_method_images', 0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['gif', 'oversized', 'disguised', 'executable', 'svg', 'URL']);

test('enforces the combined retained and newly uploaded eight-image limit', function () {
    Storage::fake('public');
    $method = SowingMethod::factory()->create();
    SowingMethodImage::factory()->for($method)->count(8)->sequence(fn ($sequence): array => ['sort_order' => $sequence->index + 1])->create();
    $this->withToken(sowingImageToken())->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'images' => ['extra' => sowingImageUpload()],
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('images');
    $this->assertDatabaseCount('sowing_method_images', 8);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('cleans new files and restores removed metadata when database persistence fails', function () {
    Storage::fake('public');
    Exceptions::fake();
    $method = SowingMethod::factory()->create();
    $image = SowingMethodImage::factory()->for($method)->create();
    Storage::disk('public')->put($image->image, 'original');
    DB::unprepared("CREATE TRIGGER fail_sowing_image BEFORE INSERT ON sowing_method_images BEGIN SELECT RAISE(ABORT, 'forced insert failure'); END");
    $this->withToken(sowingImageToken())->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'remove_image_ids' => [$image->id], 'images' => ['new' => sowingImageUpload()],
    ], ['Accept' => 'application/json'])->assertStatus(500);
    $this->assertModelExists($image);
    $this->assertDatabaseCount('sowing_method_images', 1);
    expect(Storage::disk('public')->allFiles())->toBe([$image->image]);
    Exceptions::assertReported(QueryException::class);
});

test('cleans partially written uploads when the storage adapter returns failure', function () {
    Storage::fake('public');
    Exceptions::fake();
    $disk = Storage::disk('public');
    $method = SowingMethod::factory()->create();
    $adapter = Mockery::mock($disk);
    $adapter->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
        $disk->putFileAs($directory, $file, $filename, $options);

        return false;
    });
    Storage::set('public', $adapter);
    $this->withToken(sowingImageToken())->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'images' => ['front' => sowingImageUpload()],
    ], ['Accept' => 'application/json'])->assertStatus(500);
    $this->assertDatabaseCount('sowing_method_images', 0);
    expect($disk->allFiles())->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('rejects missing files and corrupt saved ordering during finalization', function (bool $missing) {
    Storage::fake('public');
    $method = SowingMethod::factory()->withTranslations()->create();
    $image = SowingMethodImage::factory()->for($method)->create(['sort_order' => $missing ? 1 : 7]);
    if (! $missing) {
        Storage::disk('public')->put($image->image, 'image');
    }
    $this->withToken(sowingImageToken())->postJson('/api/admin/sowing-methods/'.$method->id.'/finalize', ['is_active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('images');
    expect($method->fresh()->is_active)->toBeFalse();
})->with([true, false]);

test('deleting an unreferenced method removes gallery files only after commit', function () {
    Storage::fake('public');
    $method = SowingMethod::factory()->withTranslations()->create();
    $image = SowingMethodImage::factory()->for($method)->create();
    Storage::disk('public')->put($image->image, 'image');
    $this->withToken(sowingImageToken());
    DB::beginTransaction();
    $this->deleteJson('/api/admin/sowing-methods/'.$method->id)->assertOk();
    Storage::disk('public')->assertExists($image->image);
    DB::commit();
    Storage::disk('public')->assertMissing($image->image);
    $this->assertModelMissing($method);
    $this->assertDatabaseCount('sowing_method_images', 0);
});

test('an outer rollback preserves deleted image files and metadata', function () {
    Storage::fake('public');
    $method = SowingMethod::factory()->create();
    $image = SowingMethodImage::factory()->for($method)->create();
    Storage::disk('public')->put($image->image, 'image');
    $this->withToken(sowingImageToken());
    DB::beginTransaction();
    $this->postJson('/api/admin/sowing-methods/'.$method->id.'/step-3', ['remove_image_ids' => [$image->id]])->assertOk();
    DB::rollBack();
    $this->assertModelExists($image);
    Storage::disk('public')->assertExists($image->image);
});

test('an outer rollback cleans newly uploaded files after a successful nested save', function () {
    Storage::fake('public');
    $method = SowingMethod::factory()->create();
    $this->withToken(sowingImageToken());
    DB::beginTransaction();
    $this->post('/api/admin/sowing-methods/'.$method->id.'/step-3', [
        'images' => ['front' => sowingImageUpload()],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'data.images');
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
    DB::rollBack();
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseCount('sowing_method_images', 0);
});
