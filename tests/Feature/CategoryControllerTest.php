<?php

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

test('category endpoints require an admin bearer token and return 401', function (string $method, bool $singleCategory) {
    $category = Category::factory()->create();
    $uri = '/api/admin/categories'.($singleCategory ? '/'.$category->id : '');

    $response = $this->json($method, $uri, ['title' => 'New Category', 'slug' => 'new-category']);

    $response->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
    ]);
    $this->assertDatabaseCount('categories', 1);
    $this->assertModelExists($category);
})->with([
    'index' => ['GET', false],
    'store' => ['POST', false],
    'show' => ['GET', true],
    'update' => ['PATCH', true],
    'destroy' => ['DELETE', true],
]);

test('frontend bearer tokens cannot access category endpoints and return 401', function (string $method, bool $singleCategory) {
    $category = Category::factory()->create();
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'])->plainTextToken;
    $uri = '/api/admin/categories'.($singleCategory ? '/'.$category->id : '');

    $response = $this->withToken($token)->json($method, $uri, ['title' => 'New Category', 'slug' => 'new-category']);

    $response->assertUnauthorized();
    $this->assertDatabaseCount('categories', 1);
    $this->assertModelExists($category);
})->with([
    'index' => ['GET', false],
    'store' => ['POST', false],
    'show' => ['GET', true],
    'update' => ['PATCH', true],
    'destroy' => ['DELETE', true],
]);

test('inactive admins cannot access categories and receive 403', function () {
    $admin = AdminUser::factory()->inactive()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories');

    $response->assertForbidden()->assertJsonPath('message', 'Admin account is inactive.');
});

test('admin tokens without the admin ability receive 403 for categories', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['frontend'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories');

    $response->assertForbidden();
});

test('the category gate allows active admins with either super-user flag', function () {
    $regularAdmin = AdminUser::factory()->make();
    $superAdmin = AdminUser::factory()->superUser()->make();
    $inactiveAdmin = AdminUser::factory()->inactive()->make();
    $frontendUser = User::factory()->make();

    expect(Gate::forUser($regularAdmin)->allows('manage-categories'))->toBeTrue();
    expect(Gate::forUser($superAdmin)->allows('manage-categories'))->toBeTrue();
    expect(Gate::forUser($inactiveAdmin)->allows('manage-categories'))->toBeFalse();
    expect(Gate::forUser($frontendUser)->allows('manage-categories'))->toBeFalse();
});

test('category endpoints enforce the management gate and return 403', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Gate::define('manage-categories', fn (Authenticatable $user): bool => false);

    $response = $this->withToken($token)->postJson('/api/admin/categories', ['title' => 'New Category', 'slug' => 'new-category']);

    $response->assertForbidden();
    $this->assertDatabaseCount('categories', 0);
});

test('active admins can create categories with SEO fields and active defaults', function (bool $isSuperUser) {
    $admin = AdminUser::factory()->create(['is_super_user' => $isSuperUser]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $payload = [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours',
        'meta_title' => 'Royal Heritage', 'meta_keyword' => 'forts, palaces',
        'meta_description' => 'Travel through Rajasthan.',
        'description' => '<p>Discover <strong>royal forts</strong>.</p>',
        'image_url' => 'https://untrusted.test/photo.jpg',
    ];

    $response = $this->withToken($token)->postJson('/api/admin/categories', $payload);

    $response->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.title', 'Heritage Tours')->assertJsonPath('data.slug', 'heritage-tours')
        ->assertJsonPath('data.meta_title', 'Royal Heritage')->assertJsonPath('data.meta_keyword', 'forts, palaces')
        ->assertJsonPath('data.meta_description', 'Travel through Rajasthan.')
        ->assertJsonPath('data.description', '<p>Discover <strong>royal forts</strong>.</p>')
        ->assertJsonPath('data.status', true)->assertJsonPath('data.image', null)->assertJsonPath('data.image_url', null);
    $this->assertDatabaseHas('categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'status' => 1,
        'meta_title' => 'Royal Heritage', 'meta_keyword' => 'forts, palaces',
        'meta_description' => 'Travel through Rajasthan.', 'description' => $payload['description'], 'image' => null,
    ]);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data')))->toBe([
        'id', 'title', 'slug', 'meta_title', 'meta_keyword', 'meta_description', 'image', 'image_url',
        'description', 'status', 'created_at', 'updated_at',
    ]);
})->with(['regular admin' => [false], 'super admin' => [true]]);

test('categories accept multipart boolean status strings on creation', function (string $status, bool $expectedStatus) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'New Category', 'slug' => 'new-category', 'status' => $status,
    ], ['Accept' => 'application/json']);

    $response->assertCreated()->assertJsonPath('data.status', $expectedStatus);
    $this->assertDatabaseHas('categories', ['slug' => 'new-category', 'status' => $expectedStatus]);
})->with(['active' => ['1', true], 'inactive' => ['0', false]]);

test('category details expose editing fields and nullable values', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->inactive()->create([
        'meta_title' => 'SEO Title', 'meta_keyword' => 'travel, luxury', 'meta_description' => 'SEO description',
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories/'.$category->id);

    $response->assertOk()->assertJsonPath('data.id', $category->id)->assertJsonPath('data.title', $category->title)
        ->assertJsonPath('data.slug', $category->slug)->assertJsonPath('data.meta_title', 'SEO Title')
        ->assertJsonPath('data.meta_keyword', 'travel, luxury')->assertJsonPath('data.meta_description', 'SEO description')
        ->assertJsonPath('data.status', false)->assertJsonPath('data.image_url', null)
        ->assertJsonPath('data.description', $category->description)->assertJsonPath('pagination', null);
});

test('category updates are partial and can retain their own unique slug', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create([
        'title' => 'Original Title', 'slug' => 'original-slug', 'meta_title' => 'Old SEO', 'description' => 'Keep this description.',
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/categories/'.$category->id, [
        'title' => 'Updated Title', 'slug' => 'original-slug', 'meta_title' => null, 'status' => '0',
    ]);

    $response->assertOk()->assertJsonPath('data.title', 'Updated Title')->assertJsonPath('data.slug', 'original-slug')
        ->assertJsonPath('data.meta_title', null)->assertJsonPath('data.description', 'Keep this description.')
        ->assertJsonPath('data.status', false);
    $this->assertDatabaseHas('categories', [
        'id' => $category->id, 'title' => 'Updated Title', 'slug' => 'original-slug',
        'meta_title' => null, 'description' => 'Keep this description.', 'status' => 0,
    ]);
});

test('categories can change to another unused slug', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->putJson('/api/admin/categories/'.$category->id, ['slug' => 'new-unique-slug']);

    $response->assertOk()->assertJsonPath('data.slug', 'new-unique-slug');
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'slug' => 'new-unique-slug']);
});

test('duplicate category slugs return 422 on creation without adding a category', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/categories', ['title' => 'New Category', 'slug' => $category->slug]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['slug' => 'The slug has already been taken.']);
    $this->assertDatabaseCount('categories', 1);
});

test('duplicate category slugs return 422 on update without changing the category', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['slug' => 'original-slug']);
    $otherCategory = Category::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/categories/'.$category->id, ['slug' => $otherCategory->slug]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['slug' => 'The slug has already been taken.']);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'slug' => 'original-slug']);
});

test('missing required category fields return the standard 422 validation envelope', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/categories', []);

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors(['title' => 'The title field is required.', 'slug' => 'The slug field is required.']);
    $this->assertDatabaseCount('categories', 0);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
});

test('invalid category fields return 422 without persistence', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $payload = array_replace(['title' => 'New Category', 'slug' => 'new-category'], $changes);

    $response = $this->withToken($token)->postJson('/api/admin/categories', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('categories', 0);
})->with([
    'oversized title' => [['title' => str_repeat('a', 256)], 'title'],
    'array title' => [['title' => ['New']], 'title'],
    'oversized slug' => [['slug' => str_repeat('a', 256)], 'slug'],
    'uppercase slug' => [['slug' => 'Heritage-Tours'], 'slug'],
    'spaces in slug' => [['slug' => 'heritage tours'], 'slug'],
    'path in slug' => [['slug' => '../heritage'], 'slug'],
    'double hyphen' => [['slug' => 'heritage--tours'], 'slug'],
    'oversized meta title' => [['meta_title' => str_repeat('a', 256)], 'meta_title'],
    'array meta keyword' => [['meta_keyword' => ['heritage']], 'meta_keyword'],
    'oversized meta keyword' => [['meta_keyword' => str_repeat('a', 65536)], 'meta_keyword'],
    'array meta description' => [['meta_description' => ['heritage']], 'meta_description'],
    'oversized meta description' => [['meta_description' => str_repeat('a', 65536)], 'meta_description'],
    'array description' => [['description' => ['heritage']], 'description'],
    'invalid status' => [['status' => 'active'], 'status'],
    'client-provided image path' => [['image' => 'categories/untrusted.jpg'], 'image'],
]);

test('invalid partial updates receive 422 and retain the original category', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['title' => 'Original Title', 'slug' => 'original-slug']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/categories/'.$category->id, ['title' => '', 'slug' => '']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['title', 'slug']);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'title' => 'Original Title', 'slug' => 'original-slug']);
});

test('category images accept JPEG, PNG and WebP with generated filenames', function (string $extension) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => UploadedFile::fake()->image('original.'.$extension),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $path = $response->json('data.image');
    expect($path)->toStartWith('categories/')->not->toContain('original');
    $response->assertJsonPath('data.image_url', Storage::disk('public')->url($path))->assertJsonMissingPath('data.uploadedImage');
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseHas('categories', ['slug' => 'heritage-tours', 'image' => $path]);
})->with(['jpg' => ['jpg'], 'jpeg' => ['jpeg'], 'png' => ['png'], 'webp' => ['webp']]);

test('invalid category images return 422 and create neither records nor files', function (string $type) {
    Storage::fake('public');
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $image = match ($type) {
        'oversized' => UploadedFile::fake()->image('photo.jpg')->size(2049),
        'gif' => UploadedFile::fake()->image('photo.gif'),
        'disguised text' => UploadedFile::fake()->createWithContent('photo.jpg', 'This is not an image.')->mimeType('text/plain'),
        'executable extension' => UploadedFile::fake()->image('photo.php'),
    };

    $response = $this->withToken($token)->post('/api/admin/categories', [
        'title' => 'Heritage Tours', 'slug' => 'heritage-tours', 'image' => $image,
    ], ['Accept' => 'application/json']);

    $response->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('categories', 0);
    expect(Storage::disk('public')->allFiles('categories'))->toBe([]);
})->with(['oversized' => ['oversized'], 'GIF' => ['gif'], 'disguised text' => ['disguised text'], 'executable extension' => ['executable extension']]);

test('omitted or null image updates retain the existing category image', function (array $payload) {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['image' => $path]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/categories/'.$category->id, $payload);

    $response->assertOk()->assertJsonPath('data.image', $path);
    Storage::disk('public')->assertExists($path);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => $path]);
})->with(['omitted image' => [['title' => 'Changed']], 'null image' => [['image' => null]]]);

test('direct multipart category updates return 422 instead of reporting an unchanged category as updated', function (string $method) {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['title' => 'Original Title']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $body = "--category-boundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nChanged Title\r\n--category-boundary--\r\n";

    $response = $this->call($method, '/api/admin/categories/'.$category->id, [], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=category-boundary',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], $body);

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors([
            '_method' => 'Send form-data updates using POST with _method set to PUT or PATCH, or send a JSON body with PUT or PATCH.',
        ]);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'title' => 'Original Title']);
})->with(['PUT' => ['PUT'], 'PATCH' => ['PATCH']]);

test('multipart POST with a method override replaces the image and updates category fields', function (string $method) {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('categories', 'public');
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['image' => $oldPath]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->post('/api/admin/categories/'.$category->id, [
        '_method' => $method, 'title' => 'Updated Title', 'slug' => $category->slug,
        'meta_title' => 'Updated SEO Title', 'description' => 'Updated description.',
        'image' => UploadedFile::fake()->image('new.png'), 'status' => '0',
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.status', false)->assertJsonPath('data.title', 'Updated Title')
        ->assertJsonPath('data.meta_title', 'Updated SEO Title')->assertJsonPath('data.description', 'Updated description.');
    $newPath = $response->json('data.image');
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($newPath)->assertMissing($oldPath);
    $this->assertDatabaseHas('categories', [
        'id' => $category->id, 'title' => 'Updated Title', 'slug' => $category->slug,
        'meta_title' => 'Updated SEO Title', 'description' => 'Updated description.', 'image' => $newPath, 'status' => 0,
    ]);
})->with(['PUT' => ['PUT'], 'PATCH' => ['PATCH']]);

test('category listing uses descending IDs and the standard pagination envelope', function () {
    $admin = AdminUser::factory()->create();
    $categories = Category::factory()->count(3)->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?per_page=2');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $categories->last()->id)
        ->assertJsonPath('data.1.id', $categories[1]->id)->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.last_page', 2)->assertJsonPath('pagination.per_page', 2)
        ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 2)
        ->assertJsonPath('pagination.prev_page_url', null)->assertJsonMissingPath('data.data')->assertJsonMissingPath('errors');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect($response->json('pagination.next_page_url'))->toContain('per_page=2');
});

test('category keywords partially match the title or slug', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['title' => 'Heritage Tours', 'slug' => 'royal-forts']);
    Category::factory()->create(['title' => 'Beach Holidays', 'slug' => 'beach-holidays']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?'.http_build_query(['keyword' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $category->id)->assertJsonPath('pagination.total', 1);
})->with(['title' => ['ritage'], 'slug' => ['yal-for'], 'trimmed title' => ['  Heritage  ']]);

test('category keyword matches the literal zero string', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['title' => 'Route 0', 'slug' => 'route-zero']);
    Category::factory()->create(['title' => 'Beach Holiday', 'slug' => 'beach-holiday']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?keyword=0');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $category->id);
});

test('category status filters support active and inactive values', function (string $status, bool $expectedStatus) {
    $admin = AdminUser::factory()->create();
    $active = Category::factory()->create();
    $inactive = Category::factory()->inactive()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $expectedCategory = $expectedStatus ? $active : $inactive;

    $response = $this->withToken($token)->getJson('/api/admin/categories?status='.$status);

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expectedCategory->id)
        ->assertJsonPath('data.0.status', $expectedStatus)->assertJsonPath('pagination.total', 1);
})->with(['active' => ['1', true], 'inactive' => ['0', false]]);

test('grouped category keywords combine with status before pagination and remain in links', function () {
    $admin = AdminUser::factory()->create();
    Category::factory()->create(['title' => 'Heritage Active', 'slug' => 'heritage-active']);
    Category::factory()->inactive()->create(['title' => 'Beach Holiday', 'slug' => 'beach-holiday']);
    $matched = Category::factory()->inactive()->create(['title' => 'Heritage Inactive', 'slug' => 'heritage-inactive']);
    $slugMatch = Category::factory()->inactive()->create(['title' => 'Royal Palaces', 'slug' => 'heritage-palaces']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?keyword=heritage&status=0&per_page=1');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $slugMatch->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $nextPageQuery);
    expect($nextPageQuery)->toMatchArray(['keyword' => 'heritage', 'status' => '0', 'per_page' => '1', 'page' => '2']);
    $this->withToken($token)->getJson('/api/admin/categories?keyword=heritage&status=0&per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.0.id', $matched->id);
});

test('resetting category filters returns active and inactive categories', function (string $query) {
    $admin = AdminUser::factory()->create();
    Category::factory()->create();
    Category::factory()->inactive()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories'.$query);

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.per_page', 15);
})->with(['omitted filters' => [''], 'blank filters' => ['?keyword=%20%20&status=']]);

test('special keyword characters cannot broaden category matches', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create(['title' => 'Match '.$keyword, 'slug' => 'matching-category']);
    Category::factory()->create(['title' => 'Other Category', 'slug' => 'other-category']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?'.http_build_query(['keyword' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $category->id);
})->with(['percent' => ['%'], 'underscore' => ['_'], 'escape' => ['!'], 'SQL fragment' => ["' OR 1=1 --"]]);

test('empty category pages return empty arrays with nullable bounds', function (string $query, int $total) {
    $admin = AdminUser::factory()->create();
    Category::factory()->create(['title' => 'Heritage Tours', 'slug' => 'heritage-tours']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?'.$query);

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', $total)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
})->with(['no matches' => ['keyword=nonexistent', 0], 'page beyond last page' => ['page=100', 1]]);

test('invalid category filters and pagination return 422', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/categories?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'array keyword' => [['keyword' => ['heritage']], 'keyword'],
    'oversized keyword' => [['keyword' => str_repeat('a', 256)], 'keyword'],
    'invalid status' => [['status' => 'active'], 'status'],
    'zero page' => [['page' => '0'], 'page'],
    'noninteger page' => [['page' => 'abc'], 'page'],
    'zero page size' => [['per_page' => '0'], 'per_page'],
    'oversized page size' => [['per_page' => '101'], 'per_page'],
]);

test('deleting a category without an image returns a 200 envelope', function () {
    $admin = AdminUser::factory()->create();
    $category = Category::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('/api/admin/categories/'.$category->id);

    $response->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Category deleted successfully.',
    ]);
    $this->assertModelMissing($category);
});

test('missing category records return the standard 404 envelope', function (string $method) {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->json($method, '/api/admin/categories/999');

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
})->with(['show' => ['GET'], 'update' => ['PATCH'], 'destroy' => ['DELETE']]);

test('category database list failures return a safe 500 and are reported', function () {
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    Schema::drop('categories');
    Exceptions::fake();

    $response = $this->withToken($token)->getJson('/api/admin/categories');

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null,
        'message' => 'Unable to retrieve categories. Please try again later.',
    ]);
    Exceptions::assertReported(QueryException::class);
});

test('the optional category seeder does not overwrite existing categories', function () {
    $existing = Category::factory()->inactive()->create(['title' => 'Custom Heritage', 'slug' => 'heritage-tours']);

    $this->seed(CategorySeeder::class);

    $this->assertDatabaseCount('categories', 6);
    expect($existing->refresh()->title)->toBe('Custom Heritage');
    expect($existing->status)->toBeFalse();

    $this->seed(CategorySeeder::class);

    $this->assertDatabaseCount('categories', 6);
});
