<?php

namespace App\Services;

use App\Models\Crime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NaturalLanguageQueryService
{
    private const DEFAULT_BBOX = '-180,-90,180,90';
    private const DEFAULT_RESOLUTION = 7;
    private const DEFAULT_LIMIT = 5;

    public function __construct(private readonly H3AggregationService $aggregationService)
    {
    }

    /**
     * Translate a natural language question into a structured answer.
     *
     * @return array{answer: string, query: array<string, mixed>, data: array<string, mixed>}
     */
    public function answer(string $question): array
    {
        $normalized = Str::lower(trim($question));
        $timeRange = $this->detectTimeRange($normalized);
        $crimeType = $this->detectCrimeType($normalized);
        $limit = $this->detectLimit($normalized);
        $resolution = $this->detectResolution($normalized);

        if ($this->isHotspotQuestion($normalized)) {
            return $this->buildHotspotResponse($question, $timeRange, $crimeType, $limit, $resolution);
        }

        return $this->buildCountResponse($question, $timeRange, $crimeType);
    }

    /**
     * @param array{from: CarbonInterface|null, to: CarbonInterface|null, label: string} $timeRange
     */
    private function buildHotspotResponse(
        string $question,
        array $timeRange,
        ?string $crimeType,
        int $limit,
        int $resolution
    ): array {
        $aggregated = $this->aggregationService->aggregateByBbox(
            self::DEFAULT_BBOX,
            $resolution,
            $timeRange['from'],
            $timeRange['to'],
            $crimeType
        );

        $cells = [];
        foreach ($aggregated as $h3 => $payload) {
            $cells[] = [
                'h3' => $h3,
                'count' => $payload['count'],
                'categories' => $payload['categories'],
            ];
        }

        usort($cells, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $cells = array_slice($cells, 0, $limit);

        $descriptionParts = [];
        foreach ($cells as $cell) {
            $descriptionParts[] = sprintf('%s (%d incidents)', $cell['h3'], $cell['count']);
        }

        $context = $this->buildContextPhrase($crimeType, $timeRange['label']);
        $answer = $cells
            ? sprintf('Top %d H3 cells %s: %s.', count($cells), $context, implode(', ', $descriptionParts))
            : sprintf('No hotspots found %s.', $context);

        return [
            'answer' => $answer,
            'query' => [
                'type' => 'aggregate_hexes',
                'sql' => 'SELECT h3_res'.$resolution.' AS h3, COUNT(*) AS total FROM crimes WHERE ... GROUP BY h3 ORDER BY total DESC LIMIT '.$limit,
                'parameters' => array_filter([
                    'bbox' => self::DEFAULT_BBOX,
                    'resolution' => $resolution,
                    'from' => $timeRange['from']?->toIso8601String(),
                    'to' => $timeRange['to']?->toIso8601String(),
                    'crime_type' => $crimeType,
                    'limit' => $limit,
                ]),
            ],
            'data' => [
                'cells' => $cells,
                'resolution' => $resolution,
                'filters' => [
                    'bbox' => self::DEFAULT_BBOX,
                    'from' => $timeRange['from']?->toIso8601String(),
                    'to' => $timeRange['to']?->toIso8601String(),
                    'crime_type' => $crimeType,
                ],
            ],
        ];
    }

    /**
     * @param array{from: CarbonInterface|null, to: CarbonInterface|null, label: string} $timeRange
     */
    private function buildCountResponse(string $question, array $timeRange, ?string $crimeType): array
    {
        $query = Crime::query();

        if ($timeRange['from']) {
            $query->where('occurred_at', '>=', $timeRange['from']);
        }

        if ($timeRange['to']) {
            $query->where('occurred_at', '<=', $timeRange['to']);
        }

        if ($crimeType) {
            $query->where('category', $crimeType);
        }

        $count = (int) $query->count();

        $context = $this->buildContextPhrase($crimeType, $timeRange['label']);

        $answer = $count > 0
            ? sprintf('There were %d recorded incidents %s.', $count, $context)
            : sprintf('No incidents were recorded %s.', $context);

        return [
            'answer' => $answer,
            'query' => [
                'type' => 'count',
                'sql' => 'SELECT COUNT(*) FROM crimes WHERE ...',
                'parameters' => array_filter([
                    'from' => $timeRange['from']?->toIso8601String(),
                    'to' => $timeRange['to']?->toIso8601String(),
                    'crime_type' => $crimeType,
                ]),
            ],
            'data' => [
                'total' => $count,
                'filters' => [
                    'from' => $timeRange['from']?->toIso8601String(),
                    'to' => $timeRange['to']?->toIso8601String(),
                    'crime_type' => $crimeType,
                ],
            ],
        ];
    }

    private function detectTimeRange(string $question): array
    {
        $now = CarbonImmutable::now();

        if (str_contains($question, 'today')) {
            return [
                'from' => $now->startOfDay(),
                'to' => $now->endOfDay(),
                'label' => 'today',
            ];
        }

        if (str_contains($question, 'yesterday')) {
            $yesterday = $now->subDay();
            return [
                'from' => $yesterday->startOfDay(),
                'to' => $yesterday->endOfDay(),
                'label' => 'yesterday',
            ];
        }

        if (str_contains($question, 'this week')) {
            return [
                'from' => $now->startOfWeek(),
                'to' => $now->endOfWeek(),
                'label' => 'this week',
            ];
        }

        if (str_contains($question, 'last week')) {
            $start = $now->startOfWeek()->subWeek();
            return [
                'from' => $start,
                'to' => $start->endOfWeek(),
                'label' => 'last week',
            ];
        }

        if (str_contains($question, 'this month')) {
            return [
                'from' => $now->startOfMonth(),
                'to' => $now->endOfMonth(),
                'label' => 'this month',
            ];
        }

        if (str_contains($question, 'last month')) {
            $start = $now->startOfMonth()->subMonth();
            return [
                'from' => $start,
                'to' => $start->endOfMonth(),
                'label' => 'last month',
            ];
        }

        if (preg_match('/last\s+(\d+)\s+day/', $question, $matches)) {
            $days = (int) $matches[1];
            return [
                'from' => $now->subDays(max($days, 1)),
                'to' => $now,
                'label' => sprintf('in the last %d days', $days),
            ];
        }

        if (preg_match('/last\s+(\d+)\s+week/', $question, $matches)) {
            $weeks = (int) $matches[1];
            return [
                'from' => $now->subWeeks(max($weeks, 1)),
                'to' => $now,
                'label' => sprintf('in the last %d weeks', $weeks),
            ];
        }

        if (preg_match('/last\s+(\d+)\s+month/', $question, $matches)) {
            $months = (int) $matches[1];
            return [
                'from' => $now->subMonths(max($months, 1)),
                'to' => $now,
                'label' => sprintf('in the last %d months', $months),
            ];
        }

        return [
            'from' => null,
            'to' => null,
            'label' => 'across all available records',
        ];
    }

    private function detectCrimeType(string $question): ?string
    {
        $categories = Cache::remember(
            'nlq:categories',
            now()->addHour(),
            static fn () => Crime::query()
                ->select('category')
                ->distinct()
                ->pluck('category')
                ->filter()
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all()
        );

        foreach ($categories as $category) {
            $needle = Str::lower($category);
            if ($needle !== '' && str_contains($question, $needle)) {
                return $category;
            }
        }

        return null;
    }

    private function detectLimit(string $question): int
    {
        if (preg_match('/top\s+(\d+)/', $question, $matches)) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('/(\d+)\s+(hotspots|cells|areas)/', $question, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return self::DEFAULT_LIMIT;
    }

    private function detectResolution(string $question): int
    {
        if (preg_match('/res(olution)?\s*(\d+)/', $question, $matches)) {
            $resolution = (int) $matches[2];
            if (in_array($resolution, [6, 7, 8], true)) {
                return $resolution;
            }
        }

        return self::DEFAULT_RESOLUTION;
    }

    private function isHotspotQuestion(string $question): bool
    {
        return str_contains($question, 'top')
            || str_contains($question, 'highest')
            || str_contains($question, 'hotspot')
            || str_contains($question, 'risk');
    }

    private function buildContextPhrase(?string $crimeType, string $label): string
    {
        $parts = [];

        if ($crimeType) {
            $parts[] = strtolower($crimeType).' crimes';
        } else {
            $parts[] = 'across all crime categories';
        }

        if ($label) {
            $parts[] = $label;
        }

        return implode(' ', $parts);
    }
}
