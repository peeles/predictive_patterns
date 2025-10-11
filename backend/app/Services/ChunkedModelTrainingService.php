<?php

namespace App\Services;

use App\Models\Dataset;
use Illuminate\Support\Facades\Storage;

class ChunkedModelTrainingService
{
    private const CHUNK_SIZE = 10000; // Process 10k rows at a time

    public function supportsChunkedTraining(Dataset $dataset): bool
    {
        // Check if dataset is large enough to benefit from chunking
        if ($dataset->file_path === null) {
            return false;
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($dataset->file_path)) {
            return false;
        }

        $size = $disk->size($dataset->file_path);

        // Use chunking for files > 50MB
        return $size > 50 * 1024 * 1024;
    }

    public function getOptimalChunkSize(Dataset $dataset): int
    {
        $availableMemory = $this->getAvailableMemory();

        // Use ~40% of available memory for each chunk
        $memoryPerChunk = (int) ($availableMemory * 0.4);

        // Estimate rows per MB (rough heuristic)
        $estimatedRowsPerMb = 1000;

        return max(1000, min(self::CHUNK_SIZE, (int) ($memoryPerChunk / 1024 / 1024 * $estimatedRowsPerMb)));
    }

    private function getAvailableMemory(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }
}
