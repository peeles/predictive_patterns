<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use function array_is_list;

class DatasetPreviewService
{
    private const PREVIEW_LIMIT = 5;

    /**
     * Generate a small summary of the supplied dataset file, including the row count and a preview of the
     * first few rows. The service currently supports CSV and JSON/GeoJSON payloads.
     *
     * @return array{headers: list<string>, row_count: int, preview_rows: list<array<string, mixed>>}
     */
    public function summarise(string $path, ?string $mimeType = null): array
    {
        $mimeType = $mimeType !== null ? strtolower($mimeType) : null;

        if ($mimeType !== null && str_contains($mimeType, 'json')) {
            return $this->summariseJson($path);
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json' || $extension === 'geojson') {
            return $this->summariseJson($path);
        }

        return $this->summariseCsv($path);
    }

    /**
     * @return array{headers: list<string>, row_count: int, preview_rows: list<array<string, mixed>>}
     */
    private function summariseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s" for preview generation.', $path));
        }

        $headers = null;
        $rowCount = 0;
        $preview = [];

        try {
            while (($row = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $headers = $this->normaliseHeaders($row);

                    if ($headers === []) {
                        break;
                    }

                    continue;
                }

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $assoc = $this->combineRow($headers, $row);

                if ($assoc === null || $assoc === []) {
                    continue;
                }

                $rowCount++;

                if (count($preview) < self::PREVIEW_LIMIT) {
                    $preview[] = $assoc;
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'headers' => $headers ?? [],
            'row_count' => $rowCount,
            'preview_rows' => $preview,
        ];
    }

    /**
     * @return array{headers: list<string>, row_count: int, preview_rows: list<array<string, mixed>>}
     */
    private function summariseJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read dataset file "%s" for preview generation.', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'headers' => [],
                'row_count' => 0,
                'preview_rows' => [],
            ];
        }

        if (is_array($decoded)) {
            if (array_key_exists('type', $decoded) && Str::lower((string) $decoded['type']) === 'featurecollection') {
                $features = isset($decoded['features']) && is_array($decoded['features']) ? $decoded['features'] : [];

                $rows = [];

                foreach ($features as $feature) {
                    if (!is_array($feature)) {
                        continue;
                    }

                    $rows[] = [
                        'type' => $feature['type'] ?? null,
                        'properties' => $feature['properties'] ?? null,
                        'geometry' => $feature['geometry'] ?? null,
                    ];
                }

                return [
                    'headers' => ['type', 'properties', 'geometry'],
                    'row_count' => count($rows),
                    'preview_rows' => array_slice($rows, 0, self::PREVIEW_LIMIT),
                ];
            }

            if (array_is_list($decoded)) {
                $rows = [];

                foreach ($decoded as $entry) {
                    if (is_array($entry)) {
                        $rows[] = $entry;
                    } else {
                        $rows[] = ['value' => $entry];
                    }
                }

                $preview = array_slice($rows, 0, self::PREVIEW_LIMIT);

                $headers = $preview !== [] ? array_keys($preview[0]) : [];

                return [
                    'headers' => $headers,
                    'row_count' => count($rows),
                    'preview_rows' => $preview,
                ];
            }

            return [
                'headers' => array_keys($decoded),
                'row_count' => 1,
                'preview_rows' => [$decoded],
            ];
        }

        return [
            'headers' => [],
            'row_count' => 0,
            'preview_rows' => [],
        ];
    }

    /**
     * @param array<int, string|null> $headers
     * @return list<string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $header) {
            if ($header === null) {
                $normalised[] = '';
                continue;
            }

            $value = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $value = trim((string) $value);

            $normalised[] = $value;
        }

        return $normalised;
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value === null) {
                continue;
            }

            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $headers
     * @param array<int, string|null> $row
     * @return array<string, string|null>|null
     */
    private function combineRow(array $headers, array $row): ?array
    {
        if ($headers === []) {
            return null;
        }

        $assoc = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = $row[$index] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $assoc[$header] = $value;
        }

        foreach ($assoc as $value) {
            if ($value !== null && ($value !== '')) {
                return $assoc;
            }
        }

        return null;
    }
}
