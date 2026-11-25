# Farmer Crop Manager implementation

> **Revision (2026-11-23): three-step Add/Edit Crop wizard.** The wizard is now
> 1. Crop Selection (farm, season, crop, variety, **sowing date**), 2. Select Boundary, 3. Review & Save.
> Sowing method, seed, soil assessment and fertilizer planning are no longer collected, validated or written
> by the wizard; they move to the Farmer Crop Timeline. The `step-3`, `step-4`, `step-5`,
> `seed-recommendation` and `fertilizer-recommendation` endpoints were removed, and their legacy inputs are
> rejected with 422 on Step 1. No columns or tables were dropped: existing seed, soil, fertilizer and yield
> records stay intact, the wizard never creates or deletes them, and the soil-report download endpoints remain.
> `wizard_progress` uses `crop_selection`, `boundary` and `review_save`; progress saved by the former six-step
> wizard is normalized on read (`FarmCropCycle::normalizeProgress()`), and its seed/soil/fertilizer keys are
> ignored. Sections below that describe Steps 3–5, expenses or expected yield record the former behaviour.

Implemented for the installed Laravel 12.69.3 / Sanctum 4.3.3 application. No dependencies, frontend assets, timeline generation, notifications, actual expenses, or harvest-completion workflow were added.

## Status and verification

- Final focused verification: **325 passed, 2,123 assertions**, including 60 new crop-manager cases and the existing Farm Manager, area-conversion, and yield-benchmark controller tests.
- Native spatial/concurrent-request verification: **1 passed, 71 assertions; 1 MySQL-specific test skipped** on MariaDB. Two simultaneous overlapping activations produce HTTP 200 and 409, with one active crop.
- Full regression run: **2,856 passed, 16 failed, 8 skipped**. All 16 remaining failures were reproduced on the committed HEAD baseline using the same installed dependencies; none are crop-manager regressions. They concern AdminUserPasswordTest (1), CategoryControllerTest multipart updates (2), CropCategoryPublicationTest (3), and CropVarietyControllerTest (10). They were left unchanged to respect the task's scope.
- Laravel Pint and `git diff --check` pass. All 32 new API routes were inspected through Artisan.
- Native spatial tests use an isolated temporary database and separate HTTP worker processes.
- MySQL 8.4 topology tests are included. The available development server is MariaDB 10.4.32, so MySQL-specific holes and multiple-parcel verification requires the target server.
- Existing pending crop recommendation flag changes were preserved.
- Both new migrations were applied successfully to the local application database in migration batch 28. Existing master records were not changed or seeded.

## Implemented behavior

1. Owner-scoped listing, summaries, and an unpaginated map. Active crops alone contribute to current cultivated area.
2. One crop-cycle ID across all wizard steps, resumable drafts, separate step methods, server-controlled progress, and optimistic revisions.
3. UUID `Idempotency-Key` on creation, scoped to the authenticated farmer. Reusing a key with a different selection returns 409.
4. Full farm boundary, manual drawing, GPS-reviewed geometry, and a manual-area fallback for unmapped farms.
5. Multiple active crops with non-overlapping geometries. Shared edges are allowed; drafts do not occupy land.
6. Farm-row locking before crop/edit locks and current locking reads for occupancy. Activation rechecks containment and overlap.
7. Catalogue, own, local, other, and unknown seed sources; optional verified seed lots; separate recommended, planned, and purchased quantities.
8. Live region-aware seed requirements, current package prices, exact decimal package rounding, and verified maturity date ranges.
9. Basic/unknown soil assessment, laboratory measurements with their recorded units, optional private reports, replacement/removal, and authorized downloads.
10. Region-aware fertilizer nutrient requirements, multiple manually planned products, regional package costs, and explicit unavailable product-allocation suggestions.
11. Complete review, known/unknown/skipped expense states, and region-aware benchmark yield estimates.
12. Status-only activation, accepted snapshots, retry-safe finalization, and one accepted yield record per successful finalization.
13. Separate active-crop edit drafts. Live cultivation remains unchanged until final save; edits can be discarded.
14. Draft deletion and file cleanup; active crop cancellation preserves history and releases occupancy.
15. Parent farm containment/deactivation safeguards and multiple active crop summaries in Farm Manager responses.

## Database

### Existing tables reused

`users`, `farmer_profiles`, `farms`, `regions`, `region_translations`, `seasons`, `season_translations`, `crop_categories`, `crop_category_translations`, `crops`, `crop_translations`, `crop_varieties`, `crop_variety_translations`, `crop_variety_sources`, `knowledge_sources`, `knowledge_source_translations`, `knowledge_source_attachments`, `seeds`, `seed_translations`, `seed_images`, `seed_lots`, `seed_prices`, `seed_price_regions`, `crop_seed_rates`, `crop_seed_rate_regions`, `fertilizers`, `fertilizer_translations`, `fertilizer_images`, `fertilizer_nutrients`, `fertilizer_recommendations`, `fertilizer_recommendation_nutrients`, `fertilizer_recommendation_regions`, `crop_yield_benchmarks`, and `crop_yield_benchmark_regions`.

### New migrations and tables

- `database/migrations/2026_11_16_112940_create_fertilizer_pricing_tables.php`: `fertilizer_prices`, `fertilizer_price_regions`.
- `database/migrations/2026_11_16_112941_create_farmer_crop_manager_tables.php`: `farm_crop_cycles`, `farm_crop_soil_assessments`, `soil_tests`, `farm_crop_fertilizer_plans`, `farm_crop_yield_estimates`, `farm_crop_edit_drafts`.

The crop-cycle tables did not already exist. No one-active-crop generated-column restriction existed to remove. The new cycles have an index on `(farm_id, status)` and no single-active-crop uniqueness constraint.

Area values remain unit-based; no physical square-metre columns were added. MySQL geometry is `MULTIPOLYGON SRID 4326`. Migrations use existing precision, microsecond timestamp, and foreign-key conventions. SQLite is supported for non-spatial feature tests; it cannot authorize spatial allocations.

Rollback drops the new tables and their planning records. After deployment with farmer data, prefer a forward recovery over rolling these migrations back. No existing master tables or legacy content columns are removed.

### Relationships

- Farm: crop cycles and active crops.
- FarmCropCycle: farm, crop, variety, season, seed, seed lot, soil assessments, soil tests, fertilizer plans, yield estimates, and one edit draft.
- Soil assessments/tests/plans/estimates/edit drafts: typed inverse cycle relationships.
- SoilTest: farm.
- FertilizerPrice: fertilizer, knowledge source, and regions through the regional pivot.
- Fertilizer / Region / KnowledgeSource: fertilizer-price relationships.
- Existing seed, fertilizer recommendation, yield, and regional relationships are reused.

## Regional rules

The actual existing `region_scope` values are **`all`** and **`specific`**.

- Use the saved cycle's farm, never the farmer profile, for authoritative calculations.
- Resolve tehsil/subdistrict, district, state, or the existing structured farm region, then traverse valid parents.
- Validate separately stored location IDs and reject broken/cyclic/inconsistent hierarchy context. Such farms receive only explicitly general records.
- A requested `region_id` must belong to that farm's hierarchy; it cannot replace the hierarchy.
- Filter incompatibilities before ranking. Optional NULL fields are generic; unknown conditions only match generic fields.
- Rank exact region, nearest parent, broader parent, then `all`; within a regional tier prefer compatible specific variety/seed/season/conditions.
- Prefer newer reference years when otherwise equivalent. Yield selection prefers district/state production averages over experimental potential at an otherwise equivalent tier.
- Conflicting top-ranked quantities return alternatives and `ambiguous_recommendation`; they are never summed.
- A `specific` row without pivots or an unknown scope never becomes general.
- Prices must match the product, currency, supported units, active state, farm hierarchy, and today's planning date: `effective_from <= today` and no end date or `effective_to >= today`.
- Seller, price type, package, and currency alternatives remain separate. A price from an unrelated region is rejected.
- Saved selections are checked again before activation. Changed/ineligible prices or recommendations require review.
- Finalized historical snapshots remain accepted historical estimates.

## API conventions

All routes below are under `/api/account` and use the existing `auth:user`, `abilities:frontend`, and `user.active` middleware.

Locale precedence remains the existing application convention: `X-Locale`, then supported `Accept-Language`, then English fallback. Search covers English and Hindi names.

All paginated endpoints support:

| Parameter | Contract |
| --- | --- |
| search | Optional literal search string, maximum 150 characters |
| page | Positive integer |
| per_page | 1–100, default 15 |
| sort_by | Endpoint-specific allowlist below |
| sort_direction | asc or desc |

Success and non-validation errors have exactly `status`, `data`, `pagination`, `message`. Validation errors also have a field-to-message-list `errors` object. Lists return length-aware pagination; single resources use `pagination: null`.

### Catalogue endpoints

Every catalogue controller implements `getList()`. No farmer-facing master CRUD was added.

| GET path | Additional filters | Allowed sort_by |
| --- | --- | --- |
| /regions | parent_id, region_type | name, code, id |
| /seasons | — | name, slug, id |
| /crop-categories | — | name, slug, id |
| /crops/list | category_id, is_recommendate | name, crop_code, id |
| /crop-varieties | crop_id required | name, variety_code, id |
| /seeds | crop_id, crop_variety_id, seed_type, material_type, brand_name, producer_name, seed_class | name, brand_name, id |
| /seed-lots | seed_id required, seed_class | lot_number, valid_until, id |
| /seed-prices | seed_id, farm_id or crop_cycle_id, region_id, price_type, package_unit_code, currency_code | id, package_price, package_quantity, seller_name |
| /crop-seed-rates | crop_id, crop_variety_id, seed_id, season_id, material_type, sowing_method, irrigation_condition, farm_id or crop_cycle_id, region_id, rate_basis | id |
| /fertilizers | fertilizer_type, form, brand_name, manufacturer_name, grade, nutrient_code | name, brand_name, id |
| /fertilizer-recommendations | crop_id, crop_variety_id, season_id, recommendation_type, irrigation_condition, soil_condition, application_method, farm_id or crop_cycle_id, region_id | id |
| /fertilizer-prices | fertilizer_id required, farm_id or crop_cycle_id, region_id, price_type, package_unit_code, currency_code | id, package_price, package_quantity, seller_name |
| /crop-yield-benchmarks | crop_id, crop_variety_id, season_id, benchmark_type, farm_id or crop_cycle_id, region_id | id |

Regional recommendation catalogues require `farm_id` or `crop_cycle_id`; `crop_id` is required without a saved cycle. Seed prices require a seed unless it is already selected on the saved cycle. Saved crop context takes precedence over duplicated request crop/season/farm values. A regional sorting tier is always applied before optional price sorting.

`is_recommendate=true`, `false`, `1`, and `0` are parsed correctly; omitting the filter returns all eligible crops. Crop `season_id` is rejected with 422 because no reliable crop-season catalogue relationship exists. No speculative pivot was created.

### Crop manager endpoints

| Method/path | Parameters or purpose |
| --- | --- |
| GET /farm-crops | Common list parameters; farm_id, crop_id, crop_variety_id, season_id, status. Sort: id, created_at, sowing_date, expected_harvest_date, status. |
| GET /farm-crops/summary | Same filters; counts all matching statuses and sums active cultivated area only |
| GET /farm-crops/map | Same filters; defaults to active; returns all matching lightweight geometries, independently of list pagination |
| POST /farm-crops | Step 1 selection; UUID Idempotency-Key header or idempotency_key body |
| GET /farm-crops/{id} | Complete saved state, pending edit if present, progress, revision, next step |
| PUT /farm-crops/{id}/step-1 | Selection update and revision |
| PUT /farm-crops/{id}/step-2 | Boundary/area and revision |
| GET /farm-crops/{id}/review | Read-only review: crop information, sowing date, maturity estimate, boundary and area |
| POST /farm-crops/{id}/finalize | Current revision; validates and activates only |
| DELETE /farm-crops/{id} | Delete draft or cancel active crop; historical completed/cancelled crops cannot be deleted |
| DELETE /farm-crops/{id}/edit-draft | Current revision; discard pending active edit |
| GET /farm-crops/{id}/soil-report | Authorized current report, including a pending edit report |
| GET /farm-crops/{id}/soil-tests/{soilTest}/report | Authorized saved historical report |
| GET /farms/{farmId}/crop-boundaries | Optional exclude_crop_id, which must belong to that same farm |

File downloads return their file format, rather than a JSON envelope.

## Wizard requests and responses

IDs and values below illustrate payload structure only. Use actual catalogue IDs and the revision from the latest saved response.

### Step 1

`POST /api/account/farm-crops`, header `Idempotency-Key: <UUID>`:

```json
{"farm_id":1,"season_id":1,"crop_id":1,"crop_variety_id":null,"sowing_date":"2026-11-01"}
```

Creates a draft with revision 1 and next step 2. `sowing_date` is required (`Y-m-d`); past and future dates are accepted. The same fields are sent to `PUT /farm-crops/{id}/step-1` with `revision`. Former wizard inputs (`sowing_method`, seed fields, `assessment_mode`, `fertilizers`, and similar) are rejected with 422.

The response includes `maturity` and the stored `expected_harvest_date_min`/`_max`: the sowing date plus the variety's `maturity_days_min`/`_max`, only when the variety has an active, verified knowledge source. Otherwise `maturity.maturity_status` is `unavailable`, which never blocks saving. A single `expected_harvest_date` is stored only when both ends are equal; no midpoint is invented.

### Step 2

Mapped farm:

```json
{"revision":1,"boundary_source":"farm_boundary","area_unit":"hectare"}
```

Manual/GPS drawing uses `boundary_source: manual` or `gps`, a reviewed GeoJSON MultiPolygon under `boundary`, and optional `gps_accuracy_m`. It never changes the farm's own geometry.

Unmapped farm fallback:

```json
{"revision":1,"boundary_source":"manual_area","cultivated_area":"1.00000000","area_unit":"hectare"}
```

The fallback cannot exceed recorded land area or authorize a second active allocation. Supported area codes come from `config/area_units.php`, including explicitly configured `bigha_kaccha` and `bigha_pakka`; no regional Bigha conversion was introduced.

### Former Steps 3–5 (removed)

Seed & sowing, soil assessment and fertilizer planning are no longer part of the wizard; their endpoints return 404. Records already saved through them remain in `farm_crop_cycles` (seed and sowing-method columns), `farm_crop_soil_assessments`, `soil_tests`, `farm_crop_fertilizer_plans` and `farm_crop_yield_estimates`. Editing a crop never clears or rewrites them, and the authorized soil-report downloads remain available.

### Saved step response

Every step returns the complete current crop resource. The following shows its common fields; `saved_step_data` contains the crop selection (with `sowing_date`) and boundary sections:

```json
{
  "status":true,
  "data":{"id":1,"status":"draft","completed_step":2,"next_step":3,"wizard_progress":{"crop_selection":"completed","boundary":"completed","review_save":"pending"},"revision":2,"maturity":{"maturity_status":"available","maturity_days_min":100,"maturity_days_max":120,"expected_harvest_date":null,"expected_harvest_date_min":"2027-02-09","expected_harvest_date_max":"2027-03-01"},"saved_step_data":{}},
  "pagination":null,
  "message":"Crop boundary saved successfully."
}
```

The example abbreviates `data`; actual responses contain the saved sections and regional context. Crops and drafts saved by the former six-step wizard report the same three progress keys; their `completed_step` is normalized (for example, 6 becomes 3).

### Review and final activation

`GET /farm-crops/{id}/review` includes farm/crop/variety/season, sowing date, maturity estimate, region context, boundary statistics, validation_issues, can_finalize, and progress.

`POST /farm-crops/{id}/finalize`:

```json
{"revision":2}
```

Finalization requires only a completed Crop Selection (including a valid sowing date) and Boundary. On success, status becomes `active`, `wizard_progress.review_save` and `completed_step` 3 are set, revision advances, the harvest estimate is refreshed, and `timeline_generated` remains false. No seed, soil, fertilizer or yield records are created. The response message is `Crop saved successfully.` Repeating the accepted finalization revision returns the already-finalized crop.

## Recommendation and estimate contracts

Seed recommendations expose `availability_status`, the selected `seed_rate`, alternatives, recommended/minimum/maximum quantities, quantity_unit, and region_context. Selected rate/price/recommendation/benchmark records include region_scope, regions, matched_region, match_type, source, and applicable conditions.

A missing compatible seed rate returns, for example:

```json
{"status":true,"data":{"availability_status":"no_compatible_recommendation","seed_rate":null,"recommended_quantity":null,"recommended_min_quantity":null,"recommended_max_quantity":null,"quantity_unit":null,"alternatives":[]},"pagination":null,"message":"Seed recommendation retrieved successfully."}
```

The actual supporting endpoint also includes `region_context`.

Fertilizer responses expose separate per-nutrient totals, compatible products, existing notes, and `suggested_product_quantities: null` with `manual_product_quantities_required`. No unverified algorithm assigns commercial products from nutrient targets. Elemental/oxide and weight/volume codes are not silently converted.

Expenses expose estimated_seed_cost, estimated_soil_test_cost, estimated_fertilizer_cost, known_subtotal, total_estimated_cost, currency_code, is_complete, missing_components, and component_states. Example of an incomplete estimate:

```json
{"estimated_seed_cost":"100.00","estimated_soil_test_cost":null,"estimated_fertilizer_cost":null,"known_subtotal":"100.00","total_estimated_cost":null,"currency_code":"INR","is_complete":false,"missing_components":["estimated_soil_test_cost","estimated_fertilizer_cost"],"component_states":{"estimated_seed_cost":"known","estimated_soil_test_cost":"skipped","estimated_fertilizer_cost":"skipped"}}
```

A basic assessment without a declared cost has laboratory expenses marked not_applicable. A declared basic-assessment cost is retained, including explicit zero. Skipping planning leaves its unknown costs incomplete. These are initial planning expenses, not total cultivation or actual expenses.

Yield estimates expose the selected regional benchmark, min/average/max production where present, kilograms and quintal display equivalents, source, and reference year. GET review calculates without inserting history. Finalization saves the accepted estimate; unavailable benchmarks do not block activation.

## Validation and safety

- Ownership is resolved through cycle → farm → authenticated farmer. Cross-owner crops, farms, reports, and edit drafts return 404.
- Only eligible active/published products are selectable. Varieties belong to the selected crop; seeds cannot silently select another variety; lots belong to their seed.
- Revision mismatches and occupancy conflicts return 409 with safe, actionable messages.
- Missing/incompatible inputs return 422 with nested field errors. All writes use intended validated fields; lifecycle and verification controls stay server-owned.
- Area, quantities, and costs are bounded positive/nonnegative decimals. Money and package arithmetic use the installed Brick Math dependency.
- Quantities convert only within configured compatible dimensions. No count-to-weight, elemental-to-oxide, or volume-to-weight conversion is inferred.
- Laboratory pH is 0–14, applicable measurements are nonnegative, reported units are required, and report mode needs a meaningful measurement or valid file.
- Geometry is longitude/latitude GeoJSON with closed, non-self-intersecting rings, positive area, valid MySQL topology, farm containment, and no positive-area active overlap.
- Optional soil/fertilizer sections must be saved or explicitly skipped before activation.
- Earlier changes preserve valid manual values and mark affected steps for review. Historical finalized estimates are separate from current draft calculations.
- Private uploads are generated filenames, validated by content/type/extension/size, and accessible only through authenticated owner-scoped routes.

## Created and modified files

Created controllers under `app/Http/Controllers/Account/`:

`FarmCropController.php`, `RegionController.php`, `SeasonController.php`, `CropCategoryController.php`, `CropController.php`, `CropVarietyController.php`, `SeedController.php`, `SeedLotController.php`, `SeedPriceController.php`, `CropSeedRateController.php`, `FertilizerController.php`, `FertilizerRecommendationController.php`, `FertilizerPriceController.php`, `CropYieldBenchmarkController.php`.

Created resources under `app/Http/Resources/Account/`:

`FarmCropResource.php`, `RegionResource.php`, `SeasonResource.php`, `CropCategoryResource.php`, `CropResource.php`, `CropVarietyResource.php`, `SeedResource.php`, `SeedLotResource.php`, `FertilizerResource.php`, `RegionalRecordResource.php`.

Created models under `app/Models/`:

`FarmCropCycle.php`, `FarmCropSoilAssessment.php`, `SoilTest.php`, `FarmCropFertilizerPlan.php`, `FarmCropYieldEstimate.php`, `FarmCropEditDraft.php`, `FertilizerPrice.php`.

Other new files:

- `config/farm_crops.php`
- `database/factories/FarmCropCycleFactory.php`
- `database/factories/FertilizerPriceFactory.php`
- The two migrations listed above
- `tests/FarmCropFixtures.php`
- `tests/Feature/FarmerCropCatalogueTest.php`
- `tests/Feature/FarmCropControllerTest.php`
- `tests/Feature/FarmCropRegionalTest.php`
- `tests/Feature/FarmCropSpatialTest.php`
- This implementation report

Modified integration files:

`app/Http/Controllers/Account/FarmController.php`, `app/Http/Resources/FarmResource.php`, `app/Models/Farm.php`, `app/Models/Region.php`, `app/Models/CropVariety.php`, `app/Models/Fertilizer.php`, `app/Models/KnowledgeSource.php`, `bootstrap/app.php`, and `routes/api.php`.

The temporary reference table inside `tests/Feature/CropYieldBenchmarkControllerTest.php` was renamed to `legacy_farmer_yield_estimates` so its cascade-reference test can coexist with the newly implemented real table. Its assertions and tested behavior were retained.

## Verification commands

```powershell
php artisan test --compact tests/Feature/FarmCropControllerTest.php tests/Feature/FarmCropRegionalTest.php tests/Feature/FarmerCropCatalogueTest.php tests/Feature/FarmControllerTest.php tests/Feature/FarmAreaConversionTest.php tests/Feature/CropYieldBenchmarkControllerTest.php
$env:FARM_CROP_SPATIAL_DATABASE_TESTS='1'
php artisan test --compact tests/Feature/FarmCropSpatialTest.php
php artisan test --compact
```

Run the spatial command against a MySQL 8.4 test server to execute the additional holes/multiple-parcel checks. It creates and removes an isolated temporary database, requiring appropriate test-server privileges. The existing full-suite failures listed above remain a separate maintenance issue.

## Requested acceptance confirmations

| Requirement | Result |
| --- | --- |
| Multiple active non-overlapping crops | Implemented and verified with native spatial requests |
| All wizard steps use one cycle ID | Implemented and tested |
| Resume drafts | Implemented and tested |
| Exact is_recommendate filtering | True, false, and omitted filters tested |
| Seed prices respect farm region | Implemented and tested |
| Seed rates respect farm region | Implemented and tested |
| Fertilizer recommendations respect farm region | Implemented and tested |
| Fertilizer prices respect farm region | Implemented and tested |
| Yield estimates respect farm region | Implemented and tested |
| Optional steps can be skipped | Implemented and tested |
| Expenses use saved planning data | Implemented and tested, including unknown/zero/skipped costs |
| Final save changes status to active | Implemented and tested, including retry and overlap races |
| No timelines or notifications generated | Confirmed; no generation calls were added and queue/notification absence is tested |
| Existing Farm Manager remains operational | Existing Farm Manager and area-conversion tests pass; multiple active crop fields were added compatibly |

## Remaining data limitations and assumptions

- The installed stack is Laravel 12 and local MariaDB, not the Laravel 13/MySQL 8.4 described in the attachment. No stack upgrade was attempted.
- Local master-data inspection found one active verified seed and no fertilizer products, seed prices, seed rates, fertilizer recommendations, or yield benchmarks. New fertilizer-price tables start empty. Real regional estimates require administrators to maintain appropriate verified master data.
- Crop/season catalogue filtering has no reliable implemented relationship. Unsupported crop-season filtering is rejected; season records are not fabricated. Season lookups now include nullable start_month, end_month, and icon presentation metadata; see SEASONS.md.
- Own/local seed quantity suggestions remain unavailable without reliable planting-material context; custom/unknown quantity remains supported.
- No validated laboratory interpretation or commercial fertilizer allocation algorithm exists. Soil report values are retained, not converted into invented deficiency classes or dosing rules. Reliable existing farm land_type/soil_type supply compatible irrigation/texture context.
- Admin fertilizer-price CRUD remains outside this task. Tables and farmer read/calculation support are implemented.
- MariaDB supports the existing single-ring compatibility workflow; multiple parcels and holes require MySQL 8.4 topology validation.
- The separately referenced SRS, Database-Tables, technology decision files, and standalone SQL attachments were not present in the workspace. The supplied prompt and actual repository schema provided the implementation baseline.
