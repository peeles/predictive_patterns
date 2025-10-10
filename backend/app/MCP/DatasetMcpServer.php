<?php

namespace App\MCP;

use App\Jobs\IngestDatasetRecords;
use App\Models\DatasetRecord;
use App\Services\H3AggregationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DatasetMcpServer
{
    public function run(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $resp = $this->dispatch($line);
            fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_SLASHES).PHP_EOL);
            fflush(STDOUT);
        }
    }

    private function dispatch(string $raw): array
    {
        try {
            $msg = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
            $id  = $msg['id'] ?? null;
            $tool = $msg['tool'] ?? null;
            $args = $msg['arguments'] ?? [];

            return [
                'id' => $id,
                'result' => match ($tool) {
                    'aggregate_hexes' => $this->aggregate($args),
                    'ingest_dataset_data' => $this->ingest($args),
                    'export_geojson' => $this->export($args),
                    'get_categories' => $this->categories(),
                    'list_ingested_months' => $this->ingestedMonths(),
                    'get_top_cells' => $this->topCells($args),
                    default => ['error' => 'unknown_tool']
                }
            ];
        } catch (Throwable $e) {
            return ['error' => 'bad_request', 'message' => $e->getMessage()];
        }
    }

    private function aggregate(array $a): array
    {
        $bbox = $a['bbox'] ?? throw new InvalidArgumentException('bbox required');
        $res  = (int)($a['resolution'] ?? 7);
        $from = $a['from'] ?? null;
        $to   = $a['to'] ?? null;
        $svc  = app(H3AggregationService::class);
        $agg  = $svc->aggregateByBbox(
            $bbox,
            $res,
            $from,
            $to,
            $a['dataset_type'] ?? null,
            $a['time_of_day_start'] ?? null,
            $a['time_of_day_end'] ?? null,
            $a['severity'] ?? null,
            $a['confidence_level'] ?? null,
        );

        $cells = [];
        foreach ($agg as $h3 => $data) {
            $cells[] = [
                'h3' => $h3,
                'count' => $data['count'],
                'categories' => $data['categories'],
                'statistics' => $data['statistics'] ?? [],
            ];
        }
        return ['resolution' => $res,'cells' => $cells];
    }

    private function ingest(array $a): array
    {
        $dryRun = (bool)($a['dry_run'] ?? false);
        $months = [];

        if (array_key_exists('ym', $a) && $a['ym'] !== null) {
            $months[] = $this->normalizeMonth((string) $a['ym']);
        }

        if (!empty($a['months'])) {
            foreach ((array) $a['months'] as $month) {
                if ($month === null || $month === '') {
                    continue;
                }

                $months[] = $this->normalizeMonth((string) $month);
            }
        }

        $hasFrom = array_key_exists('from', $a) && $a['from'] !== null && $a['from'] !== '';
        $hasTo = array_key_exists('to', $a) && $a['to'] !== null && $a['to'] !== '';

        if ($hasFrom || $hasTo) {
            if (!$hasFrom || !$hasTo) {
                throw new InvalidArgumentException('Both from and to must be provided for range ingestion');
            }

            $months = array_merge($months, $this->expandRange((string) $a['from'], (string) $a['to']));
        }

        if (empty($months)) {
            throw new InvalidArgumentException('ym, months, or from/to range is required');
        }

        $months = array_values(array_unique($months));
        sort($months);

        $jobs = array_map(static fn (string $month) => new IngestDatasetRecords($month, $dryRun), $months);

        Cache::put('dataset-records:ingestion:running', true, now()->addMinutes(10));

        $pendingBatch = Bus::batch($jobs)
            ->name(sprintf('dataset-record-mcp-%s', now()->format('YmdHis')))
            ->allowFailures()
            ->then(static function (Batch $batch) use ($months, $dryRun): void {
                Log::info('Dataset record ingestion batch completed via MCP', [
                    'batch_id' => $batch->id,
                    'months' => $months,
                    'dry_run' => $dryRun,
                    'failed_jobs' => $batch->failedJobs,
                ]);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($months, $dryRun): void {
                Log::error('Dataset record ingestion batch failed via MCP', [
                    'batch_id' => $batch->id,
                    'months' => $months,
                    'dry_run' => $dryRun,
                    'error' => $exception->getMessage(),
                ]);
            })
            ->finally(static function (): void {
                Cache::forget('dataset-records:ingestion:running');
            });

        $batch = $pendingBatch->dispatch();

        return [
            'status' => 'queued',
            'months' => $months,
            'dry_run' => $dryRun,
            'batch_id' => $batch->id,
        ];
    }

    private function normalizeMonth(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('ym required YYYY-MM');
        }

        $date = Carbon::createFromFormat('Y-m', $value);
        if ($date === false) {
            throw new InvalidArgumentException('ym required YYYY-MM');
        }

        return $date->format('Y-m');
    }

    private function expandRange(string $from, string $to): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $this->normalizeMonth($from));
        $end = CarbonImmutable::createFromFormat('Y-m', $this->normalizeMonth($to));

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('from must be earlier than to');
        }

        $months = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function export(array $a): array
    {
        $res = (int)($a['resolution'] ?? 7);
        $bbox = $a['bbox'] ?? throw new InvalidArgumentException('bbox required');
        $agg = app(H3AggregationService::class)->aggregateByBbox(
            $bbox,
            $res,
            $a['from'] ?? null,
            $a['to'] ?? null,
            $a['dataset_type'] ?? null,
            $a['time_of_day_start'] ?? null,
            $a['time_of_day_end'] ?? null,
            $a['severity'] ?? null,
            $a['confidence_level'] ?? null,
        );
        $features = [];
        foreach ($agg as $h3 => $data) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'h3' => $h3,
                    'count' => $data['count'],
                    'statistics' => $data['statistics'] ?? [],
                ],
                'geometry' => null,
            ];
        }
        return ['type' => 'FeatureCollection','features' => $features];
    }

    private function categories(): array
    {
        $categories = DatasetRecord::query()
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->orderBy('category')
            ->pluck('category')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        return ['categories' => $categories];
    }

    private function ingestedMonths(): array
    {
        $rows = DatasetRecord::query()
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')
            ->orderBy('ym', 'desc')
            ->get()
            ->map(fn ($row) => ['month' => (string) $row->ym, 'count' => (int) ($row->c ?? 0)])
            ->all();

        return ['months' => $rows];
    }

    private function topCells(array $args): array
    {
        $bbox = $args['bbox'] ?? '-180,-90,180,90';
        $resolution = (int)($args['resolution'] ?? 7);
        $limit = max(1, (int)($args['limit'] ?? 5));
        $from = $args['from'] ?? null;
        $to = $args['to'] ?? null;
        $datasetType = $args['dataset_type'] ?? null;

        $agg = app(H3AggregationService::class)->aggregateByBbox(
            $bbox,
            $resolution,
            $from,
            $to,
            $datasetType,
            $args['time_of_day_start'] ?? null,
            $args['time_of_day_end'] ?? null,
            $args['severity'] ?? null,
            $args['confidence_level'] ?? null,
        );
        $cells = [];
        foreach ($agg as $h3 => $payload) {
            $cells[] = [
                'h3' => $h3,
                'count' => $payload['count'],
                'categories' => $payload['categories'],
                'statistics' => $payload['statistics'] ?? [],
            ];
        }

        usort($cells, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $cells = array_slice($cells, 0, $limit);

        return [
            'resolution' => $resolution,
            'limit' => $limit,
            'filters' => array_filter([
                'bbox' => $bbox,
                'from' => $from,
                'to' => $to,
                'dataset_type' => $datasetType,
            ]),
            'cells' => $cells,
        ];
    }
}
