<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Generator;
use JsonException;
use RuntimeException;
use SplTempFileObject;
use Throwable;

class DatasetRowBuffer implements \IteratorAggregate, \Countable
{
    public function __construct(
        private readonly SplTempFileObject $file,
        private readonly bool $includeTimestamps,
        private readonly float $threshold,
        private readonly float $maxRisk,
        private readonly bool $forceMaxRiskPositive,
        private readonly int $rowCount
    ) {
    }

    public function count(): int
    {
        return $this->rowCount;
    }

    public function getIterator(): Generator
    {
        $this->file->rewind();
        $positiveForced = false;
        $processed = 0;

        while (! $this->file->eof()) {
            $line = $this->file->fgets();

            if ($line === false) {
                break;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Failed to decode buffered dataset row.', 0, $exception);
            }

            if (! is_array($data) || ! array_key_exists('features', $data) || ! array_key_exists('risk', $data)) {
                continue;
            }

            $risk = (float) $data['risk'];
            $rawLabel = $data['raw_label'] ?? null;
            $label = $this->resolveLabel($rawLabel, $risk);

            if ($this->forceMaxRiskPositive && ! $positiveForced && abs($risk - $this->maxRisk) < 1e-9) {
                $label = 1;
                $positiveForced = true;
            }

            $row = [
                'features' => array_map(static fn ($value) => (float) $value, $data['features']),
                'label' => $label,
            ];

            if ($this->includeTimestamps && isset($data['timestamp']) && is_string($data['timestamp'])) {
                try {
                    $row['timestamp'] = new CarbonImmutable($data['timestamp']);
                } catch (Throwable) {
                    // Ignore invalid timestamps during replay.
                }
            }

            yield $row;

            $processed++;

            if (($processed % 10_000) === 0) {
                gc_collect_cycles();
            }
        }
    }

    private function resolveLabel(mixed $rawLabel, float $risk): int
    {
        if ($rawLabel !== null) {
            if (is_numeric($rawLabel)) {
                return (int) ((float) $rawLabel > 0 ? 1 : 0);
            }

            if (is_string($rawLabel) && is_numeric($rawLabel)) {
                return (int) ((float) $rawLabel > 0 ? 1 : 0);
            }
        }

        if ($this->threshold > 1.0) {
            return 0;
        }

        return ($risk >= $this->threshold && $risk > 0.0) ? 1 : 0;
    }
}
