<?php

use App\Models\AdminUser;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceAttachment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Real outermost commits are required to test after-commit file cleanup. */
uses(DatabaseMigrations::class);

function knowledgeUpload(string $name, string $content): UploadedFile
{
    /** Keep tmpfile handles alive while real UploadedFile instances read their content. */
    static $fixtures = [];
    $fixture = UploadedFile::fake()->createWithContent($name, $content);
    $fixtures[] = $fixture;

    return new UploadedFile($fixture->getPathname(), $name, null, null, true);
}

function knowledgeDocument(string $name = 'report.pdf'): UploadedFile
{
    if (str_ends_with($name, '.pdf')) {
        return knowledgeUpload($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    $path = tempnam(sys_get_temp_dir(), 'knowledge-document-');
    try {
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);
        $isWord = str_ends_with($name, '.docx');
        $part = $isWord ? 'word/document.xml' : 'xl/workbook.xml';
        $mime = $isWord ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$part.'" ContentType="'.$mime.'"/></Types>');
        $archive->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.$part.'"/></Relationships>');
        $archive->addFromString($part, $isWord
            ? '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>'
            : '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>');
        $archive->close();

        return knowledgeUpload($name, file_get_contents($path));
    } finally {
        unlink($path);
    }
}

test('admins upload real documents privately with server derived metadata', function (string $name, string $mime) {
    Storage::fake('private');
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create();
    $document = knowledgeDocument($name);
    $this->travelTo('2026-11-01 08:45:00');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/knowledge-sources/'.$source->id.'/attachments', [
            'attachments' => [$document], 'file_path' => 'public/untrusted', 'file_size' => 1, 'file_type' => 'text/html',
        ], ['Accept' => 'application/json']);

    $response->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('data.0.file_name', $name)
        ->assertJsonPath('data.0.file_type', $mime)->assertJsonPath('data.0.file_size', $document->getSize())
        ->assertJsonPath('data.0.added_at', '1 Nov 2026 08:45 AM')->assertJsonMissingPath('data.0.file_path');
    $attachment = $source->attachments()->sole();
    Storage::disk('private')->assertExists($attachment->file_path);
    expect($attachment->file_path)->toStartWith('knowledge-sources/'.$source->id.'/')->not->toContain($name);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'pending', 'is_active' => false, 'verified_by' => null, 'updated_by' => $admin->id]);
    $this->assertDatabaseHas('knowledge_source_attachments', ['id' => $attachment->id, 'file_name' => $name, 'file_type' => $mime, 'file_size' => $document->getSize()]);
})->with([
    'PDF' => ['report.pdf', 'application/pdf'],
    'Word' => ['report.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'Excel' => ['report.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);

test('multiple uploaded documents own distinct generated files', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/knowledge-sources/'.$source->id.'/attachments', [
            'attachments' => [knowledgeDocument(), knowledgeDocument()],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'data');

    $this->assertDatabaseCount('knowledge_source_attachments', 2);
    expect(Storage::disk('private')->allFiles())->toHaveCount(2);
    expect($source->attachments()->get()->pluck('file_path')->unique())->toHaveCount(2);
});

test('invalid attachments return 422 before writing files', function (string $kind, string $field) {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();
    $attachments = match ($kind) {
        'disallowed' => [knowledgeUpload('script.php', '<?php echo "unsafe";')],
        'disguised' => [knowledgeUpload('report.pdf', 'This is plain text, not a PDF.')],
        'wrong extension' => [knowledgeUpload('report.php', "%PDF-1.4\n%%EOF")],
        'oversized' => [UploadedFile::fake()->create('report.pdf', 10241, 'application/pdf')],
        'too many' => array_map(fn (): UploadedFile => knowledgeDocument(), range(1, 11)),
        'long name' => [knowledgeDocument(str_repeat('a', 252).'.pdf')],
        'empty' => [],
    };

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/knowledge-sources/'.$source->id.'/attachments', ['attachments' => $attachments], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('knowledge_source_attachments', 0);
    expect(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'disallowed' => ['disallowed', 'attachments.0'], 'disguised PDF' => ['disguised', 'attachments.0'],
    'extension mismatch' => ['wrong extension', 'attachments.0'], 'oversized' => ['oversized', 'attachments.0'],
    'too many' => ['too many', 'attachments'], 'filename too long' => ['long name', 'attachments.0'],
    'empty' => ['empty', 'attachments'],
]);

test('authorized downloads preserve the original filename and never expose storage paths', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->create(['file_name' => 'Soil guide.pdf']);
    Storage::disk('private')->put($attachment->file_path, '%PDF-1.4 private content');
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->get('/api/admin/knowledge-sources/'.$attachment->knowledge_source_id.'/attachments/'.$attachment->id);

    $response->assertOk()->assertDownload('Soil guide.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('%PDF-1.4 private content');
    $this->withToken($token)->getJson('/api/admin/knowledge-sources/'.$attachment->knowledge_source_id)
        ->assertOk()->assertJsonCount(1, 'data.attachments')->assertJsonMissingPath('data.attachments.0.file_path');
});

test('attachments cannot be downloaded or removed through an unrelated source', function (string $method) {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $otherSource = KnowledgeSource::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->create();
    Storage::disk('private')->put($attachment->file_path, 'private');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/knowledge-sources/'.$otherSource->id.'/attachments/'.$attachment->id)->assertNotFound();

    $this->assertModelExists($attachment);
    Storage::disk('private')->assertExists($attachment->file_path);
})->with(['download' => ['GET'], 'delete' => ['DELETE']]);

test('missing physical files return the standard 404', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/knowledge-sources/'.$attachment->knowledge_source_id.'/attachments/'.$attachment->id)
        ->assertNotFound()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
        ]);
});

test('a physically stored attachment is sufficient for verification', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->create(['source_url' => null]);
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Storage::disk('private')->put($attachment->file_path, '%PDF-1.4');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', ['action' => 'verify'])->assertOk()
        ->assertJsonPath('data.verification_status', 'verified');

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'verified']);
    Storage::disk('private')->assertExists($attachment->file_path);
});

test('deleting attachments cleans their files after commit and resets verification', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create();
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Storage::disk('private')->put($attachment->file_path, '%PDF-1.4');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$source->id.'/attachments/'.$attachment->id)
        ->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($attachment);
    Storage::disk('private')->assertMissing($attachment->file_path);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'pending', 'is_active' => false]);
});

test('the final evidence file of a verified source cannot be deleted', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->verified()->create(['source_url' => null]);
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Storage::disk('private')->put($attachment->file_path, '%PDF-1.4');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$source->id.'/attachments/'.$attachment->id)->assertConflict();

    $this->assertModelExists($attachment);
    Storage::disk('private')->assertExists($attachment->file_path);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'verified']);
});

test('attachment deletion rollback preserves the database record and file', function () {
    Storage::fake('private');
    $attachment = KnowledgeSourceAttachment::factory()->create();
    Storage::disk('private')->put($attachment->file_path, 'private');
    DB::beginTransaction();

    $attachment->delete();

    Storage::disk('private')->assertExists($attachment->file_path);
    DB::rollBack();
    $this->assertModelExists($attachment);
    Storage::disk('private')->assertExists($attachment->file_path);
});

test('nested attachment deletion waits for the outermost commit', function () {
    Storage::fake('private');
    $attachment = KnowledgeSourceAttachment::factory()->create();
    Storage::disk('private')->put($attachment->file_path, 'private');
    DB::beginTransaction();
    DB::transaction(fn (): ?bool => $attachment->delete());
    Storage::disk('private')->assertExists($attachment->file_path);

    DB::commit();

    $this->assertModelMissing($attachment);
    Storage::disk('private')->assertMissing($attachment->file_path);
});

test('permitted parent deletion cleans every attachment through Eloquent', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();
    $attachments = KnowledgeSourceAttachment::factory()->count(2)->for($source)->create();
    foreach ($attachments as $attachment) {
        Storage::disk('private')->put($attachment->file_path, 'private');
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$source->id)->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($source);
    $this->assertDatabaseCount('knowledge_source_attachments', 0);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('active source deletion is prohibited and preserves evidence', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create();
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Storage::disk('private')->put($attachment->file_path, 'private');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$source->id)->assertConflict();

    $this->assertModelExists($source);
    $this->assertModelExists($attachment);
    Storage::disk('private')->assertExists($attachment->file_path);
});

test('historical foreign key references prohibit source deletion and preserve evidence', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Storage::disk('private')->put($attachment->file_path, 'private');
    Schema::create('retained_source_records', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('knowledge_source_id')->constrained('knowledge_sources')->restrictOnDelete();
    });
    try {
        DB::table('retained_source_records')->insert(['knowledge_source_id' => $source->id]);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->deleteJson('/api/admin/knowledge-sources/'.$source->id)->assertConflict();

        $this->assertModelExists($source);
        $this->assertModelExists($attachment);
        Storage::disk('private')->assertExists($attachment->file_path);
    } finally {
        Schema::dropIfExists('retained_source_records');
    }
});

test('failed attachment persistence removes all files from the attempted upload', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create();
    KnowledgeSourceAttachment::creating(function (KnowledgeSourceAttachment $attachment): void {
        throw new RuntimeException('Simulated database persistence failure.');
    });
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/knowledge-sources/'.$source->id.'/attachments', ['attachments' => [knowledgeDocument()]], ['Accept' => 'application/json'])
        ->assertInternalServerError()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
        ]);

    $this->assertDatabaseCount('knowledge_source_attachments', 0);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'verified', 'is_active' => true]);
    expect(Storage::disk('private')->allFiles())->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('a failed second upload cleans earlier and partially written files', function () {
    $disk = Storage::fake('private');
    $faultyDisk = Mockery::mock($disk);
    $calls = 0;
    $faultyDisk->shouldReceive('putFileAs')->twice()->andReturnUsing(
        function (string $directory, UploadedFile $file, string $filename, array $options) use ($disk, &$calls): string|false {
            $path = $disk->putFileAs($directory, $file, $filename, $options);

            return ++$calls === 2 ? false : $path;
        },
    );
    Storage::set('private', $faultyDisk);
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->post('/api/admin/knowledge-sources/'.$source->id.'/attachments', ['attachments' => [knowledgeDocument(), knowledgeDocument()]], ['Accept' => 'application/json'])
        ->assertInternalServerError();

    $this->assertDatabaseCount('knowledge_source_attachments', 0);
    expect($disk->allFiles())->toBe([]);
    Exceptions::assertReported(RuntimeException::class);
});

test('filesystem deletion failures are logged without undoing committed database deletion', function () {
    $disk = Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->create();
    $disk->put($attachment->file_path, 'private');
    $faultyDisk = Mockery::mock($disk);
    $faultyDisk->shouldReceive('delete')->once()->andThrow(new RuntimeException('Storage unavailable at secret/path.'));
    Storage::set('private', $faultyDisk);
    Log::shouldReceive('warning')->once()->with('Knowledge source attachment cleanup failed.', [
        'attachment_id' => $attachment->id, 'exception_type' => RuntimeException::class,
    ]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$attachment->knowledge_source_id.'/attachments/'.$attachment->id)->assertOk();

    $this->assertModelMissing($attachment);
    $disk->assertExists($attachment->file_path);
});

test('deleting a record whose file is missing is safe', function () {
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$attachment->knowledge_source_id.'/attachments/'.$attachment->id)->assertOk();

    $this->assertModelMissing($attachment);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('configured private attachment disk is used for upload download and deletion', function () {
    config(['filesystems.knowledge_sources_disk' => 'knowledge_archive']);
    Storage::fake('knowledge_archive');
    Storage::fake('private');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $this->withToken($token)->post('/api/admin/knowledge-sources/'.$source->id.'/attachments', [
        'attachments' => [knowledgeDocument()],
    ], ['Accept' => 'application/json'])->assertCreated();
    $attachment = $source->attachments()->sole();

    Storage::disk('knowledge_archive')->assertExists($attachment->file_path);
    $this->withToken($token)->get('/api/admin/knowledge-sources/'.$source->id.'/attachments/'.$attachment->id)->assertDownload('report.pdf');
    $this->withToken($token)->deleteJson('/api/admin/knowledge-sources/'.$source->id.'/attachments/'.$attachment->id)->assertOk();

    Storage::disk('knowledge_archive')->assertMissing($attachment->file_path);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

test('deleting an admin preserves sources and nulls reviewer creator and updater identities', function () {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->verified()->create(['verified_by' => $admin->id, 'created_by' => $admin->id, 'updated_by' => $admin->id]);

    $admin->delete();

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verified_by' => null, 'created_by' => null, 'updated_by' => null]);
});
