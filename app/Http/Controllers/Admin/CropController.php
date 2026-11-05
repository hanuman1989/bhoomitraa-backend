<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropRequest;
use App\Http\Requests\Admin\UpdateCropRequest;
use App\Http\Requests\Admin\UpdateCropStatusRequest;
use App\Http\Resources\Admin\CropResource;
use App\Models\Crop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PDOException;
use RuntimeException;
use Throwable;

class CropController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'category_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'ai_diagnosis_enabled' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = Crop::query()->withLocalizedName()
                ->with(['category.translations' => fn (HasMany $translations): HasMany => $translations
                    ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))]);
            foreach (['category_id', 'is_active', 'ai_diagnosis_enabled'] as $filter) {
                if (array_key_exists($filter, $filters)) {
                    $query->where('crops.'.$filter, $filters[$filter]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("crops.slug LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("crops.scientific_name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("crops.crop_code LIKE ? ESCAPE '!'", [$pattern]);
                });
            }
            $crops = $query->orderBy('default_translation.name')->orderBy('crops.id')
                ->paginate($request->integer('per_page', 15))->appends($request->only([
                    'keyword', 'category_id', 'is_active', 'ai_diagnosis_enabled', 'per_page',
                ]));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crops. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropResource::collection($crops),
            'pagination' => [
                'current_page' => $crops->currentPage(),
                'last_page' => $crops->lastPage(),
                'per_page' => $crops->perPage(),
                'total' => $crops->total(),
                'from' => $crops->firstItem(),
                'to' => $crops->lastItem(),
                'next_page_url' => $crops->nextPageUrl(),
                'prev_page_url' => $crops->previousPageUrl(),
            ],
            'message' => 'Crops retrieved successfully.',
        ]);
    }

    public function store(StoreCropRequest $request): JsonResponse
    {
        try {
            $crop = $this->persistCrop($request);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropResource($crop),
            'pagination' => null,
            'message' => 'Crop created successfully.',
        ], 201);
    }

    public function show(Crop $crop): JsonResponse
    {
        $crop->load(['translations', 'category.translations']);

        return response()->json([
            'status' => true,
            'data' => new CropResource($crop),
            'pagination' => null,
            'message' => 'Crop retrieved successfully.',
        ]);
    }

    public function update(UpdateCropRequest $request, Crop $crop): JsonResponse
    {
        try {
            $crop = $this->persistCrop($request, $crop);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropResource($crop),
            'pagination' => null,
            'message' => 'Crop updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropStatusRequest $request, Crop $crop): JsonResponse
    {
        $crop = $crop->saveWithTranslations(['is_active' => $request->boolean('is_active')], []);

        return response()->json([
            'status' => true,
            'data' => new CropResource($crop),
            'pagination' => null,
            'message' => 'Crop status updated successfully.',
        ]);
    }

    public function destroy(Crop $crop): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($crop): bool {
                $crop = Crop::query()->lockForUpdate()->findOrFail($crop->id);
                if ($crop->is_active) {
                    return false;
                }
                $crop->delete();

                return true;
            });
            if (! $deleted) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1451
                || (($exception->errorInfo[0] ?? null) === '23000' && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the crop. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Crop deleted successfully.',
        ]);
    }

    private function persistCrop(StoreCropRequest $request, ?Crop $crop = null): Crop
    {
        $disk = config('filesystems.crop_images_disk');
        $path = null;

        try {
            return DB::transaction(function () use ($request, $crop, $disk, &$path): Crop {
                $crop = ($crop ?? new Crop)->saveWithTranslations(
                    $request->safe()->except(['translations', 'image']),
                    $request->validated('translations', []),
                );
                if ($request->hasFile('image')) {
                    $file = $request->file('image');
                    $directory = 'crops/'.$crop->id;
                    $filename = Str::uuid().'.'.$file->guessExtension();
                    $path = $directory.'/'.$filename;
                    if (Storage::disk($disk)->putFileAs($directory, $file, $filename, ['visibility' => 'public']) === false) {
                        throw new RuntimeException('Unable to store crop image.');
                    }
                    $crop->image = $path;
                    $crop->save();
                } elseif ($request->exists('image')) {
                    $crop->image = null;
                    $crop->save();
                }

                return $crop;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                try {
                    if (! Storage::disk($disk)->delete($path)) {
                        Log::warning('Failed crop upload cleanup requires attention.', ['crop_id' => $crop?->id]);
                    }
                } catch (Throwable $cleanupException) {
                    Log::warning('Failed crop upload cleanup requires attention.', [
                        'crop_id' => $crop?->id, 'exception_type' => $cleanupException::class,
                    ]);
                }
            }

            throw $exception;
        }
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The crop cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}
