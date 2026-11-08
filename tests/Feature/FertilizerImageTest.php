<?php

use App\Models\AdminUser;
use App\Models\Fertilizer;
use App\Models\FertilizerImage;
use App\Models\KnowledgeSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/** Gallery deletion must run after real outermost commits. */
uses(DatabaseMigrations::class);

function fertilizerUpload(string $extension = 'jpg'): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->image('fertilizer.'.$extension, 20, 20);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), 'fertilizer.'.$extension, null, null, true);
}

function fertilizerInvalidUpload(string $name, string $content): UploadedFile
{
    static $fixtures = [];
    $fixture = UploadedFile::fake()->createWithContent($name, $content);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), $name, null, null, true);
}

test('fertilizer gallery accepts JPEG PNG and WebP with generated public paths and first image as primary', function (string $extension) {
    Storage::fake('public', ['url' => 'https://media.example.test/storage']);
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending',
            'images' => ['front' => fertilizerUpload($extension), 'back' => fertilizerUpload($extension)],
            'image_order' => ['new:back', 'new:front'], 'alt_texts' => ['new:back' => 'Back view']], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonCount(2, 'data.images')->assertJsonPath('data.images.0.sort_order', 1)
        ->assertJsonPath('data.images.0.alt_text', 'Back view')->assertJsonPath('data.completed_step', 4);
    $paths = $fertilizer->images()->pluck('image_path')->all();
    foreach ($paths as $path) {
        expect($path)->toStartWith('fertilizers/'.$fertilizer->id.'/')->not->toContain('fertilizer.'.$extension);
        Storage::disk('public')->assertExists($path);
        expect(Storage::disk('public')->getVisibility($path))->toBe('public');
    }
    expect($response->json('data.primary_image.id'))->toBe($response->json('data.images.0.id'));
    expect($response->getContent())->not->toContain('image_path', 'is_primary');
    $this->getJson('/api/admin/fertilizers/'.$fertilizer->id)->assertOk()->assertJsonPath('data.images.0.id', $response->json('data.images.0.id'));
})->with(['jpg', 'png', 'webp']);

test('fertilizer gallery reorders without uploading and deletes the first file only after commit', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $first = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/first.jpg', 'sort_order' => 1]);
    $second = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/second.jpg', 'sort_order' => 2]);
    Storage::disk('public')->put($first->image_path, 'first');
    Storage::disk('public')->put($second->image_path, 'second');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending', 'image_order' => ['id:'.$second->id, 'id:'.$first->id]])
        ->assertOk()->assertJsonPath('data.primary_image.id', $second->id)->assertJsonPath('data.images.0.sort_order', 1);
    $this->assertDatabaseCount('fertilizer_images', 2);
    DB::beginTransaction();
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending', 'remove_image_ids' => [$second->id]])
        ->assertOk()->assertJsonPath('data.primary_image.id', $first->id)->assertJsonPath('data.images.0.sort_order', 1);
    Storage::disk('public')->assertExists($second->image_path);
    DB::commit();
    Storage::disk('public')->assertMissing($second->image_path);
    Storage::disk('public')->assertExists($first->image_path);
    $this->assertDatabaseCount('fertilizer_images', 1);
});

test('gallery ownership ordering and alt text validation never alters existing images', function (string $kind) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $image = FertilizerImage::factory()->for($fertilizer)->create();
    $foreign = FertilizerImage::factory()->create();
    $payload = ['verification_status' => 'pending', ...match ($kind) {
        'foreign retained' => ['retain_image_ids' => [$foreign->id]], 'foreign removed' => ['remove_image_ids' => [$foreign->id]],
        'foreign order' => ['image_order' => ['id:'.$foreign->id]], 'duplicates' => ['image_order' => ['id:'.$image->id, 'id:'.$image->id]],
        'incomplete order' => ['image_order' => []], 'unknown new file' => ['image_order' => ['new:missing']],
        'conflicting lists' => ['retain_image_ids' => [$image->id], 'remove_image_ids' => [$image->id]],
        'unknown alt text' => ['alt_texts' => ['new:missing' => 'Bad']], 'raw path' => ['image_path' => '/tmp/private.jpg'],
    }];
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', $payload)->assertUnprocessable();
    $this->assertModelExists($image);
    $this->assertModelExists($foreign);
    $this->assertDatabaseCount('fertilizer_images', 2);
    expect($fertilizer->fresh()->completed_step)->toBe(3);
})->with(['foreign retained', 'foreign removed', 'foreign order', 'duplicates', 'incomplete order', 'unknown new file', 'conflicting lists', 'unknown alt text', 'raw path']);

test('fertilizer gallery rejects invalid formats and oversized files before storage', function (string $kind) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $file = match ($kind) {
        'gif' => fertilizerUpload('gif'), 'oversized' => UploadedFile::fake()->image('large.png')->size(5121),
        'svg' => fertilizerInvalidUpload('fertilizer.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'disguised' => fertilizerInvalidUpload('fertilizer.jpg', 'This is not an image.'),
        'executable extension' => UploadedFile::fake()->image('fertilizer.php'),
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', [
        'verification_status' => 'pending', 'images' => ['front' => $file]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('images.front');
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseCount('fertilizer_images', 0);
})->with(['gif', 'oversized', 'svg', 'disguised', 'executable extension']);

test('eight image limit includes retained images and rejects extra files atomically', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    FertilizerImage::factory()->for($fertilizer)->count(8)->sequence(fn ($sequence): array => ['sort_order' => $sequence->index + 1])->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', [
        'verification_status' => 'pending', 'images' => ['extra' => fertilizerUpload()]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('images');
    $this->assertDatabaseCount('fertilizer_images', 8);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('failed image persistence rolls back metadata and cleans new files while preserving removed files', function () {
    Storage::fake('public');
    Exceptions::fake();
    $admin = AdminUser::factory()->superUser()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $image = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/original.jpg']);
    Storage::disk('public')->put($image->image_path, 'original');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', [
        'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->create()->id,
        'remove_image_ids' => [$image->id], 'images' => ['new' => fertilizerUpload()]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');
    $this->assertModelExists($image);
    $this->assertDatabaseCount('fertilizer_images', 1);
    expect(Storage::disk('public')->allFiles())->toBe([$image->image_path]);
    expect($fertilizer->fresh()->completed_step)->toBe(3);
});

test('an upload storage failure cannot advance wizard progress or leave image metadata', function () {
    Storage::fake('public');
    Exceptions::fake();
    $disk = Storage::disk('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $spy = Mockery::mock($disk);
    $spy->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
        $disk->putFileAs($directory, $file, $filename, $options);

        return false;
    });
    Storage::set('public', $spy);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', [
        'verification_status' => 'pending', 'images' => ['front' => fertilizerUpload()]], ['Accept' => 'application/json'])->assertStatus(500);
    $this->assertDatabaseCount('fertilizer_images', 0);
    expect($fertilizer->fresh()->completed_step)->toBe(3);
    Exceptions::assertReported(RuntimeException::class);
    expect($disk->allFiles())->toBe([]);
});

test('finalization rejects missing files and invalid stored ordering', function (bool $missing) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 4]);
    $image = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/image.jpg', 'sort_order' => $missing ? 1 : 4]);
    if (! $missing) {
        Storage::disk('public')->put($image->image_path, 'image');
    }
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/finalize')
        ->assertUnprocessable()->assertJsonValidationErrors('images');
})->with(['missing file' => true, 'wrong order' => false]);

test('omitting gallery fields preserves images while explicit retain lists remove only omitted images', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $first = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/first.jpg']);
    $second = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/second.jpg', 'sort_order' => 2]);
    Storage::disk('public')->put($first->image_path, 'first');
    Storage::disk('public')->put($second->image_path, 'second');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending'])
        ->assertOk()->assertJsonCount(2, 'data.images');
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending', 'retain_image_ids' => [$second->id]])
        ->assertOk()->assertJsonPath('data.primary_image.id', $second->id)->assertJsonPath('data.images.0.sort_order', 1);
    Storage::disk('public')->assertMissing($first->image_path);
    Storage::disk('public')->assertExists($second->image_path);
});

test('safe fertilizer deletion removes owned rows and public files after commit', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create();
    $fertilizer->nutrients()->create(['nutrient_code' => 'N', 'content_value' => '46', 'content_unit_code' => 'percent_ww']);
    $image = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/owned.jpg']);
    Storage::disk('public')->put($image->image_path, 'owned');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/fertilizers/'.$fertilizer->id)->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($fertilizer);
    $this->assertDatabaseCount('fertilizer_images', 0);
    $this->assertDatabaseCount('fertilizer_nutrients', 0);
    $this->assertDatabaseCount('fertilizer_translations', 0);
    Storage::disk('public')->assertMissing($image->image_path);
});

test('database image failure rolls back removals and removes newly stored files', function () {
    Storage::fake('public');
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 3]);
    $image = FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'fertilizers/'.$fertilizer->id.'/old.jpg']);
    Storage::disk('public')->put($image->image_path, 'old');
    DB::unprepared("CREATE TRIGGER fail_fertilizer_image BEFORE INSERT ON fertilizer_images BEGIN SELECT RAISE(ABORT, 'Simulated image failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/fertilizers/'.$fertilizer->id.'/step-4',
            ['verification_status' => 'pending', 'remove_image_ids' => [$image->id], 'images' => ['front' => fertilizerUpload()]], ['Accept' => 'application/json'])
            ->assertInternalServerError();
        $this->assertModelExists($image);
        expect(Storage::disk('public')->allFiles())->toBe([$image->image_path]);
        expect($fertilizer->fresh()->completed_step)->toBe(3);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_fertilizer_image');
    }
});

test('deleting corrupted image metadata never deletes an unrelated public file', function () {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create();
    FertilizerImage::factory()->for($fertilizer)->create(['image_path' => 'unrelated/keep.jpg']);
    Storage::disk('public')->put('unrelated/keep.jpg', 'keep');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/fertilizers/'.$fertilizer->id)->assertOk();
    Storage::disk('public')->assertExists('unrelated/keep.jpg');
});
