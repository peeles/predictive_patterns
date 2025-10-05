<?php

namespace App\Services\Datasets;

class SchemaMapper
{
    /**
     * Normalises and validates the schema mapping input.
     *
     * @param mixed $schema
     *
     * @return array<string, string>
     */
    public function normalise(mixed $schema): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $allowed = ['timestamp', 'latitude', 'longitude', 'category', 'risk', 'label'];
        $normalised = [];

        foreach ($allowed as $key) {
            $value = $schema[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $normalised[$key] = $value;
        }

        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $required) {
            if (! array_key_exists($required, $normalised)) {
                return [];
            }
        }

        return $normalised;
    }

    /**
     * Build a summary of derived features based on the provided schema mapping and preview rows.
     *
     * @param array<string, string> $schema
     * @param list<string> $headers
     * @param array<int, array<string, mixed>> $previewRows
     *
     * @return array<string, array<string, mixed>>
     */
    public function summariseDerivedFeatures(array $schema, array $headers, array $previewRows): array
    {
        if ($schema === []) {
            return [];
        }

        $summary = [];

        foreach ($schema as $key => $column) {
            if (! is_string($key) || ! is_string($column) || trim($column) === '') {
                continue;
            }

            $sample = null;

            foreach ($previewRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! array_key_exists($column, $row)) {
                    continue;
                }

                $value = $row[$column];

                if ($value === null) {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                $sample = $value;
                break;
            }

            $summary[$key] = ['column' => $column];

            if ($sample !== null) {
                $summary[$key]['sample'] = $sample;
            }
        }

        return $summary;
    }
}
