# Project-specific AI guidelines

Always read and follow [AI_GUIDELINES.md](AI_GUIDELINES.md) before working on this project. It defines the API-only scope, admin controller and route structure, and application-wide JSON response contract. Its project-specific requirements take precedence over generic frontend guidance below.

## Multilingual Data & Translation Guidelines

These requirements govern future multilingual database and API work for Krishi AI / Bhoomitraa. Follow `AI_GUIDELINES.md` for API-only architecture, authentication, authorization, and response envelopes. This section does not authorize schema changes or converting existing resources automatically.

### Decide what needs translations

- Add translations only for platform-managed content that farmers or administrators must read and maintain in multiple languages: names, descriptions, instructions, benefits, and usage guidelines. Multiple supported languages alone do not justify a translation table.
- Possible candidates include `regions`, `crops`, `crop_varieties`, `seeds`, `fertilizers`, and `pesticides`. Inspect actual fields and business requirements first; these examples are not tables to create automatically.
- Authentication, authorization, transactional, numerical, relational, technical, and farmer-entered personal data generally need no translations. Examples include `admin_users`, `users`, `payments`, `subscriptions`, `farmlands`, `irrigation_sources`, and `pipelines`. Reassess individual fields if platform-managed multilingual content is added later; these are not absolute exclusions.
- Current project: `Category` stores `title`, `description`, and `meta_*` content in `categories`; these are candidates only if multilingual management is required. Assess `slug` separately because it is a unique API field. Keep IDs, status, image paths, and timestamps language-independent. Personal account names in `AdminUser` and `User` are not translation content.

### Database structure

- Separate each translatable resource into a master table containing only language-independent fields and a translation table containing the translatable fields. Use `{singular_model_name}_translations` in snake_case: `region_translations`, `crop_translations`, and `crop_variety_translations`.
- Example master `regions`: `id`, `parent_id`, `code`, `region_type`, `latitude`, `longitude`, `is_active`, `created_at`, `updated_at`. Example `region_translations`: `id`, `region_id`, `locale`, `name`, `created_at`, `updated_at`. These illustrate field placement, not a mandatory schema for every resource.
- Use `locale` for configured language identifiers such as `en` and `hi`. Add a foreign key to the master, a unique constraint on `(master_foreign_key, locale)` such as `(region_id, locale)`, and cascading deletion when the master is legitimately deleted. Cascading does not replace deletion authorization.
- Follow existing migration, primary-key, timestamp, and index conventions. Index translated search/sort fields when justified by actual queries; avoid redundant indexes.
- Do not add `name_en`, `name_hi`, or `description_hi` columns to master tables. Do not add manual `sort_order` columns for requirements that call for alphabetical ordering by translated name.

### Save All Translations Together

The client submits master fields and every supplied translation in one request, keyed by locale:

```json
{
  "parent_id": 1,
  "code": "RJ",
  "region_type": "state",
  "is_active": true,
  "translations": {
    "en": { "name": "Rajasthan" },
    "hi": { "name": "राजस्थान" }
  }
}
```

- Validate the complete payload before persistence. Create or update the master and supplied translations inside one `DB::transaction()` so related writes succeed or roll back together.
- Require the default translation, initially `en`, when creating a record. Validate all locale keys against a centrally configured supported-language list; do not infer support from arbitrary client input or from Laravel's default locale alone.
- Persist translations through the master's relationship with native Eloquent mechanisms such as `updateOrCreate()` keyed by `locale`. Separate intended validated master fields from validated translation fields; never mass-assign the complete nested payload or arbitrary frontend data.
- On update, preserve omitted locales and omitted fields. An omitted locale is not a deletion request. Delete translations only through an explicit, authorized, validated operation that preserves the required default translation.
- Do not introduce third-party translation packages or traits, or automatically translate content through external services.

### Models and application architecture

- Define a typed `translations(): HasMany` relationship on the master and the typed inverse `BelongsTo` relationship on the translation model. Follow existing explicit `$fillable` controls and `casts()` conventions.
- Eager-load required translations for collections to prevent N+1 queries, including translations used by API Resources and English fallback. Ordinary listings should load only the locales/fields they need; admin form responses may load all translations.
- Keep reusable locale resolution, translation lookup, fallback, and query behavior centralized where beneficial. Avoid independent implementations in every controller, speculative base models, generic translation frameworks, and unnecessary traits or service layers.
- Keep controllers focused on request orchestration, Form Requests on validation/authorization, models on relationships, and API Resources on exposed fields. Reuse existing services for complex multi-table operations when available. This project currently has no `app/Services` layer; simple transactional writes already live in controllers, so translation support alone does not require creating one.

### Locale selection and API responses

- Support an explicit requested locale using the established API convention, such as `Accept-Language` or a validated `locale` parameter. No request-locale convention or supported-language list exists currently; establish one shared convention when implementing translation support, including input precedence and handling of absent/unsupported values.
- Return the requested supported translation with English (`en`) fallback when it is unavailable. Keep language-independent fields unchanged. Share the same effective translation/fallback rules between response rendering, search, and sorting.
- Ordinary farmer/public listings expose resolved fields such as `name`, not every translation unless required. Admin detail (`show`) and create/update responses used for form editing should support all translations keyed by locale. Do not add HTML-oriented `create` or `edit` actions to API controllers.
- Preserve all existing required response fields. Build responses directly with `response()->json(...)`, putting API Resources under `data`. Successes and non-validation failures have exactly `status`, `data`, `pagination`, and `message`, in that order; validation failures additionally have `errors`. Use `pagination: null` for single resources, correct HTTP codes, and the existing paginator metadata for lists. Do not add an envelope helper or double `data` wrapping.
- Illustrative three-key translation responses that omit `pagination` conflict with the application-wide contract in `AI_GUIDELINES.md`; retain the four-key contract as shown below.

Farmer-facing single resource:

```json
{
  "status": true,
  "data": { "id": 2, "code": "RJ", "region_type": "state", "name": "राजस्थान" },
  "pagination": null,
  "message": "Region retrieved successfully."
}
```

Admin detail for form editing:

```json
{
  "status": true,
  "data": {
    "id": 2,
    "code": "RJ",
    "region_type": "state",
    "translations": {
      "en": { "name": "Rajasthan" },
      "hi": { "name": "राजस्थान" }
    }
  },
  "pagination": null,
  "message": "Region retrieved successfully."
}
```

### Search, sorting, and pagination

- Search translatable fields through their translation table using the appropriate locale and fallback. Keep existing validated `keyword` and `status` filters where applicable, bound query values, and literal wildcard escaping used by current listing endpoints.
- Sort translatable resources alphabetically by translated name (or the corresponding display field): English by default for admin lists, requested locale with English fallback for farmer-facing lists. Add master ID as a stable secondary sort. Apply filtering and ordering in the database before pagination.
- Use locale-constrained joins, subqueries, or reusable scopes that keep one result per master. Avoid duplicate masters, inflated totals, and pagination over translation rows; do not sort only the already-paginated collection.
- Reuse the current length-aware `paginate()` contract: positive `page`/`per_page`, default 15, maximum 100 unless otherwise documented, and preserved allowed filters/locale in pagination links. Do not change existing untranslated endpoint ordering as an incidental change.

### Validation, authentication, and authorization

- Use Form Requests for translation writes, following the existing separate store/update requests and array-style rules. Laravel validation remains authoritative, regardless of frontend validation.
- Validate master fields, the locale-keyed translation object structure, allowed locale keys and nested field keys, required translated fields, lengths, types, applicable uniqueness, and permitted foreign-key relationships. Require English on creation; validate supplied update fields without requiring unchanged locales to be resubmitted.
- Return HTTP 422 using the existing validation envelope and field-to-message-list `errors` object. Preserve nested field paths such as `translations.hi.name` for React Hook Form integration.
- Preserve Sanctum bearer authentication and separate admin/frontend identities. Admin mutations remain under `routes/api.php`'s admin group with `auth:admin`, `abilities:admin`, `admin.active`, and the applicable gate/policy. Authentication alone does not grant write permission.
- Scope translation writes/deletes to the authorized master relationship. Protect foreign keys and mass assignment; never allow client data to reparent translations or bypass resource permissions. Do not introduce separate multilingual authentication logic or expose credentials, SQL, or internal error details.

### Frontend and backend responsibilities

- The separate Next.js frontend uses `next-intl` for static UI labels, buttons, form labels, navigation, and frontend validation messages.
- Laravel translation tables hold dynamic administrator-managed database content, including crop/region names, descriptions, benefits, and product instructions. UI dictionaries do not replace database translations or Laravel's authoritative validation. Keep this backend API-only; no frontend assets or build changes are required here.

### Existing database and API compatibility

- Before recommending conversion of an existing resource, inspect its models, migrations, controllers, Form Requests, Resources, observers, services if present, existing records, and client contracts. Identify translatable fields separately from language-independent fields; do not assume example resources or translation infrastructure already exist.
- Document a backward-compatible migration/backfill and API rollout plan as part of an explicitly requested conversion. Preserve master IDs, foreign keys, constraints, existing content, and required response fields; backfill verified source-language content before enforcing required default translations. Never assume existing text is English without checking it.
- Use new migrations for deployed schema changes. Plan staged cutover, safe rollback or forward recovery, and any necessary client coordination before removing old content columns or changing payloads. Do not silently rewrite existing endpoints or discard untranslated data.
- This guidelines update is documentation-only: do not change application code, migrations, records, dependencies, or database tables as part of it. Any future implementation must follow the existing verification rules.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.2. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== laravel/v12 rules ===

# Laravel 12

- This project uses the streamlined Laravel 11+ structure: register middleware, exceptions, and routing in `bootstrap/app.php` and service providers in `bootstrap/providers.php`. There is no `app/Http/Kernel.php` or `app/Console/Kernel.php`, and commands in `app/Console/Commands/` auto-register.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
