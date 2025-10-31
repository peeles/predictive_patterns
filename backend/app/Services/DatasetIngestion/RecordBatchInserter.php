<?php

declare(strict_types=1);

namespace App\Services\DatasetIngestion;

use App\Models\DatasetRecord;
use App\Services\H3AggregationService;

/**
 * Service for batch inserting dataset records.
 *
 * Handles duplicate detection and H3 aggregate invalidation.
 */
class RecordBatchInserter
{
    public function __construct(
        private readonly H3AggregationService $h3AggregationService,
    ) {
    }

    /**
     * Insert the accumulated dataset rows, skipping any that already exist.
     *
     * @param array<int, array<string, mixed>> $buffer
     * @param bool $dryRun If true, skip actual insertion
     *
     * @return array{0:int,1:int,2:int} Array containing processed rows, expected insertions, and existing records
     */
    public function flush(array &$buffer, bool $dryRun): array
    {
        $processed = count($buffer);
        if ($processed === 0) {
            return [0, 0, 0];
        }

        $ids = array_column($buffer, 'id');
        $existing = DatasetRecord::query()->whereIn('id', $ids)->pluck('id')->all();

        if ($existing) {
            $existing = array_flip($existing);
            $buffer = array_values(array_filter($buffer, static fn (array $row): bool => ! isset($existing[$row['id']])));
        }

        $insertable = count($buffer);
        $existingCount = $processed - $insertable;

        if (! $dryRun && $insertable > 0) {
            DatasetRecord::query()->insert($buffer);
            $this->h3AggregationService->invalidateAggregatesForRecords($buffer);
        }

        $buffer = [];

        return [$processed, $insertable, $existingCount];
    }
}
