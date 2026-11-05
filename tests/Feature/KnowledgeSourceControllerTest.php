<?php

use App\Models\AdminUser;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceAttachment;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

test('knowledge source endpoints require admin authentication', function (string $method, string $suffix) {
    $source = KnowledgeSource::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    $path = str_replace(['{source}', '{attachment}'], [$source->id, $attachment->id], $suffix);

    $this->json($method, '/api/admin/knowledge-sources'.$path)
        ->assertUnauthorized()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
        ]);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'detail' => ['GET', '/{source}'],
    'update' => ['PATCH', '/{source}'], 'delete' => ['DELETE', '/{source}'],
    'status' => ['PATCH', '/{source}/status'], 'review' => ['POST', '/{source}/review'],
    'upload' => ['POST', '/{source}/attachments'],
    'download' => ['GET', '/{source}/attachments/{attachment}'],
    'remove attachment' => ['DELETE', '/{source}/attachments/{attachment}'],
]);

test('frontend identities cannot access knowledge sources even with wildcard abilities', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/knowledge-sources')->assertUnauthorized();
});

test('inactive admins and tokens missing admin ability cannot access knowledge sources', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/knowledge-sources')->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('management and reviewer gates enforce their permission matrix', function () {
    expect(Gate::forUser(AdminUser::factory()->make())->allows('manage-knowledge-sources'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->make())->allows('review-knowledge-sources'))->toBeFalse();
    expect(Gate::forUser(AdminUser::factory()->superUser()->make())->allows('review-knowledge-sources'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->superUser()->inactive()->make())->allows('review-knowledge-sources'))->toBeFalse();
    expect(Gate::forUser(User::factory()->make())->allows('manage-knowledge-sources'))->toBeFalse();
});

test('management denial protects every knowledge source operation', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->create();
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Gate::define('manage-knowledge-sources', fn (Authenticatable $user): bool => false);
    $path = str_replace(['{source}', '{attachment}'], [$source->id, $attachment->id], $suffix);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/knowledge-sources'.$path)->assertForbidden();

    $this->assertModelExists($source);
    $this->assertModelExists($attachment);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'detail' => ['GET', '/{source}'],
    'update' => ['PATCH', '/{source}'], 'delete' => ['DELETE', '/{source}'],
    'status' => ['PATCH', '/{source}/status'], 'review' => ['POST', '/{source}/review'],
    'upload' => ['POST', '/{source}/attachments'],
    'download' => ['GET', '/{source}/attachments/{attachment}'],
    'remove attachment' => ['DELETE', '/{source}/attachments/{attachment}'],
]);

test('admins create an inactive pending draft with server controlled identity and timestamps', function () {
    $admin = AdminUser::factory()->create();
    $this->travelTo('2026-11-01 08:45:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources', [
            'title' => 'Soil health handbook', 'publisher' => 'Agricultural Institute', 'source_type' => 'manual',
            'published_at' => '2025-04-01', 'publication_year' => 2025, 'language' => 'en',
            'verification_status' => 'verified', 'is_active' => true, 'created_by' => 999,
            'updated_by' => 999, 'verified_by' => 999, 'verified_at' => '2020-01-01', 'id' => 999,
        ]);

    $response->assertCreated()->assertJsonPath('data.verification_status', 'pending')
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.created_by', $admin->id)
        ->assertJsonPath('data.updated_by', $admin->id)->assertJsonPath('data.verified_by', null)
        ->assertJsonPath('data.added_at', '1 Nov 2026 08:45 AM')
        ->assertJsonPath('data.created_at', '2026-11-01T08:45:00.123456Z')
        ->assertJsonPath('data.published_at', '2025-04-01')->assertJsonPath('data.attachments', [])
        ->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('knowledge_sources', [
        'id' => $response->json('data.id'), 'title' => 'Soil health handbook', 'source_url' => null,
        'verification_status' => 'pending', 'is_active' => false, 'created_by' => $admin->id,
    ]);
});

test('knowledge source creation validates required metadata in the standard envelope', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/knowledge-sources', [])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'title' => 'The title field is required.',
            'publisher' => 'The publisher field is required.',
            'source_type' => 'The source type field is required.',
        ])->assertJsonPath('message', 'Validation failed.')->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    $this->assertDatabaseCount('knowledge_sources', 0);
});

test('invalid knowledge source metadata returns 422', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/knowledge-sources', array_replace([
        'title' => 'Soil guide', 'publisher' => 'Institute', 'source_type' => 'report',
    ], $changes))->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('knowledge_sources', 0);
})->with([
    'type' => [['source_type' => 'invalid'], 'source_type'],
    'non HTTP URL' => [['source_url' => 'ftp://example.org/source'], 'source_url'],
    'invalid URL' => [['source_url' => 'invalid'], 'source_url'],
    'unsupported language' => [['language' => 'xx'], 'language'],
    'invalid identifier type' => [['identifier_type' => 'invalid'], 'identifier_type'],
    'invalid date' => [['published_at' => '2026-99-99'], 'published_at'],
    'year too small' => [['publication_year' => 999], 'publication_year'],
    'year too large' => [['publication_year' => 65536], 'publication_year'],
    'title length' => [['title' => str_repeat('x', 501)], 'title'],
    'publisher length' => [['publisher' => str_repeat('x', 256)], 'publisher'],
    'URL length' => [['source_url' => 'https://example.org/'.str_repeat('x', 2048)], 'source_url'],
    'identifier length' => [['reference_identifier' => str_repeat('x', 256)], 'reference_identifier'],
]);

test('material partial metadata changes reset review and preserve attachments', function () {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create(['review_notes' => 'Approved', 'next_review_at' => now()->addMonth()]);
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/knowledge-sources/'.$source->id, [
        'description' => 'New scientific conclusions', 'verified_by' => 999, 'verification_status' => 'verified', 'is_active' => true,
    ])->assertOk()->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.verified_by', null)->assertJsonPath('data.verified_at', null)
        ->assertJsonPath('data.next_review_at', null)->assertJsonPath('data.updated_by', $admin->id)
        ->assertJsonCount(1, 'data.attachments');

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'description' => 'New scientific conclusions', 'title' => $source->title]);
    $this->assertModelExists($attachment);
});

test('unchanged metadata and license notes preserve verification', function (array $changes) {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Soil guide']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/knowledge-sources/'.$source->id, $changes)->assertOk()
        ->assertJsonPath('data.verification_status', 'verified')->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'verified', 'is_active' => true]);
})->with(['unchanged' => [['title' => 'Soil guide']], 'licensing only' => [['license_notes' => 'Open access']]]);

test('knowledge source search matches title publisher and reference identifier literally', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $match = KnowledgeSource::factory()->create(['title' => 'Soil 50%_! guide', 'publisher' => 'Agronomy Institute', 'reference_identifier' => 'REF-123']);
    KnowledgeSource::factory()->create(['title' => 'Soil 500ABC guide', 'publisher' => 'Other', 'reference_identifier' => 'Different']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/knowledge-sources?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)->assertJsonPath('pagination.total', 1);
})->with(['title' => ['50%_!'], 'publisher' => ['Agronomy'], 'identifier' => ['REF-123']]);

test('knowledge source filters compose before pagination and preserve links', function () {
    $admin = AdminUser::factory()->create();
    $this->travelTo('2026-11-01 09:00:00');
    $sources = KnowledgeSource::factory()->active()->count(2)->create([
        'source_type' => 'report', 'language' => 'hi', 'next_review_at' => '2026-10-31 00:00:00',
    ]);
    KnowledgeSource::factory()->create(['source_type' => 'website', 'language' => 'en']);
    $query = http_build_query([
        'source_type' => 'report', 'language' => 'hi', 'verification_status' => 'verified',
        'is_active' => 1, 'review_due' => 1, 'per_page' => 1,
    ]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/knowledge-sources?'.$query);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sources->last()->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.last_page', 2)->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 1);
    expect($response->json('pagination.next_page_url'))->toContain('language=hi', 'review_due=1', 'per_page=1');
});

test('knowledge source list supports safe sorting and empty pages', function () {
    $admin = AdminUser::factory()->create();
    KnowledgeSource::factory()->create(['title' => 'Zulu']);
    $first = KnowledgeSource::factory()->create(['title' => 'Alpha']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/knowledge-sources?sort_by=title&sort_direction=asc')
        ->assertOk()->assertJsonPath('data.0.id', $first->id);
    $this->withToken($token)->getJson('/api/admin/knowledge-sources?page=5')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid list filters and unsafe sorting return 422', function (string $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/knowledge-sources?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'page' => ['page=0', 'page'], 'per page' => ['per_page=101', 'per_page'],
    'sort injection' => ['sort_by=title%20desc%3Bdrop', 'sort_by'],
    'direction injection' => ['sort_direction=desc%3Bdrop', 'sort_direction'],
    'status' => ['verification_status=unknown', 'verification_status'],
    'review due' => ['review_due=invalid', 'review_due'],
]);

test('reviewers verify a referenced source without activating it', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->create(['rejection_reason' => 'Previous rejection']);
    $this->travelTo('2026-11-01 09:00:00.123456');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', [
        'action' => 'verify', 'review_notes' => 'Checked publication', 'next_review_at' => '2027-01-01T14:00:00+05:30',
    ])->assertOk()->assertJsonPath('data.verification_status', 'verified')->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.verified_by', $admin->id)->assertJsonPath('data.verified_at', '2026-11-01T09:00:00.123456Z')
        ->assertJsonPath('data.last_checked_at', '2026-11-01T09:00:00.123456Z')
        ->assertJsonPath('data.next_review_at', '2027-01-01T08:30:00.000000Z')->assertJsonPath('data.rejection_reason', null);

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'verified', 'verified_by' => $admin->id, 'review_notes' => 'Checked publication']);
});

test('a reference identifier is sufficient to verify a source', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->create(['source_url' => null, 'reference_identifier' => 'Institute report 2026-01', 'identifier_type' => 'report_number']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', ['action' => 'verify'])->assertOk();

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'verified']);
});

test('sources with no usable reference cannot be verified including missing attachment files', function (bool $missingFile) {
    Storage::fake('private');
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->create(['source_url' => null]);
    if ($missingFile) {
        KnowledgeSourceAttachment::factory()->for($source)->create();
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', ['action' => 'verify'])
        ->assertUnprocessable()->assertJsonValidationErrors('action');

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'pending']);
    expect(Storage::disk('private')->allFiles())->toBe([]);
})->with(['no reference' => [false], 'missing file' => [true]]);

test('ordinary managers cannot perform scientific reviews', function () {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', ['action' => 'verify'])->assertForbidden();

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'pending']);
});

test('review decisions deactivate sources and record the current reviewer', function (string $action, array $fields, string $status) {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->active()->create(['next_review_at' => now()->addMonth()]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', ['action' => $action, ...$fields])
        ->assertOk()->assertJsonPath('data.verification_status', $status)->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.next_review_at', null)->assertJsonPath('data.verified_by', $admin->id);

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => $status, 'is_active' => false, ...$fields]);
})->with([
    'reject' => ['reject', ['rejection_reason' => 'Unreliable evidence'], 'rejected'],
    'outdate' => ['outdate', ['review_notes' => 'Superseded publication'], 'outdated'],
]);

test('reopening clears the current decision and deactivates the source', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->active()->create(['rejection_reason' => 'Stale']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', ['action' => 'reopen', 'review_notes' => 'New edition available'])
        ->assertOk()->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.verified_by', null)->assertJsonPath('data.rejection_reason', null);

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'pending', 'verified_at' => null, 'review_notes' => 'New edition available']);
});

test('review validation rejects missing explanations invalid actions and past schedules', function (array $payload, string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->create();
    $this->travelTo('2026-11-01');

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources/'.$source->id.'/review', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'verification_status' => 'pending']);
})->with([
    'reject reason' => [['action' => 'reject'], 'rejection_reason'],
    'outdated notes' => [['action' => 'outdate'], 'review_notes'],
    'unknown action' => [['action' => 'approve'], 'action'],
    'past review' => [['action' => 'verify', 'next_review_at' => '2020-01-01'], 'next_review_at'],
]);

test('only verified sources can be activated', function (string $status) {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create(['verification_status' => $status]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/knowledge-sources/'.$source->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');

    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'is_active' => false]);
})->with(['pending', 'rejected', 'outdated']);

test('admins activate verified sources and can deactivate them', function () {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->verified()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/knowledge-sources/'.$source->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'is_active' => true]);
    $this->withToken($token)->patchJson('/api/admin/knowledge-sources/'.$source->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'is_active' => false]);
});

test('missing knowledge sources return a standard 404', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/knowledge-sources/999')->assertNotFound()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
        ]);
});
