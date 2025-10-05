<?php

namespace App\Services\Datasets;

use Illuminate\Support\LazyCollection;

class CsvParser
{
    /**
     * Read dataset rows from the given file path based on its MIME type or extension.
     *
     * @param string $path
     * @param string|null $mimeType
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function readDatasetRows(string $path, ?string $mimeType): LazyCollection
    {
        $mimeType = $mimeType !== null ? strtolower($mimeType) : null;

        if ($mimeType !== null && str_contains($mimeType, 'json')) {
            return LazyCollection::empty();
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['json', 'geojson'], true)) {
            return LazyCollection::empty();
        }

        return $this->readCsvRows($path);
    }

    /**
     * Read rows from a CSV file at the given path.
     *
     * @param string $path
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function readCsvRows(string $path): LazyCollection
    {
        return LazyCollection::make(function () use ($path) {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                $headers = null;

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

                    yield $assoc;
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * Normalise CSV headers by removing BOM characters and trimming whitespace.
     *
     * @param array<int, string|null> $headers
     *
     * @return list<string>
     */
    public function normaliseHeaders(array $headers): array
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
     * Check if a CSV row is empty (all values are null or empty strings).
     *
     * @param array<int, string|null> $row
     */
    public function isEmptyRow(array $row): bool
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
     * Combine CSV row values with headers to create an associative array.
     *
     * @param list<string> $headers
     * @param array<int, string|null> $row
     *
     * @return array<string, string|null>|null
     */
    public function combineRow(array $headers, array $row): ?array
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
            if ($value !== null && $value !== '') {
                return $assoc;
            }
        }

        return null;
    }
}
