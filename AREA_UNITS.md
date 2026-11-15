# Farm area units and API rollout

`config/area_units.php` is the source for supported unit codes, Hindi/English labels and fixed square-metre factors. `AreaHelper` reuses the installed Brick Math decimal implementation; no package or conversion table is added. Regions continue to describe locations and have no role in conversion.

## Measurement standards

| Code | Square metres per unit |
| --- | ---: |
| `bigha_kaccha` | 1618.74256896 |
| `bigha_pakka` | 2529.285264 |
| `hectare` | 10000 |
| `acre` | 4046.8564224 |
| `sqm` | 1 |
| `sqft` | 0.09290304 |
| `sqyd` | 0.83612736 |
| `guntha` | 101.17141056 |

These Bigha factors are application standards, not universal Indian definitions. The product owner confirmed **27,225 square feet = 2529.285264 square metres** for Pakka Bigha during implementation, correcting the originally proposed 2529.28548 factor. Kaccha Bigha corresponds exactly to 17,424 square feet. Introduce a new unit code for any subsequently different measurement standard; never reinterpret existing saved units.

The helper provides `units()`, `supportedUnits()`, `isSupported()`, `factor()`, `toSquareMeters()`, `fromSquareMeters()` and `convert()`. `canonicalUnit()` additionally resolves verified legacy aliases. Invalid, negative, missing or unsupported inputs return null. Zero is valid for empty totals. Multiplication preserves exact decimals; division retains 12 decimal places, and only persisted/displayed values are rounded to their required scale.

## API changes

All endpoints retain the `status`, `data`, `pagination`, `message` envelope and existing Account bearer authentication (`auth:user`, frontend ability, active user). Validation failures also expose `errors` with field names.

- `GET /api/account/area-units` returns `data.default_unit` and `data.units`, an array of `{value, label, label_hi, square_meter_factor, definition?}` from configuration. Use these options in profile and farm forms.
- Farm create/detail/list/update responses retain existing fields and add `area_sqm`. Both `area_sqm` and `actual_area_sqm` are decimal strings calculated by Eloquent accessors, or null if unavailable. `area` remains a three-decimal string; `actual_area` is now an eight-decimal string. Both square-metre attributes are explicitly serialized by `FarmResource`, without global model appends, database queries or spatial calculations.
- Farm and profile write requests accept the eight canonical unit codes above. New writes reject `ha`, generic `bigha`, `biswa`, `metric` and `imperial`. Client-supplied actual/calculated measurements are excluded from validated persistence as before.
- An area-and-unit farm PATCH is an explicit recorded-measurement correction. An area-only PATCH changes the recorded number in its existing unit. A unit-only PATCH converts the existing physical measurement and rounds to the recorded column's three decimals. Unsupported source units, overflow, or a conversion that rounds to zero return 422; submit an explicit corrected area and unit after confirming the measurement. Recorded conversions inevitably have the quantization of `DECIMAL(12,3)`.
- A farm unit change recalculates actual area from the unchanged saved boundary when present, otherwise from its existing high-precision measurement. Boundary add/replace/removal preserves government-record area. Actual measurements use the selected supported farm unit; boundaries of unresolved legacy farms use the configured default unit. Removal clears actual values, source and GPS accuracy while preserving the existing marker-coordinate behavior.
- Profile preference edits update only the profile. They do not rewrite farms, boundaries or crop measurements. New profiles default to the configured hectare standard.

## Summary

`GET /api/account/farms/summary` still counts all owned active and inactive farms, independently of list pagination and list filters. It groups recorded areas by their original unit in SQL without loading geometry, converts through square metres with `AreaHelper`, sums exact decimals, and converts into the profile preference. Actual geometry area is never mixed into the recorded total.

`total_land_area` now consistently contains:

```json
{
  "value": "11.907",
  "unit": "bigha_pakka",
  "area_sqm": "30117.141056",
  "basis": "government_record",
  "conversion_available": true,
  "unresolved_farms_count": 0,
  "preference_resolution_required": false,
  "breakdown": [
    {"value": "4.000", "unit": "bigha_pakka", "conversion_available": true},
    {"value": "2.000", "unit": "hectare", "conversion_available": true}
  ]
}
```

The example follows the confirmed factors. Summary values have three decimals and total square metres six decimals. Existing sibling fields `total_land_area_sqm`, `area_totals` and `area_conversion_unavailable` remain available. `area_totals` now groups only by unit, without region IDs. On unresolved recorded units, `total_land_area` remains an object with null `value` and `area_sqm`, false `conversion_available`, an unresolved farm count and the complete original-unit breakdown. No partial total is exposed. Empty farms return zero in the supported preferred/default unit.

The only verified legacy alias is `ha` → `hectare`, established by the old Farm conversion implementation. Existing records/preferences are retained. Generic `bigha` never maps to a variant or acquires a regional factor. Neither `metric` nor `imperial` had established behavior in the inspected application. These preferences and any other unsupported preference fall back to the configured default with `preference_resolution_required: true`. A missing profile uses the default without requiring resolution.

## Database upgrade and recovery

Deploy migration `2026_11_15_135002_centralize_farm_area_measurements.php` with the application changes. Original migrations remain unchanged. The final schema keeps `area DECIMAL(12,3)`, expands nullable `actual_area` from `DECIMAL(12,3)` to `DECIMAL(18,8)`, preserves the unit/source/location/geometry fields, updates the profile default, and removes redundant square-metre columns. No tables or regional conversion relationships are introduced.

Before migration, take a database backup and place farm/profile writers in maintenance mode. Coordinate the response and accepted-code changes with clients. The migration preflights all legacy measurements before schema changes, increases precision, backfills from independent stored square metres, replaces boundary constraints/triggers, then drops redundant columns. MySQL/MariaDB DDL is not transactional, so keep writes stopped throughout and use forward recovery or the backup if DDL fails.

The old `actual_area_sqm` contains independently calculated geometry measurements, while `actual_area` previously retained only three decimals. The migration uses the former as authoritative for actual area. It preserves the old actual unit only when conversion to eight decimals reconstructs the old six-decimal square metres exactly. Otherwise it stores that physical measurement in `sqm` at eight decimals. This can change the representation of historical actual area but preserves its full stored measurement; it never infers or changes recorded Bigha units. Boundaries and timestamps remain unchanged. Measurements that cannot fit without precision loss stop the migration before DDL. An unexpected physical `area_sqm` column is removed only if its values agree with supported recorded measurements; conflicts/unresolved values stop the migration.

Rollback is supported for an empty farms table, restoring the original precision, square-metre column, boundary checks and profile default. Populated farm measurements require forward recovery: rolling back to three decimal places would truncate new actual measurements, and original redundant data cannot be reconstructed exactly for every row. `down()` refuses that destructive rollback before changing schema or records. Restore the pre-upgrade backup with the previous application version for an exact populated-data rollback, or deploy a forward-fix migration.

Read-only inspection found one existing `bigha` recorded farm and one `ha` recorded farm, both with independent actual square metres, and two `acre` profile preferences. No development or production records are automatically reclassified by this implementation; the migration must be run as part of the coordinated deployment.

## Files and verification

Created: the upgrade migration, this document, and `tests/Feature/AreaHelperTest.php`, `FarmAreaConversionTest.php`, and `FarmAreaMigrationTest.php`.

Updated existing working-tree files: `config/area_units.php`, `config/farms.php`, `app/Helpers/AreaHelper.php`, `app/Models/Farm.php`, `app/Models/FarmerProfile.php`, Account `FarmController`/`UserController`, Account `StoreFarmRequest`/`UpdatePersonalDetailsRequest`, `FarmResource`, `FarmFactory`/`FarmerProfileFactory`, `routes/api.php`, and existing farm/spatial/profile/registration tests. Other pre-existing user changes are outside this enhancement.

Focused tests cover decimal conversions, dynamic attributes without queries/columns, unit metadata and authentication, farm writes/corrections/conversions, mixed summaries, legacy failures, precision-preserving migration, profile isolation, manual/GPS boundaries and removal. Existing account/admin authentication regressions remain applicable. Native spatial testing uses the existing opt-in isolated temporary MySQL/MariaDB database test (`FARM_SPATIAL_DATABASE_TESTS=1`), never the live application database.

Executed verification:

- Focused area/farm/profile/registration/account and admin authentication run: **396 passed, 2,548 assertions**.
- Migration and category observer regression run after adding guarded empty-schema rollback: **23 passed, 182 assertions**. The final migration-only run passed **7 tests, 106 assertions**.
- Native spatial upgrade, empty-schema rollback, historical measurement preservation, SRID, constraints and concurrent creation on **MariaDB 10.4.32**: **1 passed, 77 assertions**. MySQL 8.4 was not available for native execution in this environment.
- Final complete suite, `php artisan test --compact`: **2,739 passed, 16 failed, 6 skipped; 14,181 assertions**. All area-related tests passed. The skipped opt-in spatial test was separately executed successfully above; other opt-in tests retain their existing skip behavior.
- The remaining full-suite failures are outside this enhancement: `AdminUserPasswordTest` (1: existing 8-character rule versus expected 12), `CategoryControllerTest` (2: multipart requests returning 200 versus expected 422), `CropCategoryPublicationTest` (3: locale sorting/fallback), and `CropVarietyControllerTest` (10: existing source, translation and query contracts). Those application/test files were not changed for this enhancement; crop-variety application edits already existed in the working tree. The admin password failure also reproduces in isolation.
- Laravel Pint completed successfully on changed PHP files using `--dirty --format agent`; route inspection verified the authenticated area-units route, and `git diff --check` passed.

The full-suite output is retained locally in `storage/framework/testing/area-full-suite.log`. Re-run `php artisan test --compact` after resolving the existing failures. The application database was inspected read-only; the new migration was exercised only in isolated test databases and still needs to be applied during coordinated deployment.
