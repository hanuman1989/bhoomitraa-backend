# Sowing / Planting Method Master API

Implemented 26 November 2026. This is an admin API module; the supplied screens are UI references.

## Conventions and implementation decisions

The module reuses the existing Seed Manager's step-wise persistence, admin bearer authentication, Form Requests, locale-keyed translation updates, one-based gallery order, generated filenames, public filesystem URLs, upload restrictions, and four-key response envelope. Multi-table operations live in the existing app/Services directory in SowingMethodManager. Nested upload rollback compensation follows CategoryObserver's existing transaction-event pattern.

Basic Details uses conventional resource store/update routes. Translations and images use Seed Manager-style step endpoints. GET detail supplies editing and review data; there is no duplicate review endpoint or progress/status column. The frontend tracks its current step and intended publication status locally.

Incomplete records are inactive drafts. Completeness is derived as is_complete plus readiness_errors. A complete inactive record is distinguishable from an incomplete inactive record without another stored status. Images are optional, so an empty image step does not prevent completion.

## Authentication, authorization and locale

Every route below requires:

- Authorization: Bearer <admin token>
- auth:admin, abilities:admin, admin.active
- manage-sowing-methods gate

Like manage-seeds, the new gate permits active AdminUser accounts with either super-user flag. Farmer tokens, inactive administrators, missing admin abilities, and denied gates cannot manage methods. There is no new role/permission table.

Existing SetLocale middleware is reused: a nonempty X-Locale header takes precedence over Accept-Language negotiation; absent or unsupported values fall back to languages.default (en). Supported content locales come from config/languages.php (en and hi). For the English-only admin interface, send X-Locale: en. All translations are always available in detail responses.

All content is plain text. Render it as text in Next.js. No rich-text handling or external translation service is introduced.

## Registered endpoints

Base: /api/admin/sowing-methods. Route names begin admin.sowing-methods.

| Method | Suffix | Route name suffix | Purpose |
| --- | --- | --- | --- |
| GET | / | index | Paginated summaries |
| GET | /options | options | Fixed option values and labels |
| POST | / | store | Step 1: create inactive Basic Details |
| GET | /{sowingMethod} | show | Full edit/review data and readiness |
| PUT or PATCH | /{sowingMethod} | update | Step 1: partial Basic Details update |
| PUT or PATCH | /{sowingMethod}/step-2 | step-two | Save supplied translations |
| POST, PUT or PATCH | /{sowingMethod}/step-3 | step-three | Upload/reorder/remove images only |
| POST | /{sowingMethod}/finalize | finalize | Step 4: validate full configuration and apply status |
| PATCH | /{sowingMethod}/status | status | Activate or deactivate |
| DELETE | /{sowingMethod} | destroy | Delete only an unreferenced method |

Identifiers are numeric model IDs, not codes/slugs. Options is registered before dynamic resource routes. No HTML create/edit routes or farmer routes are added.

## Step 1: create and update

POST /api/admin/sowing-methods:

```json
{
  "code": "line_sowing",
  "slug": "line-sowing",
  "title": "Line Sowing",
  "short_description": "Sowing in organized rows.",
  "establishment_type": "direct_sowing",
  "crop_scope": "specific",
  "crop_ids": [12, 18],
  "supports_row_spacing": true,
  "supports_plant_spacing": false,
  "supports_field_layout": null,
  "machinery_requirement": "optional",
  "labour_requirement": "medium",
  "cost_level": "medium",
  "sort_order": 10,
  "is_active": false
}
```

The example crop IDs must be replaced with eligible IDs from the existing GET /api/admin/crops lookup. Eligibility follows Crop::published(): active crop, active category, nonempty required English/Hindi names.

Required on creation: code, slug, title, establishment_type, crop_scope. title and short_description write the English translation; there are no duplicate master title/description columns. is_active defaults to false. Keep a desired active toggle in frontend state until finalization; attempting to activate an incomplete Step 1 returns 422 and rolls back creation.

Validation and defaults:

| Field | Accepted values / limits | Default |
| --- | --- | --- |
| code | <=40; lowercase letters/digits with underscore-separated segments; unique | required |
| slug | <=180; lowercase letters/digits with hyphen-separated segments; unique | required |
| title | nonempty string <=200 | required English translation |
| short_description | nullable string <=500 | null |
| establishment_type | direct_sowing, transplanting, vegetative_planting, other | required |
| crop_scope | all, specific | explicitly required at API creation; database default specific |
| crop_ids | distinct positive IDs; maximum 1000; existing eligible crops | [] for incomplete draft |
| supports_row_spacing, supports_plant_spacing, supports_field_layout | true, false, null | null |
| machinery_requirement | required, optional, not_required, varies | varies |
| labour_requirement | low, medium, high, varies | varies |
| cost_level | low, medium, high, varies | varies |
| sort_order | integer 0..65535 | 0 |
| is_active | boolean; activation requires complete configuration | false |

For crop_scope=all, crop_ids must be empty/omitted and pivots are cleared. For specific, an inactive draft may temporarily have no crops; finalization and activation require at least one. Submitted IDs must be eligible even for inactive drafts. Omitted update fields and crop IDs are preserved, except switching to all clears pivots transactionally.

PATCH /api/admin/sowing-methods/7:

```json
{
  "title": "Updated English title",
  "supports_field_layout": null,
  "sort_order": 20
}
```

PUT also supports these partial-update semantics. Unchanged unique values are accepted. A referenced code cannot change. Unknown fields are rejected, including nested translations in Step 1; use Step 2 for those.

## Step 2: translations

PATCH /api/admin/sowing-methods/7/step-2:

```json
{
  "translations": {
    "en": {
      "title": "Line Sowing",
      "short_description": "Sowing in organized rows.",
      "description": "Detailed English method information.",
      "advantages": null,
      "disadvantages": null,
      "suitable_conditions": null,
      "resource_requirements": null,
      "economic_considerations": null,
      "important_considerations": null
    },
    "hi": {
      "title": "कतार बुवाई",
      "short_description": "कतारों में बुवाई की विधि।",
      "description": "बुवाई विधि का विस्तृत विवरण।",
      "advantages": null,
      "disadvantages": null,
      "suitable_conditions": null,
      "resource_requirements": null,
      "economic_considerations": null,
      "important_considerations": null
    }
  }
}
```

Both locales require title, short_description and description before finalization/activation. A newly supplied locale requires title because the translation title is nonnullable. Existing locale updates may contain only changed fields:

```json
{"translations":{"hi":{"advantages":"प्रशासक द्वारा दर्ज किया गया विवरण।"}}}
```

Omitted locales and fields are preserved. Optional empty strings normalize to null; supplied blank titles are rejected. title limit is 200; short_description is 500; description and the six other TEXT fields have a 16,000-character limit so four-byte Unicode fits MySQL/MariaDB TEXT storage. No translation-delete operation is exposed. Unsupported locale keys, locale-list payloads, unknown nested fields and client-supplied relationship IDs return 422. The database also enforces one row per method/locale.

An active method cannot lose required content through Step 1 or Step 2. Deactivate explicitly first if an incomplete edit is intended. Failed multi-locale updates roll back every write.

## Step 3: image-only gallery

POST /api/admin/sowing-methods/7/step-3 with multipart/form-data. Let the browser set the multipart Content-Type boundary.

```text
images[front]           <JPEG file>
images[side]            <PNG or WebP file>
retain_image_ids[]      31
remove_image_ids[]      32
image_order[]          new:side
image_order[]          id:31
image_order[]          new:front
```

Example browser request:

```javascript
const form = new FormData();
form.append("images[front]", frontFile);
form.append("images[side]", sideFile);
form.append("retain_image_ids[]", "31");
form.append("remove_image_ids[]", "32");
form.append("image_order[]", "new:side");
form.append("image_order[]", "id:31");
form.append("image_order[]", "new:front");

await fetch(`${apiBase}/api/admin/sowing-methods/7/step-3`, {
  method: "POST",
  headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  body: form
});
```

Upload keys may contain letters, numbers, hyphens and underscores, length 1..50. new:<key> references that upload; id:<id> references an existing retained image.

Rules:

- Maximum eight images total, including retained images; 5,120 KiB per file.
- JPEG/JPG, PNG and WebP only. File content, guessed MIME format and filename extension are validated. Client URLs/paths are rejected.
- Omitted retain_image_ids preserves all existing images except explicit removals.
- An explicit retain list replaces the retained set; omitted owned IDs are removed. Send JSON retain_image_ids: [] to clear the entire gallery.
- An explicit retain list cannot also retain an explicitly removed ID.
- All retained/removed/reordered IDs must belong to this method.
- image_order, if supplied, must contain exactly every retained/new image once.
- Omitting image_order keeps retained images in their current order and appends new uploads in request order.
- Each save normalizes order to 1..N. The first image ordered by sort_order, id is the cover.
- An empty gallery returns cover_image: null. No cover flag, alt text, crop selection, source or image metadata is accepted.

JSON-only reorder:

```json
{"image_order":["id:33","id:31","id:34"]}
```

JSON-only removal:

```json
{"remove_image_ids":[33]}
```

Retrieve the gallery using GET detail; a separate gallery GET is unnecessary. Adding uploads while editing uses the same step endpoint.

Storage: filesystems.sowing_method_images_disk reads SOWING_METHOD_IMAGES_DISK, default public. Relative generated paths are sowing-methods/{id}/{uuid}.{guessed_extension}; only the database image column stores them. API resources expose image_url, never the internal relative path. Keep the configured disk stable for existing files. Local public storage uses the existing storage symlink/media hosting.

Existing files are removed after the outermost successful commit. New and partially written files are cleaned on failed persistence, including nested transaction rollbacks. Cleanup failures are safely logged; no durable retry queue is introduced. Process termination/storage outages can still require operational orphan-file cleanup.

## Step 4 and publication

Review: GET /api/admin/sowing-methods/7. Use translations.en for primary form values and the full locale map for language editing. crops is for review only; edit applicability in Step 1.

POST /api/admin/sowing-methods/7/finalize:

```json
{"is_active":true}
```

Use false to finalize a complete inactive method. The flag is required. Both variants revalidate all saved master fields, uniqueness, required locale content, crop applicability/eligibility, gallery count, safe paths, file existence and consecutive order in a transaction. Images themselves are optional. Finalization does not create a second record.

PATCH /api/admin/sowing-methods/7/status:

```json
{"is_active":false}
```

Activating requires completeness; deactivation preserves data and references and does not require completeness. Active edits are revalidated and rejected atomically if they would make the method incomplete. Status is never silently changed.

is_complete is derived configuration readiness, not a stored wizard completion flag. Complete data can be activated through status without a redundant finalize call. Optional image edits do not require an artificial completed-step marker.

## List and options

Example:

```text
GET /api/admin/sowing-methods?keyword=line&crop_id=12&is_active=1&sort_by=sort_order&sort_direction=asc&page=1&per_page=15
```

Allowed filters: keyword (<=150), is_active, establishment_type, crop_scope, crop_id, machinery_requirement, labour_requirement, cost_level. crop_id matches all-crop methods plus specific methods associated with that crop.

Search covers code, slug, English title and Hindi title. Percent, underscore and exclamation mark are treated literally. Filtering/sorting happen in SQL before pagination; translation rows do not inflate totals.

Sort fields: id, code, slug, title, sort_order, is_active, created_at, updated_at. Directions: asc, desc. Default: sort_order ASC, id ASC. Title sorting uses the same requested-locale/English fallback as the displayed title; the English-only admin should send X-Locale: en. ID is the stable secondary sort.

page and per_page must be positive; per_page defaults to 15 and is capped at 100. Allowed filters are preserved in paginator links. Continue sending locale headers when following links.

GET /options returns establishment_types, crop_scopes, machinery_requirements, labour_requirements, cost_levels and supported_locales. Every item has value and label; for example:

```json
{"value":"direct_sowing","label":"Direct Sowing"}
```

Actual methods are dynamic database records, not an options constant. There is no duplicate crop lookup.

## Response examples

All successes/non-validation failures use exactly status, data, pagination, message in that order. Write/detail responses use pagination: null. POST creation returns 201; other successful operations return 200.

A list summary (each row includes the shown master fields; detailed text/gallery is omitted):

```json
{
  "status": true,
  "data": [{
    "id": 7,
    "code": "line_sowing",
    "slug": "line-sowing",
    "establishment_type": "direct_sowing",
    "crop_scope": "specific",
    "supports_row_spacing": true,
    "supports_plant_spacing": false,
    "supports_field_layout": null,
    "machinery_requirement": "optional",
    "labour_requirement": "medium",
    "cost_level": "medium",
    "sort_order": 10,
    "is_active": true,
    "title": "Line Sowing",
    "crops": [{"id":12,"name":"Wheat"}],
    "cover_image": null,
    "created_at": "2026-11-26T10:00:00.000000Z",
    "updated_at": "2026-11-26T10:00:00.000000Z"
  }],
  "pagination": {
    "current_page": 1, "last_page": 1, "per_page": 15, "total": 1,
    "from": 1, "to": 1, "next_page_url": null, "prev_page_url": null
  },
  "message": "Sowing methods retrieved successfully."
}
```

GET detail returns the same master fields plus all translations, images, is_complete and readiness_errors:

```json
{
  "status": true,
  "data": {
    "id": 7,
    "code": "line_sowing",
    "slug": "line-sowing",
    "establishment_type": "direct_sowing",
    "crop_scope": "specific",
    "supports_row_spacing": true,
    "supports_plant_spacing": false,
    "supports_field_layout": null,
    "machinery_requirement": "optional",
    "labour_requirement": "medium",
    "cost_level": "medium",
    "sort_order": 10,
    "is_active": true,
    "title": "Line Sowing",
    "crops": [{"id":12,"name":"Wheat"}],
    "translations": {
      "en": {
        "title":"Line Sowing", "short_description":"Sowing in organized rows.",
        "description":"Detailed English method information.",
        "advantages":null, "disadvantages":null, "suitable_conditions":null,
        "resource_requirements":null, "economic_considerations":null, "important_considerations":null
      },
      "hi": {
        "title":"कतार बुवाई", "short_description":"कतारों में बुवाई की विधि।",
        "description":"बुवाई विधि का विस्तृत विवरण।",
        "advantages":null, "disadvantages":null, "suitable_conditions":null,
        "resource_requirements":null, "economic_considerations":null, "important_considerations":null
      }
    },
    "images": [{"id":31,"image_url":"https://media.example.test/storage/sowing-methods/7/example.jpg","sort_order":1}],
    "cover_image": {"id":31,"image_url":"https://media.example.test/storage/sowing-methods/7/example.jpg","sort_order":1},
    "is_complete": true,
    "readiness_errors": {},
    "created_at": "2026-11-26T10:00:00.000000Z",
    "updated_at": "2026-11-26T10:00:00.000000Z"
  },
  "pagination": null,
  "message": "Sowing method retrieved successfully."
}
```

Translation fields exist only in the locale map, apart from the resolved title summary. The gallery and cover refer to the same image record; there is no separate stored cover selection.

Readiness errors use field-to-message-list objects, just like validation. An incomplete inactive detail response is successful, with is_complete: false and populated readiness_errors.

HTTP 422:

```json
{
  "status": false,
  "data": null,
  "pagination": null,
  "message": "Validation failed.",
  "errors": {"translations.hi.description":["The translations.hi.description field is required."]}
}
```

A missing entire locale may produce translations.hi rather than a nested field. React Hook Form should support both group and field paths.

HTTP 409 on referenced deletion:

```json
{
  "status": false,
  "data": null,
  "pagination": null,
  "message": "Referenced sowing methods cannot be deleted. Deactivate the method instead."
}
```

Other existing envelopes: 401 Unauthenticated; 403 Forbidden; 404 Resource not found; a concurrent unique constraint violation returns 409; unexpected database/storage failures return a safe 500 response. SQL, internal paths and exception traces are not exposed.

## Schema, deployment and recovery

Exactly four new tables, 37 columns total. No existing tables or deployed migrations are changed.

| Migration | Table | Columns | Relationships / indexes |
| --- | --- | --- | --- |
| 2026_11_26_150907_create_sowing_methods_table.php | sowing_methods | 15 | unique code, slug; indexes (is_active,sort_order), (establishment_type,is_active) |
| 2026_11_26_150908_create_sowing_method_translations_table.php | sowing_method_translations | 14 | cascading method FK; unique (method,locale); index (locale,title) |
| 2026_11_26_150908_create_sowing_method_images_table.php | sowing_method_images | 6 | cascading method FK; index (method,sort_order); image column |
| 2026_11_26_150909_create_sowing_method_crops_table.php | sowing_method_crops | 2 | composite PK (method,crop); reverse index (crop,method); method cascade, crop restrict |

Master, translation and image timestamps are nonnullable DATETIME(6). Pivot has no ID/timestamps. No soft-delete, wizard, source, cost estimate or cover columns are introduced.

Environment inspection found Laravel 12.69.3, Sanctum 4.3.3, PHP 8.2.12 and local MariaDB 10.4.32 with utf8mb4_general_ci database collation. New tables inherit the existing connection's utf8mb4_unicode_ci table collation and use InnoDB; global configuration/collation was not changed. Production MySQL 8.4 was not available for a live concurrency run.

All four migrations were applied successfully to the configured local application database in batch 35. Existing crop-cycle method values remain unchanged. For another environment, apply only this module in dependency order:

```powershell
php artisan migrate --path=database/migrations/2026_11_26_150907_create_sowing_methods_table.php --no-interaction
php artisan migrate --path=database/migrations/2026_11_26_150908_create_sowing_method_translations_table.php --no-interaction
php artisan migrate --path=database/migrations/2026_11_26_150908_create_sowing_method_images_table.php --no-interaction
php artisan migrate --path=database/migrations/2026_11_26_150909_create_sowing_method_crops_table.php --no-interaction
```

Use the deployment's normal --force convention in production. A normal php artisan migrate --no-interaction also applies these migrations, but first review any other pending migrations. The separate irrigation work already present in this workspace was preserved.

Migration down methods drop only their own new tables. Reversing them discards module data; remove pivots/images/translations before the master. Prefer forward recovery after administrative content exists. No backfill or production master seeding is performed.

## Existing method-code compatibility and reference protection

Observed application data: crop cycles contain null, broadcasting and line_sowing; the inspected crop_seed_rates table contains no records. These nonnull codes fit the new identifier format. No code values were renamed or seeded.

CropSeedRate::SOWING_METHODS remains broadcasting, line_sowing, drilling, transplanting, dibbling. In particular, the legacy drilling value is not renamed seed_drilling. New master codes are dynamic, but creating a new master does not expand the legacy seed-rate validator or change seed-rate matching. That coordinated rollout is outside this task.

FarmCropCycle::sowingMethod() and CropSeedRate::sowingMethod() logically match their existing nullable sowing_method string to SowingMethod.code. There is no physical FK or new sowing_method_id. Missing masters resolve to null without changing historical strings or the wildcard meaning of null.

Reference checks include direct crop-cycle/seed-rate strings and recursively inspect sowing_method keys in crop-cycle planning_snapshot and farm-crop edit-draft draft_data, including legacy nested attributes. Rename/delete checks run with a master row lock and locking reference reads inside a transaction; JSON scans are chunked. Concurrent reference writes can wait on these reads under the configured database's locking rules, and deadlocks are retried for metadata/delete operations.

These remain legacy string references, not database-enforced integrity. Direct SQL or a later independent legacy write can still store a code without a master. Future Smart Action writes must validate and lock the selected master in their transaction. No claim of new foreign-key protection is made. JSON reference scans can be relatively expensive on large histories; deletion/code renames are administrative operations rather than listing queries.

An unreferenced method can be deleted transactionally, with dependent records cascaded and gallery files removed only after commit. Referenced methods must be deactivated instead.

Reusable SowingMethod::published()->applicableToCrop($cropId) supports a future farmer catalogue and excludes inactive/incomplete translation/applicability configurations. No farmer selection mutation, routes, UI or recommendations are exposed.

## Files and verification

New application files:

- app/Models/SowingMethod.php, SowingMethodTranslation.php, SowingMethodImage.php
- app/Http/Controllers/Admin/SowingMethodController.php
- app/Http/Requests/Admin/StoreSowingMethodRequest.php, UpdateSowingMethodRequest.php, SaveSowingMethodTranslationsRequest.php, SaveSowingMethodImagesRequest.php, UpdateSowingMethodStatusRequest.php
- app/Http/Resources/Admin/SowingMethodResource.php, SowingMethodImageResource.php
- app/Services/SowingMethodManager.php
- database/factories/SowingMethodFactory.php, SowingMethodTranslationFactory.php, SowingMethodImageFactory.php
- The four migrations listed above
- tests/Feature/SowingMethodControllerTest.php, SowingMethodImageTest.php
- SOWING_METHODS.md (this API contract/report)

Existing files changed for this module: app/Models/Crop.php, app/Models/FarmCropCycle.php, app/Models/CropSeedRate.php (relationships only); app/Providers/AppServiceProvider.php (gate and transaction listeners); config/filesystems.php (image disk); routes/api.php (admin routes); CROP_CATEGORIES.md (documentation link). Existing irrigation edits were preserved.

Test commands:

```text
php artisan test --compact tests/Feature/SowingMethodControllerTest.php tests/Feature/SowingMethodImageTest.php
php artisan test --compact tests/Feature/SeedControllerTest.php tests/Feature/CropSeedRateControllerTest.php tests/Feature/FarmCropControllerTest.php
php vendor/bin/pint --dirty --format agent
php artisan route:list --path=api/admin/sowing-methods --except-vendor --no-interaction
git diff --check
```

Tests use SQLite :memory: from phpunit.xml and fake storage; no application database records are modified. Image tests migrate an isolated in-memory database without the DatabaseMigrations teardown, because an existing crop-stage-timeline migration intentionally refuses rollback. They exercise real outermost commits and rollbacks.

Actual final verification:

- Combined run of the five listed feature files: **350 passed, 1,887 assertions**, 33.66 seconds.
- New module coverage: 75 controller tests (365 assertions) and 31 image tests (285 assertions).
- Existing SeedController, CropSeedRateController and FarmCropController coverage: 244 tests, 1,237 assertions.
- Pint --dirty --format agent completed successfully; a second scoped formatter pass reported passed.
- git diff --check passed. All ten registered routes and their admin middleware were inspected through Artisan.
- MariaDB schema inspection confirmed exactly 15 + 14 + 6 + 2 columns, the intended unique/composite indexes, method cascade constraints and restrictive crop FK. No redundant FK indexes were added by the database.
- All four local migrations completed successfully in batch 35. Application master data was not seeded or rewritten.
- No static-analysis command is configured in composer.json. The complete project suite was not run; run php artisan test --compact for that additional verification.
