<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;

/**
 * Centralised service for resolving and normalising dataset column mappings.
 *
 * This service handles the mapping between user-defined column names and the
 * expected schema fields, applying normalisation to handle variations in
 * column naming conventions (case, spacing, special characters).
 */
class ColumnMapper
{
    /**
     * Resolve the complete column map from a dataset's schema mapping.
     *
     * @return array<string, string> Map of field names to normalised column names
     */
    public function resolveColumnMap(Dataset $dataset): array
    {
        $mapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];

        return [
            'timestamp' => $this->resolveMappedColumn($mapping, 'timestamp', 'timestamp'),
            'latitude' => $this->resolveMappedColumn($mapping, 'latitude', 'latitude'),
            'longitude' => $this->resolveMappedColumn($mapping, 'longitude', 'longitude'),
            'category' => $this->resolveMappedColumn($mapping, 'category', 'category'),
            'risk_score' => $this->resolveMappedColumn($mapping, 'risk', 'risk_score'),
            'label' => $this->resolveMappedColumn($mapping, 'label', 'label'),
        ];
    }

    /**
     * Resolve a single mapped column, falling back to the default if not found.
     *
     * @param array<string, mixed> $mapping User-defined schema mapping
     * @param string $key The mapping key to look up
     * @param string $default Default column name if mapping not found
     *
     * @return string Normalised column name
     */
    public function resolveMappedColumn(array $mapping, string $key, string $default): string
    {
        $value = $mapping[$key] ?? $default;

        if (! is_string($value) || trim($value) === '') {
            $value = $default;
        }

        $normalised = $this->normaliseColumnName($value);

        if ($normalised === '') {
            $normalised = $this->normaliseColumnName($default);
        }

        if ($normalised === '') {
            $normalised = $default;
        }

        return $normalised;
    }

    /**
     * Normalise a column name to a consistent format.
     *
     * - Removes UTF-8 BOM if present
     * - Converts to lowercase
     * - Replaces hyphens and slashes with spaces
     * - Converts non-alphanumeric characters to underscores
     * - Collapses multiple underscores to single underscore
     * - Trims leading/trailing underscores
     *
     * @param string $column Raw column name from dataset
     *
     * @return string Normalised column name (lowercase, underscored)
     */
    public function normaliseColumnName(string $column): string
    {
        // Remove UTF-8 BOM if present
        $column = preg_replace('/^\xEF\xBB\xBF/u', '', $column) ?? $column;
        $column = trim($column);

        if ($column === '') {
            return '';
        }

        // Normalise to lowercase and replace common separators
        $column = mb_strtolower($column, 'UTF-8');
        $column = str_replace(['-', '/'], ' ', $column);

        // Convert non-alphanumeric to underscores
        $column = preg_replace('/[^a-z0-9]+/u', '_', $column) ?? $column;

        // Collapse multiple underscores
        $column = preg_replace('/_+/', '_', $column) ?? $column;

        return trim($column, '_');
    }
}
