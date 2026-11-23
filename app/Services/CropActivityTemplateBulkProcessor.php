<?php

namespace App\Services;

use App\Models\CropActivityTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Applies one bulk operation record by record. Each record uses the single-record write rules
 * and its own transaction, so one failure never rolls back or blocks the others.
 */
class CropActivityTemplateBulkProcessor
{
    public function __construct(private CropActivityTemplateWriter $writer) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $data
     * @return array{results: list<array<string, mixed>>, totals: array{requested: int, updated: int, skipped: int, failed: int}}
     */
    public function process(array $ids, string $operation, array $data): array
    {
        $templates = CropActivityTemplate::query()->whereIn('id', $ids)->get()->keyBy('id');
        $results = [];
        foreach ($ids as $id) {
            $template = $templates->get($id);
            $results[] = $template === null
                ? $this->result($id, 'failed', 'not_found', 'The crop activity template does not exist.')
                : $this->processOne($template, $operation, $data);
        }
        $statuses = array_count_values(array_column($results, 'status'));

        return [
            'results' => $results,
            'totals' => [
                'requested' => count($ids),
                'updated' => $statuses['updated'] ?? 0,
                'skipped' => $statuses['skipped'] ?? 0,
                'failed' => $statuses['failed'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processOne(CropActivityTemplate $template, string $operation, array $data): array
    {
        try {
            switch ($operation) {
                case 'activate':
                case 'deactivate':
                    $isActive = $operation === 'activate';
                    if ($template->is_active === $isActive) {
                        return $this->result($template->id, 'skipped', 'no_changes', 'The template already has this status.');
                    }
                    $this->writer->changeStatus($template, $isActive);

                    return $this->result($template->id, 'updated');
                case 'delete':
                    return $this->writer->delete($template)
                        ? $this->result($template->id, 'updated', null, 'Deleted.')
                        : $this->result($template->id, 'failed', 'conflict', 'Active or referenced templates cannot be deleted. Deactivate them instead.');
                default:
                    $changes = $this->changes($template, $operation, $data);
                    if ($this->isUnchanged($template, $changes)) {
                        return $this->result($template->id, 'skipped', 'no_changes', 'The template already has these values.');
                    }
                    $this->writer->save($template, $changes);

                    return $this->result($template->id, 'updated');
            }
        } catch (ValidationException $exception) {
            return [...$this->result($template->id, 'failed', 'validation_failed', 'The change is not valid for this template.'), 'errors' => $exception->errors()];
        } catch (AuthorizationException) {
            return $this->result($template->id, 'failed', 'forbidden', 'You are not allowed to change this template.');
        } catch (ConflictHttpException $exception) {
            return $this->result($template->id, 'failed', 'conflict', $exception->getMessage());
        } catch (QueryException|PDOException $exception) {
            report($exception);

            return $this->result($template->id, 'failed', 'database_error', 'The template could not be saved. Please try again later.');
        }
    }

    /**
     * Translate the operation into a single-record patch payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function changes(CropActivityTemplate $template, string $operation, array $data): array
    {
        return match ($operation) {
            'smart_actions' => ['smart_actions' => $this->combine(
                array_values($template->smart_actions ?? []), $data['smart_actions'], $data['mode'] ?? 'replace',
            )],
            'knowledge_sources' => ['knowledge_source_ids' => array_map('intval', $this->combine(
                $template->resolveKnowledgeSourceIds([]), array_map('intval', $data['knowledge_source_ids']), $data['mode'] ?? 'replace',
            ))],
            'clear_schedule' => ['schedule_basis' => CropActivityTemplate::PENDING_SCHEDULE_BASIS],
            default => $data,
        };
    }

    /**
     * @param  list<mixed>  $current
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function combine(array $current, array $values, string $mode): array
    {
        return match ($mode) {
            'add' => array_values(array_unique([...$current, ...$values])),
            'remove' => array_values(array_diff($current, $values)),
            default => array_values($values),
        };
    }

    /** @param array<string, mixed> $changes */
    private function isUnchanged(CropActivityTemplate $template, array $changes): bool
    {
        $lists = [
            'crop_variety_ids' => fn (): array => $template->resolveVarietyIds([]),
            'season_ids' => fn (): array => $template->resolveSeasonIds([]),
            'region_ids' => fn (): array => $template->resolveRegionIds([]),
            'knowledge_source_ids' => fn (): array => $template->resolveKnowledgeSourceIds([]),
        ];
        foreach ($changes as $field => $value) {
            if (isset($lists[$field])) {
                $current = $lists[$field]();
                $proposed = array_map('intval', $value);
                sort($current);
                sort($proposed);
                if ($current !== $proposed) {
                    return false;
                }
            } elseif ($field === 'smart_actions') {
                if (array_values($template->smart_actions ?? []) !== array_values($value)) {
                    return false;
                }
            } elseif ($template->getAttribute($field) !== $value
                && ! (is_bool($template->getAttribute($field)) && (bool) $value === $template->getAttribute($field))) {
                return false;
            }
        }

        return true;
    }

    /** @return array{id: int, status: string, reason: string|null, message: string|null} */
    private function result(int $id, string $status, ?string $reason = null, ?string $message = null): array
    {
        return ['id' => $id, 'status' => $status, 'reason' => $reason, 'message' => $message];
    }
}
