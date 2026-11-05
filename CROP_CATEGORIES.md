# Crop Category Manager MVP

The module uses the existing Laravel application, Admin controller namespace, Sanctum bearer-token authentication, and status/data/pagination/message JSON envelope. Validation failures also contain errors.

## Endpoints

All endpoints are relative to /api/admin/crop-categories and require auth:admin, abilities:admin, admin.active, and the manage-crop-categories gate. Active admins may manage categories.

| Method | Path | Behavior |
| --- | --- | --- |
| GET | / | Search, filter, sort alphabetically, and paginate categories |
| POST | / | Create an inactive category with supplied translations |
| GET | /{cropCategory} | Details including every translation |
| PATCH | /{cropCategory} | Update slug and supplied translations |
| DELETE | /{cropCategory} | Delete an inactive, unreferenced category |
| PATCH | /{cropCategory}/status | Activate or deactivate |

## Data and writes

The master contains id, unique slug, is_active, and timestamps. A translation contains id, crop_category_id, locale, name, optional description, and timestamps. The database enforces one row per category/locale and cascades translation deletion when an unreferenced category is legitimately deleted.

Creation requires English (en). Other configured languages are optional. Locale keys come from config/languages.php. Each supplied translation requires a nonempty name of at most 255 characters and permits an optional nullable description. Unsupported locale keys and unexpected translation fields are rejected.

An omitted or null creation slug is generated with Str::slug() from the English name. Slugs must consist of lowercase alphanumeric words separated by single hyphens, be at most 255 characters, and be globally unique. If the English name cannot generate a slug, provide one explicitly. Translation edits never regenerate the slug; explicit validated PATCH changes are supported.

The master and all supplied translations are saved in a single transaction, with the master row locked for updates. Omitted locales and fields remain unchanged. An explicit null description clears it. Translation edits preserve the category's active state. New categories are always inactive, and ordinary create/update payloads cannot bypass the dedicated status endpoint.

Activation requires a nonempty English translation. An invalid activation returns 409; invalid boolean input returns 422. Authorized deactivation is always available. Changes to an active category's text are available immediately.

## Locale, search, and pagination

Admin lists default to English, independently of X-Locale and Accept-Language headers. A supported locale query parameter selects another language. Missing requested translations fall back to English. Detail and write responses include all translations keyed by locale; each translation resource contains only locale, name, and description.

List parameters: keyword, is_active, locale, page, per_page. Search matches slug, the resolved display name, and English name. Literal %, _, and ! characters are escaped. Filtering and alphabetical ordering happen in SQL before pagination, with ID as the stable secondary order. Locale-constrained joins prevent duplicate categories.

Pagination defaults to 15 records, caps per_page at 100, requires positive page/per_page, and preserves allowed filters in links. Resources do not issue database queries. Timestamps use existing application serialization and added_at uses j M Y h:i A.

## Publication and retention

CropCategory::published($locale) selects active categories with nonempty English text, resolves the requested locale with English fallback, and eager-loads only the requested and English translations. Public category resources omit the full translation map. The Crop model now belongs to its category through category_id, and CropCategory::crops() provides the inverse relationship. Categories referenced by crops cannot be deleted. There is no farmer-facing crop-category API.

Deletion refuses active categories and respects restrictive foreign-key dependencies, returning 409. Future crop/history consumers must use restrictive references and extend retention checks for any non-FK or polymorphic associations. Historical data must not be cascade-deleted or rewritten when categories are deactivated.

The current database and creation migration already contain the simplified translation schema. No additional schema change is required in this environment.

## Crop catalogue integration

The related Crop Manager provides six admin endpoints under /api/admin/crops: CRUD and PATCH /{crop}/status. The ordinary store/update actions handle the optional main image along with metadata and translations. It requires English and Hindi names on creation, saves all supplied translations atomically, and preserves omitted locales on update. Slugs can be generated from English on creation and stay stable during translation edits. Crop codes are optional and unique.

Active crops require active categories and nonempty English/Hindi names. Crop::published($locale) applies those conditions for new catalogue selections; ordinary model relationships still expose inactive crops for historical records. Crop varieties, stages, cultivation models, and farmer-history tables do not yet exist and are not introduced. Future historical consumers must use restrictive foreign keys or add explicit retention checks for non-FK links.

Crop images use filesystems.crop_images_disk (CROP_IMAGES_DISK, default public), relative generated paths under crops/{crop_id}, and Storage URL generation. POST creation and PUT/PATCH updates accept an optional image file containing JPEG, PNG, or WebP content up to 5 MiB. Omit image to preserve the current image; send image: null on update to remove it. Raw image-path strings and unknown fields are rejected. For multipart updates on PHP, send POST /api/admin/crops/{crop} with _method=PATCH. Nested translations use form fields such as translations[en][name] and translations[hi][name]. CropController persists metadata, supplied translations, and the image within one outer transaction. CropObserver captures old image paths during update/delete events and removes unreferenced files only after commit. Upload failures explicitly clean new and partially written files. Cleanup failures are logged safely without reverting committed data; no durable retry queue is introduced. Keep the disk selection stable for existing paths or migrate their files when changing it. Public local storage requires the existing storage symlink or equivalent media hosting.

The manage-crops gate follows existing active-admin management permissions. Changing AI diagnosis settings additionally requires manage-crop-ai-diagnosis, currently limited to active super users. Enabling the flag requires crops.ai_diagnosis_available (CROP_AI_DIAGNOSIS_AVAILABLE), which defaults to false because no diagnosis backend is implemented here. Future diagnosis endpoints must enforce availability and authorization themselves; the catalogue flag does not implement diagnosis.

Crop migrations have been added separately from category migrations. Apply pending migrations during deployment with php artisan migrate --no-interaction. Crops and crop translations use the requested non-null DATETIME(6) schema. Rollback drops translations before crops and destroys their catalogue data; schema rollback does not dispatch image-cleanup model events.

## Verification

    php artisan test --compact tests/Feature/CropCategoryControllerTest.php tests/Feature/CropCategoryPublicationTest.php
    php artisan test --compact tests/Feature/CropControllerTest.php tests/Feature/CropImageTest.php
    php artisan route:list --path=api/admin/crop-categories --except-vendor --no-interaction
    php artisan route:list --path=api/admin/crops --except-vendor --no-interaction

## Crop Manager file changes

Created:

- database/migrations/2026_11_04_123355_create_crops_table.php
- database/migrations/2026_11_04_123356_create_crop_translations_table.php
- app/Models/Crop.php
- app/Models/CropTranslation.php
- app/Http/Requests/Admin/StoreCropRequest.php
- app/Http/Requests/Admin/UpdateCropRequest.php
- app/Http/Requests/Admin/UpdateCropStatusRequest.php
- config/crops.php
- database/factories/CropFactory.php
- database/factories/CropTranslationFactory.php
- app/Observers/CropObserver.php
- app/Http/Controllers/Admin/CropController.php
- app/Http/Resources/Admin/CropResource.php
- app/Http/Resources/Admin/CropTranslationResource.php
- tests/Feature/CropControllerTest.php
- tests/Feature/CropImageTest.php

Modified:

- app/Models/CropCategory.php
- app/Http/Controllers/Admin/CropCategoryController.php
- config/filesystems.php
- app/Providers/AppServiceProvider.php
- routes/api.php
- CROP_CATEGORIES.md
