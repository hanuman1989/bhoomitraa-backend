<?php

use App\Models\AdminUser;
use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedLot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/** Real outermost commits exercise private file cleanup rather than deferred test transactions. */
uses(DatabaseMigrations::class);

function lotReportUpload(string $name, ?string $content = null): UploadedFile
{
    static $fixtures = [];
    $fixture = $content === null ? UploadedFile::fake()->image($name, 20, 20) : UploadedFile::fake()->createWithContent($name, $content);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), $name, null, null, true);
}

function lotPdf(): UploadedFile
{
    return lotReportUpload('original-laboratory-report.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
}

test('lot reports accept allowed document types and are only exposed through authenticated downloads', function (string $extension, string $mime) {
    $disk = Storage::fake('private');
    Storage::fake('public');
    $privateDisk = Mockery::mock($disk);
    $privateDisk->shouldReceive('putFileAs')->once()->with(Mockery::type('string'), Mockery::type(UploadedFile::class), Mockery::type('string'), ['visibility' => 'private'])
        ->andReturnUsing(fn (string $directory, UploadedFile $file, string $filename, array $options): string|false => $disk->putFileAs($directory, $file, $filename, $options));
    Storage::set('private', $privateDisk);
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->completed()->create(['is_active' => true, 'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->active()->create()->id]);
    $file = $extension === 'pdf' ? lotPdf() : lotReportUpload('original-report.'.$extension);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seed-lots', [
        'seed_id' => $seed->id, 'lot_number' => 'FILE-LOT', 'report_file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.verification_status', 'pending');
    $lot = SeedLot::findOrFail($response->json('data.id'));
    expect($lot->report_file)->toStartWith('seed-lots/'.$lot->id.'/')->not->toContain('original-report', 'laboratory');
    Storage::disk('private')->assertExists($lot->report_file);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect($response->getContent())->not->toContain($lot->report_file, 'storage/app', 'report_file');
    $response->assertJsonPath('data.report_url', route('admin.seed-lots.report', ['seedLot' => $lot->id]));
    $download = $this->get('/api/admin/seed-lots/'.$lot->id.'/report')->assertOk()->assertHeader('Content-Type', $mime)
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('seed-lot-'.$lot->id.'-report.'.pathinfo($lot->report_file, PATHINFO_EXTENSION));
    expect($download->headers->get('Cache-Control'))->toContain('private', 'no-store');
})->with(['pdf' => ['pdf', 'application/pdf'], 'jpg' => ['jpg', 'image/jpeg'], 'jpeg' => ['jpeg', 'image/jpeg'], 'png' => ['png', 'image/png']]);

test('report replacement through multipart method spoofing preserves lot ID and removes the old file after commit', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create(['report_file' => 'placeholder']);
    $old = 'seed-lots/'.$lot->id.'/old.pdf';
    $lot->report_file = $old;
    $lot->save();
    Storage::disk('private')->put($old, 'old report');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::beginTransaction();
    $response = $this->post('/api/admin/seed-lots/'.$lot->id, ['_method' => 'PATCH', 'report_file' => lotPdf()], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.id', $lot->id);
    $new = $lot->fresh()->report_file;
    Storage::disk('private')->assertExists($old);
    Storage::disk('private')->assertExists($new);
    DB::commit();
    Storage::disk('private')->assertMissing($old);
    Storage::disk('private')->assertExists($new);
    $this->assertDatabaseCount('seed_lots', 1);
    expect($response->json('data.report_url'))->not->toBeNull();
});

test('omitted reports remain unchanged and explicit null removes the file and reopens verification', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $lot = SeedLot::factory()->create(['verification_status' => 'verified', 'verified_by' => $reviewer->id, 'verified_at' => now()]);
    $path = 'seed-lots/'.$lot->id.'/report.pdf';
    $lot->report_file = $path;
    $lot->save();
    Storage::disk('private')->put($path, 'report');
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id, ['is_active' => false])
        ->assertOk()->assertJsonPath('data.verification_status', 'verified');
    expect($lot->fresh()->report_file)->toBe($path);
    Storage::disk('private')->assertExists($path);
    $this->patchJson('/api/admin/seed-lots/'.$lot->id, ['report_file' => null])->assertOk()->assertJsonPath('data.report_url', null)
        ->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.verified_by', null)->assertJsonPath('data.verified_at', null)
        ->assertJsonPath('data.is_active', false);
    Storage::disk('private')->assertMissing($path);
});

test('unsupported report types disguised contents wrong extensions and oversized files return 422 before writing', function (string $kind) {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $file = match ($kind) {
        'gif' => lotReportUpload('report.gif'), 'webp' => lotReportUpload('report.webp'),
        'svg' => lotReportUpload('report.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'disguised' => lotReportUpload('report.pdf', 'This is not a PDF.'),
        'wrong extension' => lotReportUpload('report.php', "%PDF-1.4\n%%EOF"),
        'oversized' => UploadedFile::fake()->create('report.pdf', 5121, 'application/pdf'),
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seed-lots/'.$lot->id, [
        '_method' => 'PATCH', 'report_file' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('report_file');
    expect(Storage::disk('private')->allFiles())->toBe([]);
    expect($lot->fresh()->report_file)->toBeNull();
})->with(['gif', 'webp', 'svg', 'disguised', 'wrong extension', 'oversized']);

test('failed storage writes clean partially written reports and roll back newly created lots', function () {
    $disk = Storage::fake('private');
    Exceptions::fake();
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('putFileAs')->once()->andReturnUsing(function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk): bool {
        $disk->putFileAs($directory, $file, $filename, $options);

        return false;
    });
    Storage::set('private', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->completed()->create(['is_active' => true, 'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->active()->create()->id]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seed-lots', [
        'seed_id' => $seed->id, 'lot_number' => 'FAILED', 'report_file' => lotPdf()], ['Accept' => 'application/json'])
        ->assertInternalServerError()->assertJsonPath('message', 'An unexpected error occurred.');
    $this->assertDatabaseCount('seed_lots', 0);
    expect($disk->allFiles())->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('database failures after report upload preserve the old report and clean the replacement', function () {
    Storage::fake('private');
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $old = 'seed-lots/'.$lot->id.'/old.pdf';
    $lot->report_file = $old;
    $lot->save();
    Storage::disk('private')->put($old, 'old report');
    DB::unprepared("CREATE TRIGGER fail_lot_report_update BEFORE UPDATE OF report_file ON seed_lots BEGIN SELECT RAISE(ABORT, 'Simulated report database failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->post('/api/admin/seed-lots/'.$lot->id, [
            '_method' => 'PATCH', 'report_file' => lotPdf()], ['Accept' => 'application/json'])->assertInternalServerError()
            ->assertJsonPath('message', 'Unable to update the seed lot. Please try again later.');
        expect($lot->fresh()->report_file)->toBe($old);
        expect(Storage::disk('private')->allFiles())->toBe([$old]);
    } finally {
        DB::unprepared('DROP TRIGGER fail_lot_report_update');
    }
});

test('rolled back removal preserves its database pointer and file', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $path = 'seed-lots/'.$lot->id.'/report.pdf';
    $lot->report_file = $path;
    $lot->save();
    Storage::disk('private')->put($path, 'report');
    DB::beginTransaction();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id, ['report_file' => null])->assertOk();
    Storage::disk('private')->assertExists($path);
    DB::rollBack();
    expect($lot->fresh()->report_file)->toBe($path);
    Storage::disk('private')->assertExists($path);
});

test('deleting an inactive unreferenced lot removes its report only after the outer transaction commits', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create(['is_active' => false]);
    $path = 'seed-lots/'.$lot->id.'/report.pdf';
    $lot->report_file = $path;
    $lot->save();
    Storage::disk('private')->put($path, 'report');
    DB::beginTransaction();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seed-lots/'.$lot->id)->assertOk();
    Storage::disk('private')->assertExists($path);
    DB::commit();
    Storage::disk('private')->assertMissing($path);
    $this->assertModelMissing($lot);
});

test('missing or invalid report pointers return a safe 404', function (string $kind) {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $lot->report_file = match ($kind) {
        'absent' => null, 'missing' => 'seed-lots/'.$lot->id.'/missing.pdf', 'traversal' => 'seed-lots/'.$lot->id.'/../../secret.pdf'
    };
    $lot->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-lots/'.$lot->id.'/report')->assertNotFound()
        ->assertJsonPath('status', false)->assertJsonPath('data', null);
})->with(['absent', 'missing', 'traversal']);
