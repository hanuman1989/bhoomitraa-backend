# Field Layout and Spacing Admin API

The admin module persists every wizard step, resumes saved drafts, and publishes changes only after complete server validation. It uses Laravel 12.69.3, Sanctum 4.3.3, and the application's existing JSON envelopes and locale middleware.

## Reused conventions

- Seed Manager's separate step endpoints, Form Requests, Resources, and review/finalize workflow.
- Sowing Method Manager's focused transactional manager, active master eligibility, and locale-keyed translation writes.
- Farm Crop Manager's separation of pending edits from published values. Its draft table is tied to farm crop cycles, so it cannot store field-layout drafts.
- Existing `config/languages.php`: every configured locale is required, as in Seed and Sowing Method. There is no separate required/optional-language setting in this repository.
- Existing locale resolution: `X-Locale` takes precedence over negotiated `Accept-Language`; absent or unsupported values use the configured default, currently English. Search and rendering use the requested translation with the same default fallback.
- Existing admin middleware and gate style. All endpoints require `auth:admin`, `abilities:admin`, `admin.active`, and `can:manage-crop-field-layouts`. Active admins with either super-user flag are eligible.

No dependencies, frontend files, farmer endpoints, image storage, or sample production configurations were added.

## Files

Created:

- `app/Http/Controllers/Admin/CropFieldLayoutController.php`
- `app/Http/Requests/Admin/StoreCropFieldLayoutRequest.php`
- `app/Http/Requests/Admin/UpdateCropFieldLayoutRequest.php`
- `app/Http/Requests/Admin/SaveCropFieldLayoutConfigurationRequest.php`
- `app/Http/Requests/Admin/SaveCropFieldLayoutTranslationsRequest.php`
- `app/Http/Requests/Admin/FinalizeCropFieldLayoutRequest.php`
- `app/Http/Resources/Admin/CropFieldLayoutResource.php`
- `app/Models/CropFieldLayout.php`
- `app/Models/CropFieldLayoutTranslation.php`
- `app/Services/CropFieldLayoutOptions.php`
- `app/Services/CropFieldLayoutManager.php`
- `database/factories/CropFieldLayoutFactory.php`
- `database/factories/CropFieldLayoutTranslationFactory.php`
- `tests/Feature/CropFieldLayoutControllerTest.php`
- The four migrations below and this module reference.

Modified:

- `routes/api.php`: register the ten module routes.
- `app/Providers/AppServiceProvider.php`: register `manage-crop-field-layouts`.
- `app/Services/SowingMethodManager.php`: return the existing deletion conflict when a layout references the method, before hitting the new restrictive foreign key.

Existing unrelated workspace changes were retained.

## Database

Migrations, applied successfully to the local database on 27 November 2026:

1. `2026_11_27_043913_create_crop_field_layouts_table.php`
2. `2026_11_27_043914_create_crop_field_layout_crops_table.php`
3. `2026_11_27_043914_create_crop_field_layout_translations_table.php`
4. `2026_11_27_043915_add_wizard_draft_to_crop_field_layouts_table.php`

The first three match the approved base tables. The fourth adds the explicitly approved nullable JSON `wizard_draft` column. No existing migration was rewritten. No `farm_crop_layouts` table was created.

The actual database is MariaDB 10.4.32. Schema inspection confirmed InnoDB, `utf8mb4_unicode_ci`, unsigned bigint foreign keys, `DATETIME(6)`, validated JSON storage, the required indexes, translation uniqueness, and the composite crop pivot primary key. Layout deletion cascades to its translations and crop associations; crop, variety, and sowing-method references restrict deletion. MySQL 8.4 was not available for a runtime test.

The three local tables were empty after migration verification. Synthetic configurations exist only in isolated tests and factories.

Deploy these migrations before serving the new application code. Rollback drops module data and pending drafts; preserve that data before any rollback and prefer forward recovery for populated deployments.

## Routes and controller methods

All paths start with `/api/admin/crop-field-layouts`.

| Method | Suffix | Controller method |
| --- | --- | --- |
| GET | root | `index` |
| GET | `/options` | `options` |
| POST | `/step-1` | `storeBasicDetails` |
| GET | `/{id}` | `show` |
| PUT or PATCH | `/{id}/step-1` | `updateBasicDetails` |
| PUT or PATCH | `/{id}/step-2` | `updateLayoutConfiguration` |
| PUT or PATCH | `/{id}/step-3` | `updateTranslations` |
| GET | `/{id}/review` | `review` |
| POST | `/{id}/finalize` | `finalize` |
| DELETE | `/{id}` | `destroy` |

`index`, `show`, and `destroy` use `apiResource`; custom wizard actions have explicit routes. Static paths precede route model binding. Resume uses `show` or `review`; no separate resume route is needed.

The options response supplies scope, farming-type, growing-system, language, and layout definitions. Every layout definition has a stable value, label, configuration version, preview key, and field definitions with types, bounds, units, required conditions, and select options. It also links to existing crop, crop-variety, and sowing-method index endpoints rather than duplicating them. Crop varieties can be queried through the existing endpoint using `crop_id`; its active filter is `status`. Sowing methods expose applicability and accept `crop_id` and `is_active` filters. Backend eligibility remains authoritative.

## Persistent wizard and edit drafts

A new record saves actual basic fields and crop associations at Step 1, actual configuration at Step 2, and actual translation rows at Step 3. It remains inactive. `wizard_draft` holds completed-step flags, requested active status, and a revision counter. Step 2 completion is explicit, including for a valid empty configuration object.

For an already finalized record, the first step save creates an edit snapshot inside `wizard_draft.data`. Subsequent saves merge into that snapshot. Published master fields, associations, configuration, translations, and active status remain unchanged. `show` exposes published data at the top level and pending edits under `draft`; `review` displays the effective pending data. Reads never save or create a draft.

Changing layout type clears incompatible configuration and invalidates Step 2 while preserving translations. Changes to applicability preserve configuration and are revalidated before publication. Omitted translation locales and fields are preserved, including saved locales subsequently removed from configuration. Deleting translations is not part of these endpoints.

Successful saves increment `wizard.revision`. Updates and finalization optionally accept `draft_revision`; a mismatch returns HTTP 409 without writes. Clients should submit the revision returned by their last read/save when protecting against concurrent saves within a pending draft. This is a draft counter, not a permanent revision history across finalization cycles.

Finalization locks the master, validates saved steps and current master eligibility, atomically applies the pending values and requested status, then clears the draft. Repeating a completed finalization does not create rows. Failed validation leaves published data and saved progress intact.

## Example wizard requests and responses

Send `Authorization: Bearer <admin-token>` and `Content-Type: application/json`. Example IDs represent server-returned IDs. The response examples below select relevant resource fields for brevity; actual resources include the related data, labels, definitions, translations, and timestamps described above. All four envelope keys are retained.

### Step 1

`POST /api/admin/crop-field-layouts/step-1`

```json
{
  "crop_scope": "all",
  "crop_variety_id": null,
  "sowing_method_id": null,
  "farming_type": "open_field",
  "growing_system": "soil_based",
  "layout_type": "random_distribution",
  "sort_order": 0,
  "is_active": false
}
```

HTTP 201 response excerpt:

```json
{
  "status": true,
  "data": {
    "id": 42,
    "layout_type": "random_distribution",
    "configuration": {},
    "is_active": false,
    "wizard": {
      "completed_step": 1,
      "completed_steps": [1],
      "is_finalized": false,
      "has_pending_edit": false,
      "requested_is_active": false,
      "revision": 1,
      "can_finalize": false
    }
  },
  "pagination": null,
  "message": "Field layout basic details saved successfully."
}
```

Revisit the same record with `PATCH /api/admin/crop-field-layouts/42/step-1`, for example `{"sort_order":10,"draft_revision":1}`. Do not POST another initial record when resuming.

### Step 2

`PUT /api/admin/crop-field-layouts/42/step-2`

```json
{
  "configuration": {},
  "reference_note": "Synthetic API example.",
  "draft_revision": 1
}
```

HTTP 200 response excerpt:

```json
{
  "status": true,
  "data": {
    "id": 42,
    "configuration": {},
    "configuration_version": 1,
    "reference_note": "Synthetic API example.",
    "wizard": { "completed_step": 2, "revision": 2, "can_finalize": false }
  },
  "pagination": null,
  "message": "Field layout configuration saved successfully."
}
```

The layout type comes from the saved record or edit snapshot. Do not send `layout_type`, a global measurement unit, configuration version, preview images, or wizard metadata. Send an object, including `{}` for random distribution; a JSON array is rejected.

### Step 3

`PUT /api/admin/crop-field-layouts/42/step-3`

```json
{
  "draft_revision": 2,
  "translations": {
    "en": {
      "title": "Example random layout",
      "description": "Synthetic configuration for demonstrating the API.",
      "important_considerations": "Verify crop-specific recommendations separately."
    },
    "hi": {
      "title": "उदाहरण व्यवस्था",
      "description": "एपीआई का उदाहरण दिखाने के लिए विवरण।",
      "important_considerations": null
    }
  }
}
```

HTTP 200 response excerpt:

```json
{
  "status": true,
  "data": {
    "id": 42,
    "translations": {
      "en": { "locale": "en", "language_name": "English", "title": "Example random layout" },
      "hi": { "locale": "hi", "language_name": "हिन्दी", "title": "उदाहरण व्यवस्था" }
    },
    "wizard": { "completed_step": 3, "revision": 3, "can_finalize": true, "readiness_errors": {} }
  },
  "pagination": null,
  "message": "Field layout translations saved successfully."
}
```

All currently configured locales need a title and description for Step 3 to complete. Subsequent partial saves may omit already persisted locales and fields. Adding another configured language automatically makes it available and required; no two-language limit is imposed.

### Step 4

`GET /api/admin/crop-field-layouts/42/review` returns `id`, `basic_details`, `layout_configuration`, `translations`, and `wizard` under `data`, with message `Field layout review retrieved successfully.`. The sections include selected relation names, requested status, configuration values, units, field definitions, locale display names, readiness errors, and `can_finalize`.

`POST /api/admin/crop-field-layouts/42/finalize`

```json
{ "draft_revision": 3 }
```

HTTP 200 response excerpt:

```json
{
  "status": true,
  "data": {
    "id": 42,
    "is_active": false,
    "draft": null,
    "wizard": {
      "completed_step": 4,
      "completed_steps": [1, 2, 3, 4],
      "is_finalized": true,
      "has_pending_edit": false,
      "requested_is_active": false,
      "revision": 0,
      "can_finalize": true,
      "readiness_errors": {}
    }
  },
  "pagination": null,
  "message": "Field layout saved successfully."
}
```

Publication status is requested through Step 1. Finalization accepts no status override. To deactivate a referenced complete layout, save `is_active: false` through Step 1 and finalize the valid edit.

## Configuration definitions

All persisted distances are metres. The client converts display centimetres or metres before submission; the backend performs no second conversion. Counts are whole numbers from 1 through 65,535, except multi-row beds require at least two rows. Positive distances have technical bounds of 0.000001 through 1,000,000 metres. These are input/storage limits, not crop recommendations. Path width may be zero.

| Layout value | Configuration fields |
| --- | --- |
| `random_distribution` | Empty object |
| `continuous_row` | `row_spacing_m` |
| `individual_row` | `row_spacing_m`, `plant_spacing_m` |
| `square` | `planting_distance_m` |
| `rectangular` | `row_spacing_m`, `plant_spacing_m` |
| `raised_bed_multi_row` | `bed_width_m`, `path_width_m`, `rows_per_bed`, `row_spacing_m`, optional `planting_pattern`, conditional `plant_spacing_m` |
| `pole_based` | `support_row_spacing_m`, `support_spacing_m`, `plants_per_support` |
| `trellis_based` | `row_spacing_m`, `plant_spacing_m` |
| `rack_shelf` | `growing_unit_type` (`bag`, `tray`, `block`), `levels_per_rack`, `units_per_level` |
| `hanging_bag` | `growing_unit_type` (`bag`), `units_per_support` |
| `floor_bed` | `bed_width_m`, `path_width_m` |
| `channel_based` | `sites_per_channel` |
| `grow_bag`, `container` | `plants_per_container` |
| `vertical_tower` | `sites_per_tower` |
| `tray` | `cells_per_tray` |

For multi-row raised beds, omitted `planting_pattern` defaults to `individual_plants`, requiring plant spacing. Explicit `continuous_row` permits omitting plant spacing and rejects supplying it. Rows must fit: `(rows_per_bed - 1) * row_spacing_m <= bed_width_m`. This explicit pattern field resolves the specification's need to distinguish continuous rows from individual planting positions.

The following catalog types remain unavailable because neither the supplied specification nor the repository defines their complete configuration behavior: `paired_row`, `grouped_row`, `flat_bed`, `raised_bed_single_row`, `ridge_furrow`, `trench`, `triangular`, `quincunx`, `contour`, `bower`, `floating_raft`, and `custom`. They are reported in `deferred_layout_types` and rejected on writes. No scientific definitions or engineering formulas were invented for them.

## Validation and listing behavior

- Step 1 validates supported values, integer order from 0 through 65,535, boolean requested status, distinct crop IDs, and current crop/category/translation availability. Specific scope requires at least one crop. All scope clears crop associations and variety.
- A variety must be active, belong to the single selected crop, and be absent for multiple crops. Optional sowing methods must be eligible active methods; restricted methods must apply to every selected crop and cannot be used for all scope.
- Step 2 rejects unknown keys, wrong structure, missing applicable fields, negative or zero positive-only values, nonnumeric/nonfinite or out-of-bounds input, fractional counts, incompatible planting patterns, and rows that do not fit. Reference notes are nullable text up to 500 characters.
- Step 3 validates configured locale keys and allowed nested field keys. Titles are at most 200 characters; descriptions and considerations are at most 16,000 characters, matching the existing Unicode-safe TEXT convention. Titles and descriptions are required for configured languages. Writes use relationship-scoped `updateOrCreate` and never reparent translations.
- Step 4 repeats all relevant validations against durable data and current master records. Missing steps or invalid content return 422. Invalid step progression and submitted stale draft revisions return 409.
- Unknown/protected fields are rejected. Configuration version and wizard state are controlled internally. Validation failures use nested field paths and lists of messages in the existing 422 envelope.
- Listing accepts `crop_id`, `sowing_method_id`, `layout_type`, `farming_type`, `keyword`, `is_active`, `page`, and `per_page`. Crop filtering includes applicable all-crop records; the other three required filters match exact values. OR conditions are grouped. Search uses resolved titles and crop names with bound parameters and literal wildcard escaping.
- Order is `sort_order ASC, id ASC`. Pagination defaults to 15, caps at 100, preserves validated filters, and uses the existing length-aware metadata. Required related data is eagerly loaded; list translations load the requested and default locales and relevant fields.
- Unreferenced deletion removes owned rows through cascades. Implemented external foreign-key references are checked before deletion; restrictive constraint conflicts return 409. No query assumes the future farmer layout table exists.

## Verification results

Executed successfully:

```text
php artisan test --compact tests/Feature/CropFieldLayoutControllerTest.php
125 passed, 564 assertions

php artisan test --compact tests/Feature/CropFieldLayoutControllerTest.php tests/Feature/SowingMethodControllerTest.php tests/Feature/SeedControllerTest.php
280 passed, 1288 assertions

php vendor/bin/pint --dirty --format agent
passed

php artisan route:list --path=api --except-vendor --no-interaction --json
php artisan route:list --path=api/admin/crop-field-layouts --except-vendor --no-interaction
10 module routes confirmed

git diff --check
passed
```

Feature tests use the configured isolated SQLite in-memory database. They cover real bearer authentication, module authorization on every route, the response envelope, missing records, applicability, configuration types, persistence/resume, empty configurations, language changes, publication isolation, repeated saves, stale pending revisions, filters, pagination, search/fallback, query count, deletion cascades, and reference protection. The MariaDB checks verified migration application and actual schema constraints; they do not constitute a MySQL 8.4 runtime or concurrent-load test.

No implementation approvals remain pending. The 12 undefined configuration types require their field contracts before they can become selectable. The complete application suite remains to be run with `php artisan test --compact`.
