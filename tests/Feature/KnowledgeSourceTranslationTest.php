<?php

use App\Models\AdminUser;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceTranslation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('admins save source title translations together without replacing the original citation', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $translations = [
        'en' => ['title' => 'English guide'],
        'hi' => ['title' => 'हिन्दी मार्गदर्शिका'],
        'gu' => ['title' => 'ગુજરાતી માર્ગદર્શિકા'],
    ];

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources', [
            'title' => 'Original citation title', 'language' => 'hi', 'publisher' => 'Institute',
            'source_type' => 'manual', 'translations' => $translations,
        ])->assertCreated()->assertJsonPath('data.title', 'Original citation title')
        ->assertJsonPath('data.language', 'hi')->assertJsonCount(3, 'data.translations')
        ->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.is_active', false)
        ->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $response->json('data.id'), 'title' => 'Original citation title', 'language' => 'hi']);
    foreach ($translations as $locale => $translation) {
        $response->assertJsonPath('data.translations.'.$locale.'.title', $translation['title']);
        $this->assertDatabaseHas('knowledge_source_translations', [
            'knowledge_source_id' => $response->json('data.id'), 'locale' => $locale, 'title' => $translation['title'],
        ]);
    }
    $this->getJson('/api/admin/knowledge-sources/'.$response->json('data.id'))
        ->assertOk()->assertJsonCount(3, 'data.translations')
        ->assertJsonPath('data.translations.en.title', 'English guide')
        ->assertJsonPath('data.translations.hi.title', 'हिन्दी मार्गदर्शिका')
        ->assertJsonPath('data.translations.gu.title', 'ગુજરાતી માર્ગદર્શિકા');
});

test('existing source payloads keep their original title and do not create guessed translations', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources', [
            'title' => 'हिन्दी मूल शीर्षक', 'language' => 'hi', 'publisher' => 'Institute', 'source_type' => 'report',
        ])->assertCreated()->assertJsonPath('data.title', 'हिन्दी मूल शीर्षक');

    expect($response->json('data.translations'))->toBe([]);
    expect(KnowledgeSource::findOrFail($response->json('data.id'))->load('translations')->translatedTitle('en'))->toBe('हिन्दी मूल शीर्षक');
    $this->assertDatabaseCount('knowledge_source_translations', 0);
});

test('source creation requires the default title when translations are supplied and returns 422 without writes', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/knowledge-sources', [
            'title' => 'Original title', 'publisher' => 'Institute', 'source_type' => 'report',
            'translations' => ['hi' => ['title' => 'हिन्दी शीर्षक']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['translations.en', 'translations.en.title']);

    $this->assertDatabaseCount('knowledge_sources', 0);
    $this->assertDatabaseCount('knowledge_source_translations', 0);
});

test('invalid source translations return 422 before master or translation changes', function (mixed $translations, string $field) {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create(['title' => 'Original title']);
    $source->translations()->create(['locale' => 'en', 'title' => 'Original English']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/knowledge-sources/'.$source->id, [
        'title' => 'Must not persist', 'translations' => $translations,
    ])->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('message', 'Validation failed.');

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseHas('knowledge_sources', ['id' => $source->id, 'title' => 'Original title']);
    $this->assertDatabaseCount('knowledge_source_translations', 1);
    $this->assertDatabaseHas('knowledge_source_translations', ['knowledge_source_id' => $source->id, 'locale' => 'en', 'title' => 'Original English']);
})->with([
    'null object' => [null, 'translations'],
    'empty object' => [[], 'translations'],
    'non object' => ['title', 'translations'],
    'unsupported locale' => [['fr' => ['title' => 'French']], 'translations'],
    'numeric locale keys' => [[['title' => 'English']], 'translations'],
    'null translation' => [['hi' => null], 'translations.hi'],
    'empty translation' => [['hi' => []], 'translations.hi.title'],
    'missing title' => [['hi' => ['description' => 'Unknown']], 'translations.hi.title'],
    'foreign source ID' => [['hi' => ['title' => 'Hindi', 'knowledge_source_id' => 999]], 'translations.hi'],
    'translation ID' => [['hi' => ['title' => 'Hindi', 'id' => 999]], 'translations.hi'],
    'null title' => [['hi' => ['title' => null]], 'translations.hi.title'],
    'empty title' => [['hi' => ['title' => ' ']], 'translations.hi.title'],
    'long title' => [['hi' => ['title' => str_repeat('a', 501)]], 'translations.hi.title'],
    'non string title' => [['hi' => ['title' => ['Unexpected']]], 'translations.hi.title'],
]);

test('source translation updates preserve omitted locales and original metadata while resetting verification', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Original citation', 'language' => 'hi']);
    $source->translations()->createMany([
        ['locale' => 'en', 'title' => 'English guide'], ['locale' => 'hi', 'title' => 'पुराना शीर्षक'],
    ]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/knowledge-sources/'.$source->id, ['translations' => [
            'hi' => ['title' => 'नया शीर्षक'], 'gu' => ['title' => 'ગુજરાતી શીર્ષક'],
        ]])->assertOk()->assertJsonPath('data.title', 'Original citation')
        ->assertJsonPath('data.translations.en.title', 'English guide')
        ->assertJsonPath('data.translations.hi.title', 'नया शीर्षक')
        ->assertJsonPath('data.translations.gu.title', 'ગુજરાતી શીર્ષક')
        ->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.verified_by', null)->assertJsonPath('data.verified_at', null);

    $this->assertDatabaseHas('knowledge_sources', [
        'id' => $source->id, 'title' => 'Original citation', 'language' => 'hi', 'verification_status' => 'pending',
        'is_active' => false, 'verified_by' => null, 'updated_by' => $admin->id,
    ]);
    $this->assertDatabaseHas('knowledge_source_translations', ['knowledge_source_id' => $source->id, 'locale' => 'hi', 'title' => 'नया शीर्षक']);
    $this->assertDatabaseCount('knowledge_source_translations', 3);
});

test('new translations invalidate verification and identical translations preserve it', function (bool $existingTranslation) {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create();
    if ($existingTranslation) {
        $source->translations()->create(['locale' => 'hi', 'title' => 'हिन्दी शीर्षक']);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/knowledge-sources/'.$source->id, [
            'license_notes' => 'Same bibliographic content', 'translations' => ['hi' => ['title' => 'हिन्दी शीर्षक']],
        ])->assertOk()->assertJsonPath('data.verification_status', $existingTranslation ? 'verified' : 'pending')
        ->assertJsonPath('data.is_active', $existingTranslation);

    $this->assertDatabaseHas('knowledge_sources', [
        'id' => $source->id, 'is_active' => $existingTranslation, 'verification_status' => $existingTranslation ? 'verified' : 'pending',
    ]);
    $this->assertDatabaseCount('knowledge_source_translations', 1);
})->with(['new' => false, 'unchanged' => true]);

test('source creation rolls back the master and earlier translations if a translation cannot be saved', function () {
    $admin = AdminUser::factory()->create();
    DB::unprepared("CREATE TRIGGER fail_source_title_insert BEFORE INSERT ON knowledge_source_translations FOR EACH ROW WHEN NEW.locale = 'hi' BEGIN SELECT RAISE(ABORT, 'Simulated translation persistence failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson('/api/admin/knowledge-sources', [
                'title' => 'Original', 'publisher' => 'Institute', 'source_type' => 'report',
                'translations' => ['en' => ['title' => 'English'], 'hi' => ['title' => 'Hindi']],
            ])->assertInternalServerError()->assertJsonPath('data', null);

        $this->assertDatabaseCount('knowledge_sources', 0);
        $this->assertDatabaseCount('knowledge_source_translations', 0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_source_title_insert');
    }
});

test('failed source translation changes preserve existing titles metadata and verification', function () {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Original citation']);
    $source->translations()->create(['locale' => 'en', 'title' => 'Original English']);
    DB::unprepared("CREATE TRIGGER fail_source_title_update BEFORE INSERT ON knowledge_source_translations FOR EACH ROW WHEN NEW.locale = 'hi' BEGIN SELECT RAISE(ABORT, 'Simulated translation persistence failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->patchJson('/api/admin/knowledge-sources/'.$source->id, [
                'title' => 'Revised citation', 'translations' => ['en' => ['title' => 'Revised English'], 'hi' => ['title' => 'Hindi']],
            ])->assertInternalServerError();

        $this->assertDatabaseHas('knowledge_sources', [
            'id' => $source->id, 'title' => 'Original citation', 'verification_status' => 'verified', 'is_active' => true,
        ]);
        $this->assertDatabaseCount('knowledge_source_translations', 1);
        $this->assertDatabaseHas('knowledge_source_translations', ['knowledge_source_id' => $source->id, 'locale' => 'en', 'title' => 'Original English']);
    } finally {
        DB::unprepared('DROP TRIGGER fail_source_title_update');
    }
});

test('source title lookup uses loaded translations with a canonical fallback and never lazy loads', function () {
    $source = KnowledgeSource::factory()->create(['title' => 'Original citation']);
    $source->translations()->createMany([
        ['locale' => 'en', 'title' => 'English title'], ['locale' => 'hi', 'title' => 'हिन्दी शीर्षक'],
    ]);
    DB::enableQueryLog();

    try {
        expect($source->translatedTitle('hi'))->toBe('Original citation');
        expect(DB::getQueryLog())->toBe([]);
        $source->load('translations');
        DB::flushQueryLog();
        expect($source->translatedTitle('hi'))->toBe('हिन्दी शीर्षक');
        expect($source->translatedTitle('gu'))->toBe('English title');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('source title translations remain scoped to their source and cascade only on its authorized deletion', function () {
    $admin = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->create();
    $translation = KnowledgeSourceTranslation::factory()->for($source)->create(['title' => 'English title']);
    $otherTranslation = KnowledgeSourceTranslation::factory()->create();

    expect($translation->knowledgeSource->is($source))->toBeTrue();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/knowledge-sources/'.$source->id)->assertOk();

    $this->assertModelMissing($source);
    $this->assertModelMissing($translation);
    $this->assertModelExists($otherTranslation);
});

test('a source cannot store two titles for the same locale', function () {
    $source = KnowledgeSource::factory()->create();
    $source->translations()->create(['locale' => 'en', 'title' => 'First title']);

    expect(fn () => $source->translations()->create(['locale' => 'en', 'title' => 'Duplicate title']))
        ->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('knowledge_source_translations', 1);
});
