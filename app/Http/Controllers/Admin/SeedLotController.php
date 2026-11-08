<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSeedLotRequest;
use App\Http\Requests\Admin\UpdateSeedLotRequest;
use App\Http\Requests\Admin\UpdateSeedLotStatusRequest;
use App\Http\Requests\Admin\UpdateSeedLotVerificationRequest;
use App\Http\Resources\Admin\SeedLotResource;
use App\Models\KnowledgeSource;
use App\Models\SeedLot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SeedLotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'seed_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seeds', 'id')],
            'verification_status' => ['sometimes', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)],
            'is_active' => ['sometimes', 'boolean'], 'validity_status' => ['sometimes', Rule::in(SeedLot::VALIDITY_STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));
        $request->attributes->set('seed_lot_today', $today);
        try {
            $query = SeedLot::query()->with($this->relations())->whereValidity($filters['validity_status'] ?? 'all', $today);
            foreach (['seed_id', 'verification_status', 'is_active'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where($field, $filters[$field]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("lot_number LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("certification_number LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('seed', fn (Builder $seed): Builder => $seed->withLocalizedName()
                            ->where(fn (Builder $seed): Builder => $seed
                                ->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                                ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern])
                                ->orWhereRaw("seeds.sku LIKE ? ESCAPE '!'", [$pattern])));
                });
            }
            $lots = $query->orderBy('lot_number')->orderBy('id')->paginate($request->integer('per_page', 15))
                ->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve seed lots. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => SeedLotResource::collection($lots),
            'pagination' => ['current_page' => $lots->currentPage(), 'last_page' => $lots->lastPage(), 'per_page' => $lots->perPage(),
                'total' => $lots->total(), 'from' => $lots->firstItem(), 'to' => $lots->lastItem(),
                'next_page_url' => $lots->nextPageUrl(), 'prev_page_url' => $lots->previousPageUrl()],
            'message' => 'Seed lots retrieved successfully.',
        ]);
    }

    public function store(StoreSeedLotRequest $request): JsonResponse
    {
        try {
            $lot = $this->persistLot($request);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the seed lot. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new SeedLotResource($lot), 'pagination' => null, 'message' => 'Seed lot created successfully.'], 201);
    }

    public function show(SeedLot $seedLot): JsonResponse
    {
        $seedLot->load($this->relations());

        return response()->json(['status' => true, 'data' => new SeedLotResource($seedLot), 'pagination' => null, 'message' => 'Seed lot retrieved successfully.']);
    }

    public function update(UpdateSeedLotRequest $request, SeedLot $seedLot): JsonResponse
    {
        try {
            $lot = $this->persistLot($request, $seedLot);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the seed lot. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new SeedLotResource($lot), 'pagination' => null, 'message' => 'Seed lot updated successfully.']);
    }

    private function persistLot(StoreSeedLotRequest $request, ?SeedLot $existing = null): SeedLot
    {
        $path = null;
        try {
            return DB::transaction(function () use ($request, $existing, &$path): SeedLot {
                $lot = $existing === null ? new SeedLot : SeedLot::query()->lockForUpdate()->findOrFail($existing->id);
                $lot->fill(Arr::only($request->validated(), $lot->getFillable()));
                $errors = $lot->validationErrors(lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $changed = $lot->isDirty(SeedLot::REVIEW_FIELDS);
                $oldReport = $lot->getRawOriginal('report_file');
                $lot->save();
                if ($request->hasFile('report_file')) {
                    $file = $request->file('report_file');
                    $directory = 'seed-lots/'.$lot->id;
                    $filename = Str::uuid().'.'.$file->guessExtension();
                    $path = $directory.'/'.$filename;
                    if (Storage::disk(config('filesystems.seed_lot_reports_disk'))->putFileAs($directory, $file, $filename, ['visibility' => 'private']) === false) {
                        throw new RuntimeException('Unable to store seed lot report.');
                    }
                    $lot->report_file = $path;
                } elseif ($request->exists('report_file')) {
                    $lot->report_file = null;
                }
                $changed = $changed || $lot->isDirty('report_file');
                if ($changed) {
                    $lot->resetVerification();
                }
                if ($request->exists('verification_status')) {
                    $status = $request->validated('verification_status');
                    if ($status !== 'pending') {
                        Gate::forUser($request->user())->authorize('review-knowledge-sources');
                    }
                    $lot->setVerification($status, (int) $request->user()->getAuthIdentifier(), $changed && $status !== 'pending');
                }
                $lot->save();
                if ($oldReport !== null && $oldReport !== $lot->report_file) {
                    $lot->getConnection()->afterCommit(fn () => $this->deleteReport($oldReport, $lot->id));
                }

                return $lot->load($this->relations());
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->deleteReport($path, $existing?->id, checkReferences: false);
            }
            throw $exception;
        }
    }

    public function updateStatus(UpdateSeedLotStatusRequest $request, SeedLot $seedLot): JsonResponse
    {
        $lot = DB::transaction(function () use ($request, $seedLot): SeedLot {
            $lot = SeedLot::query()->lockForUpdate()->findOrFail($seedLot->id);
            $lot->is_active = $request->boolean('is_active');
            $lot->save();

            return $lot->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new SeedLotResource($lot), 'pagination' => null, 'message' => 'Seed lot status updated successfully.']);
    }

    public function updateVerification(UpdateSeedLotVerificationRequest $request, SeedLot $seedLot): JsonResponse
    {
        $lot = DB::transaction(function () use ($request, $seedLot): SeedLot {
            $lot = SeedLot::query()->lockForUpdate()->findOrFail($seedLot->id);
            $lot->setVerification($request->validated('verification_status'), (int) $request->user()->getAuthIdentifier());
            $lot->save();

            return $lot->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new SeedLotResource($lot), 'pagination' => null, 'message' => 'Seed lot verification updated successfully.']);
    }

    public function report(SeedLot $seedLot): StreamedResponse
    {
        $path = $seedLot->report_file;
        abort_if($path === null || ! str_starts_with($path, 'seed-lots/'.$seedLot->id.'/') || str_contains($path, '..'), 404);
        $disk = Storage::disk(config('filesystems.seed_lot_reports_disk'));
        abort_unless($disk->exists($path), 404);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime = match ($extension) {
            'pdf' => 'application/pdf', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'application/octet-stream'
        };

        return $disk->download($path, 'seed-lot-'.$seedLot->id.'-report.'.$extension, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(SeedLot $seedLot): JsonResponse
    {
        try {
            DB::transaction(function () use ($seedLot): void {
                $lot = SeedLot::query()->lockForUpdate()->findOrFail($seedLot->id);
                abort_if($lot->is_active || $lot->hasExternalReferences(), 409, 'Active or referenced seed lots cannot be deleted. Deactivate the lot instead.');
                $path = $lot->report_file;
                $lot->delete();
                if ($path !== null) {
                    $lot->getConnection()->afterCommit(fn () => $this->deleteReport($path, $lot->id));
                }
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the seed lot. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Seed lot deleted successfully.']);
    }

    /** @return array<int|string, mixed> */
    private function relations(): array
    {
        $names = static fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);

        return ['seed:id,crop_id,crop_variety_id,sku,material_type,is_active,setup_status,deleted_at',
            'seed.translations' => $names, 'seed.crop:id', 'seed.crop.translations' => $names,
            'seed.cropVariety:id', 'seed.cropVariety.translations' => $names, 'seed.primaryImage',
            'verifier:id,first_name,last_name'];
    }

    private function deleteReport(string $path, ?int $lotId, bool $checkReferences = true): void
    {
        try {
            if ($checkReferences && SeedLot::query()->where('report_file', $path)->exists()) {
                return;
            }
            if (! Storage::disk(config('filesystems.seed_lot_reports_disk'))->delete($path)) {
                Log::warning('Seed lot report cleanup failed.', ['seed_lot_id' => $lotId]);
            }
        } catch (Throwable $exception) {
            Log::warning('Seed lot report cleanup failed.', ['seed_lot_id' => $lotId, 'exception_type' => $exception::class]);
        }
    }
}
