<?php

declare(strict_types=1);

namespace App\Services\H3;

use App\DataTransferObjects\HexAggregate;
use App\Models\DatasetRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for building H3 aggregation queries with filters.
 *
 * Handles query construction, temporal filtering, time-of-day filtering,
 * and category/severity filtering for H3 cell aggregations.
 */
class H3QueryBuilder
{
    /**
     * Execute the aggregation query and return HexAggregate objects.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @param int $resolution H3 resolution level
     * @param CarbonInterface|null $from Start date filter
     * @param CarbonInterface|null $to End date filter
     * @param string|null $category Category filter
     * @param int|null $timeOfDayStart Start hour (0-23)
     * @param int|null $timeOfDayEnd End hour (0-23)
     * @param string|null $severity Severity filter
     *
     * @return HexAggregate[]
     */
    public function aggregate(
        array $boundingBox,
        int $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string $category,
        ?int $timeOfDayStart,
        ?int $timeOfDayEnd,
        ?string $severity,
    ): array {
        [$west, $south, $east, $north] = $boundingBox;

        $query = DatasetRecord::query()
            ->whereBetween('lng', [$west, $east])
            ->whereBetween('lat', [$south, $north]);

        $this->applyTemporalFilters($query, $from, $to);
        $this->applyTimeOfDayFilter($query, $timeOfDayStart, $timeOfDayEnd);

        if ($category) {
            $query->where('category', $category);
        }

        $this->applySeverityFilter($query, $severity);

        $column = sprintf('h3_res%d', $resolution);

        return $query
            ->selectRaw(
                "$column as h3, category, count(*) as c, ".
                'count(risk_score) as risk_value_count, '.
                'COALESCE(sum(risk_score), 0) as risk_sum, '.
                'COALESCE(sum(POWER(risk_score, 2)), 0) as risk_sum_squares'
            )
            ->groupBy($column, 'category')
            ->get()
            ->groupBy('h3')
            ->map(
                static function (Collection $rows) {
                    $first = $rows->first();
                    $h3 = (string) ($first->h3 ?? '');
                    $count = (int) $rows->sum('c');
                    $categories = $rows
                        ->pluck('c', 'category')
                        ->map(static fn ($value) => (int) $value)
                        ->toArray();
                    $riskValueCount = (int) $rows->sum('risk_value_count');
                    $riskValueSum = (float) $rows->sum('risk_sum');
                    $riskValueSumSquares = (float) $rows->sum('risk_sum_squares');

                    return new HexAggregate(
                        $h3,
                        $count,
                        $categories,
                        $riskValueCount,
                        $riskValueSum,
                        $riskValueSumSquares,
                    );
                }
            )
            ->values()
            ->all();
    }

    /**
     * Apply from/to constraints onto the aggregate query if they are supplied.
     */
    private function applyTemporalFilters(
        Builder $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to
    ): void {
        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to) {
            $query->where('occurred_at', '<=', $to);
        }
    }

    /**
     * Apply time-of-day filtering to the aggregate query if start or end times are supplied.
     */
    private function applyTimeOfDayFilter(
        Builder $query,
        ?int $timeOfDayStart,
        ?int $timeOfDayEnd
    ): void {
        if ($timeOfDayStart === null && $timeOfDayEnd === null) {
            return;
        }

        if ($timeOfDayStart === null || $timeOfDayEnd === null) {
            return;
        }

        $hourExpression = DB::raw($this->hourExtractionExpression($query));

        if ($timeOfDayStart <= $timeOfDayEnd) {
            $query->whereBetween($hourExpression, [$timeOfDayStart, $timeOfDayEnd]);

            return;
        }

        $query->where(static function (Builder $builder) use ($hourExpression, $timeOfDayStart, $timeOfDayEnd): void {
            $builder
                ->whereBetween($hourExpression, [$timeOfDayStart, 23])
                ->orWhereBetween($hourExpression, [0, $timeOfDayEnd]);
        });
    }

    /**
     * Apply severity filtering to the aggregate query if a severity is supplied.
     */
    private function applySeverityFilter(Builder $query, ?string $severity): void
    {
        if ($severity === null) {
            return;
        }

        $query->where('severity', $severity);
    }

    /**
     * Build the database-specific hour extraction expression.
     */
    private function hourExtractionExpression(Builder $query): string
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => "CAST(strftime('%H', occurred_at) AS INTEGER)",
            'mysql', 'mariadb' => 'HOUR(occurred_at)',
            default => 'EXTRACT(HOUR FROM occurred_at)',
        };
    }
}
