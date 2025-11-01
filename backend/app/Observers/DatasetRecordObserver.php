<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DatasetRecord;
use App\Services\H3AggregationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Observer for DatasetRecord model events.
 *
 * Automatically invalidates H3 aggregation cache when dataset records
 * are created, updated, or deleted to ensure aggregation queries
 * always return fresh data.
 */
class DatasetRecordObserver
{
    public function __construct(
        private readonly H3AggregationService $h3AggregationService
    ) {
    }

    /**
     * Handle the DatasetRecord "created" event.
     *
     * Invalidates cache after a record is successfully created.
     */
    public function created(DatasetRecord $record): void
    {
        $this->invalidateCache([$record], 'created');
    }

    /**
     * Handle the DatasetRecord "updated" event.
     *
     * Invalidates cache after a record is successfully updated.
     * This ensures changes to location, category, or temporal data
     * are reflected in aggregations.
     */
    public function updated(DatasetRecord $record): void
    {
        $this->invalidateCache([$record], 'updated');
    }

    /**
     * Handle the DatasetRecord "deleted" event.
     *
     * Invalidates cache after a record is successfully deleted.
     */
    public function deleted(DatasetRecord $record): void
    {
        $this->invalidateCache([$record], 'deleted');
    }

    /**
     * Invalidate H3 aggregation cache for the affected records.
     *
     * @param DatasetRecord[] $records Records that changed
     * @param string $event Event type (created|updated|deleted)
     */
    private function invalidateCache(array $records, string $event): void
    {
        try {
            // Convert records to array format expected by invalidation service
            $recordData = array_map(function (DatasetRecord $record) {
                return [
                    'id' => $record->id,
                    'occurred_at' => $record->occurred_at?->toDateTimeString(),
                    'h3_res6' => $record->h3_res6,
                    'h3_res7' => $record->h3_res7,
                    'h3_res8' => $record->h3_res8,
                    'category' => $record->category,
                ];
            }, $records);

            $this->h3AggregationService->invalidateAggregatesForRecords($recordData);

            Log::debug('H3 aggregation cache invalidated', [
                'event' => $event,
                'record_count' => count($records),
                'record_ids' => array_map(fn ($r) => $r->id, $records),
            ]);
        } catch (Throwable $exception) {
            // Log but don't fail the transaction if cache invalidation fails
            Log::warning('Failed to invalidate H3 aggregation cache', [
                'event' => $event,
                'record_count' => count($records),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
