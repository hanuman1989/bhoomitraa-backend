# Crop color implementation and API contract

The existing Crop Manager now accepts and returns optional `crop_color`. It is language-independent master data stored only on `crops`. The installed runtime is Laravel 12.69.3, and the implementation uses that version's APIs. No package, route, authorization rule, translation table, or architectural layer was added.

## Storage and write behavior

Migration `2026_11_15_165435_add_crop_color_to_crops_table.php` adds nullable `VARCHAR(7)` with the implicit null default and no index. Existing crops retain their IDs, content, relationships and timestamps and receive null for the new field. The migration does not assign suggested palette colors or rewrite existing records. `down()` drops only `crop_color`; rolling it back discards assigned colors, so export or back them up first if needed.

`Crop::$fillable` includes the field. Existing controller persistence already passes intended validated master attributes into `saveWithTranslations()` inside its current transactions. Consequently no CropController rewrite was needed. `UpdateCropRequest` inherits the rule from `StoreCropRequest`, retaining the project's partial PUT/PATCH behavior:

| Input | Result |
| --- | --- |
| Omitted on create | null |
| Omitted on PUT/PATCH | Existing color preserved |
| JSON null | Color cleared |
| `#FFC107`, `#4caf50`, `#aBcD09` | Accepted and stored with supplied case |
| Color names, RGB/RGBA, missing `#`, 3/8 hex digits, non-hex digits | HTTP 422 |
| Arrays, objects, numbers and booleans | HTTP 422 |

Validation uses `sometimes`, `nullable`, `string`, `max:7`, and a fully anchored six-digit HEX regex, with `bail` to avoid duplicate errors for the same value. Format/length messages use English or Hindi according to the existing `X-Locale` / `Accept-Language` selection. Other type errors retain the application's normal validation wording. Errors remain under `errors.crop_color`. Existing string trimming and empty-string-to-null middleware behavior is preserved.

Color-only updates leave translations untouched. The color is never copied to varieties, stages, seeds, farm records, translations or timeline tables. Existing mass-assignment controls, image handling, publication checks, Sanctum guards and gates are preserved.

## Existing endpoints

The write field is accepted by `POST /api/admin/crops` and `PUT`/`PATCH /api/admin/crops/{crop}`. It is returned by those actions and `GET /api/admin/crops`, `GET /api/admin/crops/{crop}`, and status-update responses. List keyword search, category filters, active filters and pagination continue to work as before; color is presentation data, not a new search/filter criterion. Existing `GET /api/admin/crops?is_active=1` responses can supply crop options. There is no dedicated crop dropdown or farmer catalogue route to extend, so no new endpoint was introduced.

Example create request; replace `category_id` with an existing category:

```json
{
  "category_id": 1,
  "slug": "mustard",
  "crop_color": "#FFC107",
  "translations": {
    "en": {"name": "Mustard"},
    "hi": {"name": "सरसों"}
  }
}
```

Example update: `{"crop_color":"#4caf50"}`. To clear: `{"crop_color":null}`. An unrelated update such as `{"scientific_name":"Brassica juncea"}` preserves the assigned color. Existing multipart crop updates continue to use POST with `_method=PATCH` or `_method=PUT`; color is an ordinary form field alongside the existing image and translation fields.

Representative detail response showing relevant data fields; all other existing resource fields remain available:

```json
{
  "status": true,
  "data": {
    "id": 1,
    "name": "Mustard",
    "slug": "mustard",
    "crop_color": "#FFC107",
    "is_active": false
  },
  "pagination": null,
  "message": "Crop retrieved successfully."
}
```

Unassigned colors are JSON null. List records remain directly under `data`, with the existing paginator metadata. English and Hindi responses expose the same color, independently of translated crop names.

## Related modules and query behavior

| Existing response | Color field |
| --- | --- |
| Crop varieties, stages, stage timelines, activity templates | `data.crop_color` |
| Crop seed rates, yield benchmarks, fertilizer recommendations | `data.crop_color` and `data.crop.crop_color` |
| Seeds | `data.crop_color` |
| Seed lots and seed prices | `data.seed.crop_color` |
| Farmer smart timeline preview | `data.selection.crop_color` |

Lists expose the corresponding path on each data row. Related resources use their existing loaded crop relationship; six controllers that previously selected only `crop:id` now also select `crop_color`. Collection tests verify one crop query for two displayed records, including nested seed/crop paths. Existing `crops.*` selections already include the new column. The published crop scope and CropResource also expose it without extra resource queries or full translation collections for ordinary consumers.

Crop categories were reviewed and retain their own unrelated category data. Crop relationships, translation models, observers, image resources, status updates and publication rules were reviewed. The smart timeline preview service already returns crop display metadata, so its selection now includes color. No farm crop-cycle, farmer dashboard, calendar, analytics, or AI crop response currently exists that requires another color mapping; those features were not added. FarmResource still has its existing unavailable active-crop placeholder.

There is no crop-specific seeder, import/export implementation, or crop response cache in this repository. No crops or palette defaults were seeded, no manually assigned colors are overwritten by seeding, and no cache is cleared. `CropFactory` defaults to null; `withColor()` generates a valid HEX value, and `withColor('#FFC107')` supplies an explicit test color.

## File inventory

New files:

- `database/migrations/2026_11_15_165435_add_crop_color_to_crops_table.php`: add/drop only the nullable color column.
- `tests/Feature/CropColorTest.php`: focused behavior, security, multilingual, migration, related-resource and query-count tests.
- `CROP_COLOR.md`: implementation report and API integration guide.

Modified files and purposes:

| File | Purpose |
| --- | --- |
| `app/Models/Crop.php` | Allow validated color assignment |
| `app/Http/Requests/Admin/StoreCropRequest.php` | Shared create/update validation and localized format messages |
| `database/factories/CropFactory.php` | Null default and opt-in valid colors |
| `app/Http/Resources/Admin/CropResource.php` | Full crop color field |
| `app/Http/Resources/Admin/CropVarietyResource.php` | Parent crop display color, preserving pre-existing variety changes |
| `app/Http/Resources/Admin/CropStageResource.php` | Stage parent crop color |
| `app/Http/Resources/Admin/CropStageTimelineResource.php` | Color through the loaded stage's crop |
| `app/Http/Resources/Admin/CropActivityTemplateResource.php` | Activity parent crop color |
| `app/Http/Resources/Admin/CropSeedRateResource.php` | Flat and nested crop color |
| `app/Http/Resources/Admin/CropYieldBenchmarkResource.php` | Flat and nested crop color |
| `app/Http/Resources/Admin/FertilizerRecommendationResource.php` | Flat and nested crop color |
| `app/Http/Resources/Admin/SeedResource.php` | Seed parent crop color |
| `app/Http/Resources/Admin/SeedLotResource.php` | Nested seed crop color |
| `app/Http/Resources/Admin/SeedPriceResource.php` | Nested seed crop color |
| `app/Http/Controllers/Admin/CropActivityTemplateController.php` | Include color in restricted crop eager load |
| `app/Http/Controllers/Admin/CropSeedRateController.php` | Include color in restricted crop eager load |
| `app/Http/Controllers/Admin/CropYieldBenchmarkController.php` | Include color in restricted crop eager load |
| `app/Http/Controllers/Admin/FertilizerRecommendationController.php` | Include color in restricted crop eager load |
| `app/Http/Controllers/Admin/SeedLotController.php` | Include color in nested restricted crop eager load |
| `app/Http/Controllers/Admin/SeedPriceController.php` | Include color in nested restricted crop eager load |
| `app/Services/FarmerSmartTimelineGenerator.php` | Expose selected crop color in preview metadata |
| `tests/Feature/CropStageControllerTest.php` | Include the added field in the existing exact response contract assertion |
| `CROP_CATEGORIES.md` | Extend existing Crop Manager API documentation |

## Deployment and frontend integration

The migration was applied successfully to the configured **local** database. Read-only verification found the column is nullable `varchar(7)` with a null default and zero indexes. All **seven** existing crops remain present and all seven new color values are null. This task applied only the new crop-color migration.

Deploy the additive column migration before serving code that reads it. To apply only this migration without applying other pending work:

```powershell
php artisan migrate --path=database/migrations/2026_11_15_165435_add_crop_color_to_crops_table.php --no-interaction
```

The standard deployment command `php artisan migrate --no-interaction` also applies other pending migrations; review them first. Non-interactive production deployments add `--force`. No `migrate:fresh` or `migrate:refresh` command is needed for this feature. Rollback removes assigned colors and must be coordinated with the application version that expects the column.

The frontend should treat `crop_color` as nullable text with a seven-character maximum, submit a six-digit HEX string, omit it to preserve the current color, and send JSON null to clear it. Existing frontend dictionaries can supply static field labels. Laravel remains authoritative for validation and nested `errors.crop_color`. UI fallback choices for null belong to the client; the API does not manufacture a default or random presentation color. No frontend or environment files are changed by this task.

## Executed verification and remaining issues

- `composer show laravel/framework` and Boost application/schema inspection confirmed Laravel 12.69.3 and the pre-existing crops table without a color column.
- Artisan generated the new migration with `make:migration add_crop_color_to_crops_table --no-interaction` and the test with `make:test CropColorTest --pest --no-interaction`.
- `php artisan test --compact tests/Feature/CropColorTest.php`: **55 passed, 335 assertions**. Coverage includes creation with upper/lower/mixed case, omission/null, invalid formats/types, PUT/PATCH replacement/clearing/preservation, unchanged translations, localized errors, protected writes, list/search/active/detail responses, publication resources, ten related modules, no additional crop queries, timeline previews, migration data preservation/rollback, and factory states.
- Final focused run of `CropColorTest`, `CropControllerTest`, `CropStageControllerTest`, and `FarmerSmartTimelinePreviewTest`: **370 passed, 1,879 assertions**.
- Final affected-module regression run: **1,449 passed, 10 failed, 7,161 assertions**, covering 14 test files. The failures are confined to the pre-existing `CropVarietyControllerTest` contracts for legacy `source_id` versus `knowledge_sources`, source titles, list translations and query projections. They reproduce the previously observed variety failures and are unrelated to color. A baseline run before color edits already reproduced the source-field mismatch. Those unrelated contracts were left intact.
- The 14-file regression command used `php artisan test --compact` with `tests/Feature/` paths for `CropColorTest.php`, `CropControllerTest.php`, `CropImageTest.php`, `CropVarietyControllerTest.php`, `CropStageControllerTest.php`, `CropStageTimelineControllerTest.php`, `CropActivityTemplateControllerTest.php`, `CropSeedRateControllerTest.php`, `CropYieldBenchmarkControllerTest.php`, `FertilizerRecommendationControllerTest.php`, `SeedControllerTest.php`, `SeedLotControllerTest.php`, `SeedPriceControllerTest.php`, and `FarmerSmartTimelinePreviewTest.php`. Its output is retained in `storage/framework/testing/crop-color-regressions.log`.
- `php vendor/bin/pint --dirty --format agent` scoped to the 24 PHP files changed for this task: **passed**. `git diff --check`: **passed**. `php artisan route:list --path=api/admin/crops --except-vendor --no-interaction` confirmed the six existing crop routes.
- `php artisan env --no-interaction` confirmed the local environment. The path-specific migration was previewed with `--pretend`, then applied successfully; schema/count queries verified nullable `varchar(7)`, null default, no index, and seven preserved crops with seven null colors.

The complete repository suite was not re-run for this field change; the affected modules were tested above. Run `php artisan test --compact` for complete-suite verification after reviewing the existing crop-variety failures. Production deployment remains a separate application rollout; only the configured local database was migrated here. Existing unrelated working-tree changes were preserved, including the ongoing crop-variety source/resource changes.
