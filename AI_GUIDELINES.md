# AI Guidelines: Laravel API Backend

Read this file before creating, editing, or reviewing backend code. These project-specific requirements supplement `AGENTS.md` and take precedence over generic frontend suggestions.

## Scope and workflow

- This application uses Laravel for APIs only. Do not add Blade pages, frontend components, CSS, JavaScript tooling, or frontend build requirements for backend work.
- Define application endpoints in `routes/api.php`. If it is not present or registered, register it through `bootstrap/app.php` when implementing the first API feature. Do not put application API endpoints in `routes/web.php`.
- Confirm installed package versions before using their APIs. Follow existing sibling files and read applicable `.ai/rules` if present.
- Use Artisan generators with `--no-interaction`. Do not change dependencies or introduce new base directories without approval.
- Preserve existing client contracts unless a change is explicitly requested. Report legacy endpoints that differ from this contract instead of silently rewriting unrelated endpoints.

## Admin routes and controllers

- All admin controllers belong in `app/Http/Controllers/Admin`, with namespace `App\Http\Controllers\Admin` and names such as `UserController`.
- Group admin routes using `Route::prefix('admin')->name('admin.')` in `routes/api.php`. With Laravel's API prefix, URLs become `/api/admin/users`; do not repeat `api` or `admin` in child routes.
- Apply the project's configured authentication middleware and explicit admin authorization to the group. An admin URL prefix alone does not restrict access. Do not assume an `admin` middleware or a particular authentication package already exists.
- Use policies or gates for resource-level permissions, including ownership and tenant boundaries where relevant.
- Use `Route::apiResource()` for resource endpoints. Standard controller methods are `index`, `store`, `show`, `update`, and `destroy`; do not add HTML-oriented `create` or `edit` actions.
- Create admin controllers using, for example, `php artisan make:controller Admin/UserController --api --no-interaction`.
- Keep controllers focused on validation, authorization, coordinating business operations, and building the JSON response. Extract substantial or reusable business logic into existing service or action patterns.
- Use explicit parameter and return types. Controller actions returning this contract must return `Illuminate\Http\JsonResponse`.

## Separate admin and frontend authentication

- Keep two independent authenticatable models: `App\Models\AdminUser` uses `admin_users`, and `App\Models\User` uses `users`. Both extend Laravel's `Authenticatable` and use Sanctum's `HasApiTokens`. Never authenticate an admin against `users` or a frontend user against `admin_users`.
- This backend serves JSON APIs to clients that may run on other servers or unrelated domains. Use Sanctum personal access tokens in `Authorization: Bearer <access_token>` over HTTPS. Do not introduce session login, Blade authentication pages, or Sanctum stateful SPA middleware for these endpoints.
- Keep `sanctum.guard` empty and `sanctum.routes` false so API authentication does not fall back to web sessions. Tokens expire after 1,440 minutes (24 hours), are stored hashed by Sanctum, and the plaintext token is returned only when issued. Never log tokens or return password hashes or remember tokens.
- Configure two Sanctum guards with explicit Eloquent providers: `admin` uses `admin_users` / `AdminUser`; `frontend` uses `users` / `User`. The provider check must reject tokens belonging to the other model, even when numeric IDs or emails match. Token abilities and a URL prefix alone do not enforce this boundary.
- All admin authentication controllers, including `Admin\AuthController` and `Admin\AdminUserController`, belong in `app/Http/Controllers/Admin`. Admin login is `POST /api/admin/login`; protected admin endpoints use `auth:admin`, `abilities:admin`, and `admin.active` inside the admin route group.
- Frontend login is `POST /api/login`; protected frontend endpoints use `auth:frontend` and `abilities:frontend` outside the admin route group. Each section has `GET me` and `POST logout` endpoints. Logout revokes only the current bearer token.
- Admin tokens receive only the `admin` ability; frontend tokens receive only `frontend`. Do not accept client-supplied token abilities or issue wildcard abilities during login. Rate-limit both login endpoints by account, route, and IP, with an additional IP-wide limit.
- In `admin_users`, use `id`, `first_name`, `last_name`, nullable `mobile` (string, maximum 30 characters), unique `email`, hashed `password`, nullable `email_verified_at`, boolean `status`, boolean `is_super_user`, `remember_token`, `created_at`, and `updated_at`. `status = true` means active; it defaults to true. `is_super_user` defaults to false. Keep mobile numbers as strings to preserve leading zeros and country prefixes.
- Reject inactive admins at login and on every protected admin request. Admin-user CRUD at `/api/admin/admin-users` requires the `manage-admin-users` gate, which allows authenticated active `AdminUser` accounts regardless of `is_super_user`. Keep `is_super_user` stored, cast, validated, and exposed for future permission rules; do not use it to restrict current login or admin-user management. There is no public admin registration. Frontend users cannot manage admins, and verification timestamps cannot be set through admin-user write payloads.
- Revoke an admin's existing tokens when changing their password, email, status, or super-user flag, and when deleting the account. Clear `email_verified_at` when changing their email. Persist related changes in a transaction.
- Keep password-reset stores separate: frontend users use `password_reset_tokens`; admins use `admin_user_password_reset_tokens` with `email` as its primary key, `token`, and nullable `created_at`. Use `Password::broker('admin_users')` for admin resets and `Password::broker('users')` for frontend resets. Never use the default frontend broker for an admin. Reset endpoints and frontend reset URLs must be implemented explicitly when requested; creating the table and broker does not provide that flow.
- Select exposed account fields with `AdminUserResource` or `UserResource` and put these resources directly under `data` in `response()->json([...], $httpStatus)`, including login, logout, and exceptions. Do not introduce a response-envelope helper class.
- Configure browser client origins through the comma-separated `CORS_ALLOWED_ORIGINS` environment setting and `config/cors.php`. Bearer-token requests do not need credential cookies; keep `supports_credentials` false. Ensure CORS preflight accepts the Authorization and Content-Type headers.
- To create the initial super user, set `ADMIN_EMAIL`, `ADMIN_PASSWORD` (at least 12 characters), and optionally `ADMIN_FIRST_NAME` / `ADMIN_LAST_NAME` in deployment configuration, then run `php artisan db:seed --class=AdminUserSeeder --no-interaction`. This explicit seeder creates only a missing account and does not overwrite or promote an existing account. Do not seed fixed credentials or automatically run the admin seeder in production.
- Test these boundaries with real bearer tokens, including cross-model access, coincident IDs/emails, missing abilities, inactive accounts, expiry, revocation, access for active admins with either super-user flag, and separate reset brokers.

## Application-wide JSON response contract

Return API responses directly through `response()->json($payload, $httpStatus)`. Build the response array in the controller or exception handler; do not add a response-envelope helper class.

Successful responses and non-validation failures must contain exactly these four top-level keys, in this order:

| Key | Type | Meaning |
| --- | --- | --- |
| `status` | boolean | `true` for success, `false` for failure. |
| `data` | object, array, or null | Single resource object, list array, or `null` when no result is returned. |
| `pagination` | object or null | Pagination metadata for paginated lists; otherwise `null`. |
| `message` | string | Clear, human-readable outcome. |

Validation failures additionally contain `errors`, an object mapping field names to lists of validation messages. Omit `errors` from successful responses and other failures.

Do not alternate between `status` and `success`, change key spelling, or use strings such as `"true"` for booleans. Use snake_case for response keys. Empty lists use `data: []`; failed requests use `data: null`.

Successful single-resource response:

```json
{
  "status": true,
  "data": { "id": 1, "name": "Example" },
  "pagination": null,
  "message": "User retrieved successfully."
}
```

Paginated list response:

```json
{
  "status": true,
  "data": [{ "id": 1, "name": "Example" }],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1,
    "from": 1,
    "to": 1,
    "next_page_url": null,
    "prev_page_url": null
  },
  "message": "Users retrieved successfully."
}
```

- Build pagination metadata from Laravel's length-aware paginator, not manually calculated values. Use `paginate()` when this total-based contract is required.
- Keep records directly inside `data`; do not nest the entire paginator under `data` or add competing top-level `links` and `meta` envelopes.
- Validate `page` and `per_page` as positive integers. Default to 15 records and cap `per_page` at 100 unless the endpoint has a documented requirement.
- Use deterministic ordering with a unique tie-breaker. Preserve allowed filters in pagination links. Empty pages have `data: []` and nullable `from` and `to`.
- Use Eloquent API Resources to select and transform exposed fields, placing a single resource or `AdminUserResource::collection($adminUsers)` directly under `data` in the JSON response. Build pagination from the same `$adminUsers` paginator. Avoid double `data` wrapping and leaking internal or sensitive model attributes.

Validation failure response (HTTP 422):

```json
{
  "status": false,
  "data": null,
  "pagination": null,
  "message": "Validation failed.",
  "errors": { "email": ["The email field is required."] }
}
```

- Use correct HTTP codes: 200 for reads, updates, and deletes returning this envelope; 201 for creation; 401 for unauthenticated requests; 403 for forbidden actions; 404 for missing resources; 409 for conflicts; 422 for validation failures; 429 for rate limits; 500 for unexpected failures.
- Do not return HTTP 200 for failures. Do not send a JSON body with HTTP 204; use HTTP 200 for deletes when returning the standard envelope.
- When implementing the contract, configure API exception rendering in `bootstrap/app.php` so validation, authentication, authorization, missing-resource, and other API errors use the same envelope, even without an `Accept: application/json` header.
- Scope exception formatting to API requests and preserve relevant exception headers, including rate-limit headers. Report unexpected exceptions internally and return a safe message without stack traces, SQL, credentials, or internal paths.
- File downloads, streams, and framework health responses may use their required formats; do not wrap their bodies in JSON.

## Backend best practices

- Prefer Form Requests for write validation and authorization. Pass only intended validated fields to persistence; never mass-assign `$request->all()`.
- Protect mass assignment and sensitive fields. Use policies, gates, and route model binding; scope nested resources to their parent when appropriate.
- Use Eloquent or bound query-builder values. Allow-list client-selected sort columns, directions, and filters.
- Eager-load required relationships to prevent N+1 queries. Paginate large lists and add appropriate database indexes.
- Use database transactions for related writes that must succeed together. Enforce uniqueness and other invariants with database constraints.
- Validate uploads and use Laravel storage with generated filenames. Apply rate limits to authentication and expensive endpoints.
- Read environment values through configuration files, then use `config()` in application code. Never expose or commit secrets.
- Reuse existing abstractions. Avoid speculative repositories, helpers, services, and unnecessary dependencies.
- Follow project PHP style: braces for every control structure, descriptive names, explicit types, constructor property promotion where applicable, and useful PHPDoc array shapes.

## Verification

- For code changes, run the narrowest relevant Pest tests. Cover authorization boundaries, validation, HTTP status codes, the response envelope, and pagination where the feature requires them.
- Use factories and follow the `testing-best-practices` skill when designing tests. Do not delete tests without approval.
- After modifying PHP, run `vendor/bin/pint --dirty --format agent`.
- Inspect new API routes with `php artisan route:list --path=api --except-vendor --no-interaction`.
- After feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.
