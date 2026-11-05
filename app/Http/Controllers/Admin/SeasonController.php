<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSeasonRequest;
use App\Http\Requests\Admin\UpdateSeasonRequest;
use App\Http\Requests\Admin\UpdateSeasonStatusRequest;
use App\Http\Resources\Admin\SeasonResource;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;

class SeasonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = Season::query()->withLocalizedName();

            if (array_key_exists('status', $filters)) {
                $query->where('seasons.is_active', $filters['status']);
            }

            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("seasons.slug LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern]);
                });
            }

            $seasons = $query->orderBy('localized_name')->orderBy('seasons.id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only(['keyword', 'status', 'per_page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve seasons. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => SeasonResource::collection($seasons),
            'pagination' => [
                'current_page' => $seasons->currentPage(),
                'last_page' => $seasons->lastPage(),
                'per_page' => $seasons->perPage(),
                'total' => $seasons->total(),
                'from' => $seasons->firstItem(),
                'to' => $seasons->lastItem(),
                'next_page_url' => $seasons->nextPageUrl(),
                'prev_page_url' => $seasons->previousPageUrl(),
            ],
            'message' => 'Seasons retrieved successfully.',
        ]);
    }

    public function store(StoreSeasonRequest $request): JsonResponse
    {
        try {
            $season = $this->saveSeason(new Season, $request->safe()->only('slug'), $request->validated('translations'));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the season. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new SeasonResource($season),
            'pagination' => null,
            'message' => 'Season created successfully.',
        ], 201);
    }

    public function show(Season $season): JsonResponse
    {
        $season->load('translations');

        return response()->json([
            'status' => true,
            'data' => new SeasonResource($season),
            'pagination' => null,
            'message' => 'Season retrieved successfully.',
        ]);
    }

    public function update(UpdateSeasonRequest $request, Season $season): JsonResponse
    {
        try {
            $season = $this->saveSeason($season, $request->safe()->only('slug'), $request->validated('translations', []));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the season. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new SeasonResource($season),
            'pagination' => null,
            'message' => 'Season updated successfully.',
        ]);
    }

    public function updateStatus(UpdateSeasonStatusRequest $request, Season $season): JsonResponse
    {
        try {
            $season = DB::transaction(function () use ($request, $season): Season {
                $season = Season::query()->lockForUpdate()->findOrFail($season->id);
                $season->load('translations');

                if ($request->boolean('is_active')) {
                    $errors = [];
                    foreach (Season::REQUIRED_LOCALES as $locale) {
                        $name = $season->translations->firstWhere('locale', $locale)?->name;
                        if (blank($name)) {
                            $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' season name is required before activation.'];
                        }
                    }

                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }

                $season->is_active = $request->boolean('is_active');
                $season->save();

                return $season;
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the season status. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new SeasonResource($season),
            'pagination' => null,
            'message' => 'Season status updated successfully.',
        ]);
    }

    public function destroy(Season $season): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($season): bool {
                $season = Season::query()->lockForUpdate()->findOrFail($season->id);
                if ($season->is_active || $season->hasExternalReferences()) {
                    return false;
                }
                $season->delete();

                return true;
            }, attempts: 3);
            if (! $deleted) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1451, 787], true)
                || (($exception->errorInfo[0] ?? null) === '23000'
                    && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the season. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the season. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Season deleted successfully.',
        ]);
    }

    /**
     * @param  array{slug?: string}  $attributes
     * @param  array<string, array{name?: string, description?: string|null}>  $translations
     */
    private function saveSeason(Season $season, array $attributes, array $translations): Season
    {
        $seasonId = $season->exists ? $season->id : null;

        return DB::transaction(function () use ($seasonId, $attributes, $translations): Season {
            $season = $seasonId === null ? new Season : Season::query()->lockForUpdate()->findOrFail($seasonId);
            $season->fill($attributes);
            if (! $season->exists) {
                $season->is_active = false;
            }
            $season->save();

            foreach ($translations as $locale => $values) {
                $season->translations()->updateOrCreate(['locale' => $locale], $values);
            }

            return $season->load('translations');
        }, attempts: 3);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The season cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}
