<?php

namespace App\Support;

use Generator;
use JsonException;
use RuntimeException;
use SplTempFileObject;

class FeatureBuffer implements \IteratorAggregate, \Countable
{
    private const TEMPFILE_MEMORY_LIMIT = 262_144; // 256 KB before spilling to disk

    private readonly SplTempFileObject $file;
    private int $featureCount = 0;
    private int $rowCount = 0;

    public function __construct()
    {
        $this->file = new SplTempFileObject(self::TEMPFILE_MEMORY_LIMIT);
        $this->file->setFlags(SplTempFileObject::DROP_NEW_LINE);
    }

    public function append(array $features, int $label): void
    {
        if ($this->featureCount === 0) {
            $this->featureCount = count($features);
        }

        $record = [
            'features' => array_map(static fn ($value) => (float) $value, $features),
            'label' => $label > 0 ? 1 : 0,
        ];

        try {
            $encoded = json_encode($record, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode buffered features.', 0, $exception);
        }

        $this->file->fwrite($encoded . "\n");
        $this->rowCount++;

        if (($this->rowCount % 5_000) === 0) {
            gc_collect_cycles();
        }
    }

    public function getIterator(): Generator
    {
        $this->file->rewind();
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
                throw new RuntimeException('Failed to decode buffered features.', 0, $exception);
            }

            if (! is_array($data) || ! isset($data['features'], $data['label'])) {
                continue;
            }

            yield [
                'features' => array_map(static fn ($value) => (float) $value, $data['features']),
                'label' => (int) $data['label'],
            ];

            $processed++;

            if (($processed % 10_000) === 0) {
                gc_collect_cycles();
            }
        }
    }

    public function count(): int
    {
        return $this->rowCount;
    }

    public function featureCount(): int
    {
        return $this->featureCount;
    }
}
