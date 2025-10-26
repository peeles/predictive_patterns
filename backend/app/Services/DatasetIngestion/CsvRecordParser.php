<?php

declare(strict_types=1);

namespace App\Services\DatasetIngestion;

use Carbon\Carbon;

/**
 * Service for parsing and transforming CSV records from dataset archives.
 *
 * Handles row normalisation, coordinate parsing, and record validation.
 */
class CsvRecordParser
{
    /**
     * Combine a CSV row with its headers, trimming both keys and values.
     *
     * @param array<int, string> $headers
     * @param array<int, string|null> $row
     *
     * @return array<string, mixed>|null
     */
    public function combineRow(array $headers, array $row): ?array
    {
        $headerCount = count($headers);
        if ($headerCount === 0) {
            return null;
        }

        $rowCount = count($row);
        if ($rowCount < $headerCount) {
            $row = array_pad($row, $headerCount, null);
        } elseif ($rowCount > $headerCount) {
            $row = array_slice($row, 0, $headerCount);
        }

        $assoc = [];
        foreach ($headers as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }

            $value = $row[$index] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $assoc[$header] = $value;
        }

        return $assoc === [] ? null : $assoc;
    }

    /**
     * Transform a CSV row into an insertable payload for the datasets table.
     *
     * @param array<string, mixed> $row
     * @param callable $toH3 H3 index generator callback
     * @param array<string, bool> $seen Tracking array for duplicate detection
     * @param int $duplicateCount Duplicate counter (passed by reference)
     * @param int $invalidCount Invalid counter (passed by reference)
     * @param string $ingestedAt Timestamp for created_at/updated_at
     *
     * @return array<string, mixed>|null Insertable record or null if invalid
     */
    public function transformRow(
        array $row,
        callable $toH3,
        array &$seen,
        int &$duplicateCount,
        int &$invalidCount,
        string $ingestedAt
    ): ?array {
        $normalised = $this->normaliseRecordKeys($row);

        $month = $normalised['month'] ?? null;
        if ($month === null || $month === '') {
            $invalidCount++;

            return null;
        }

        $occurredAt = Carbon::createFromFormat('Y-m', (string) $month);
        if ($occurredAt === false) {
            $invalidCount++;

            return null;
        }
        $occurredAt = $occurredAt->startOfMonth();

        $lat = $this->parseCoordinate($normalised['latitude'] ?? $normalised['lat'] ?? null);
        $lng = $this->parseCoordinate($normalised['longitude'] ?? $normalised['lng'] ?? null);

        if ($lat === null || $lng === null) {
            $invalidCount++;

            return null;
        }

        $category = (string) ($normalised['dataset_type'] ?? $normalised['category'] ?? '');
        $category = $category !== '' ? $category : 'unknown';

        $identifier = trim((string) ($normalised['dataset_id'] ?? $normalised['id'] ?? ''));
        if ($identifier === '') {
            $location = (string) ($normalised['location'] ?? '');
            $identifier = $this->deterministicUuid(
                implode('|', [
                    $occurredAt->format('Y-m'),
                    $category,
                    $lat,
                    $lng,
                    $location,
                ])
            );
        }

        if (isset($seen[$identifier])) {
            $duplicateCount++;

            return null;
        }

        $seen[$identifier] = true;

        $raw = $this->encodeRaw($row);

        return [
            'id' => $identifier,
            'category' => $category,
            'occurred_at' => $occurredAt->toDateTimeString(),
            'lat' => $lat,
            'lng' => $lng,
            'h3_res6' => call_user_func($toH3, $lat, $lng, 6),
            'h3_res7' => call_user_func($toH3, $lat, $lng, 7),
            'h3_res8' => call_user_func($toH3, $lat, $lng, 8),
            'raw' => $raw,
            'created_at' => $ingestedAt,
            'updated_at' => $ingestedAt,
        ];
    }

    /**
     * Normalise the supplied row keys for easier lookups.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function normaliseRecordKeys(array $row): array
    {
        $normalised = [];

        foreach ($row as $key => $value) {
            $key = strtolower((string) $key);
            $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
            $key = trim($key, '_');

            if ($key === '') {
                continue;
            }

            $normalised[$key] = $value;
        }

        return $normalised;
    }

    /**
     * Trim and normalise the CSV header row from the dataset archive.
     *
     * @param array<int, string|null> $headers
     *
     * @return array<int, string>
     */
    public function normaliseHeaders(array $headers): array
    {
        return array_map(static function (?string $value) {
            $value = $value ?? '';
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '');

            return trim($value);
        }, $headers);
    }

    /**
     * Normalise latitude/longitude values, discarding invalid values.
     */
    private function parseCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 6);
    }

    /**
     * Generate a deterministic UUID from a seed string.
     */
    private function deterministicUuid(string $seed): string
    {
        $hash = substr(hash('sha1', $seed), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    /**
     * Encode a row as JSON for storage.
     *
     * @param array<string, mixed> $row
     */
    private function encodeRaw(array $row): string
    {
        $encoded = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }
}
