<?php

namespace App\Support\H3;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Lightweight polyfill for the ext-h3 binding used by the application services.
 */
class H3
{
    private ?string $indexScript = null;

    private ?string $boundaryScript = null;

    /**
     * Convert latitude and longitude to an H3 index at the specified resolution.
     *
     * @param float $lat
     * @param float $lng
     * @param int $resolution
     *
     * @return string
     */
    public function latLngToCell(float $lat, float $lng, int $resolution): string
    {
        return $this->geoToH3($lat, $lng, $resolution);
    }

    /**
     * Convert latitude and longitude to an H3 index at the specified resolution.
     *
     * @param float $lat
     * @param float $lng
     * @param int $resolution
     *
     * @return string
     */
    public function geoToH3(float $lat, float $lng, int $resolution): string
    {
        $script = $this->resolveIndexScript();

        $process = new Process([
            'node',
            $script,
            (string) $lat,
            (string) $lng,
            (string) $resolution,
        ]);

        $process->setTimeout(5.0);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : 'Unknown error from Node H3 index helper';

            throw new RuntimeException(sprintf('Node H3 index helper failed: %s', $message));
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            throw new RuntimeException('Node H3 index helper returned an empty response');
        }

        return $output;
    }

    /**
     * Get the boundary of an H3 cell.
     *
     * @param string $index
     * @param bool $geoJson
     *
     * @return array[]
     */
    public function cellToBoundary(string $index, bool $geoJson = false): array
    {
        $boundary = $this->fetchBoundary($index);

        if ($geoJson) {
            return $boundary;
        }

        return array_map(
            static fn (array $vertex): array => [
                'lat' => (float) $vertex[1],
                'lng' => (float) $vertex[0],
            ],
            $boundary
        );
    }

    /**
     * Get the boundary of an H3 cell in GeoJSON format.
     *
     * @param string $index
     * @param bool $geoJson
     *
     * @return array[]
     */
    public function cellToGeoBoundary(string $index, bool $geoJson = false): array
    {
        return $this->cellToBoundary($index, $geoJson);
    }

    /**
     * Fetch the boundary vertices from the Node.js helper script in GeoJSON format.
     *
     * @param string $index
     *
     * @return list<array{0: float, 1: float}>
     */
    public function h3ToGeoBoundary(string $index): array
    {
        return $this->cellToGeoBoundary($index, true);
    }

    /**
     * Fetch the boundary vertices from the Node.js helper script.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function fetchBoundary(string $index): array
    {
        $script = $this->resolveBoundaryScript();

        $process = new Process(['node', $script, $index]);
        $process->setTimeout(5.0);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : 'Unknown error from Node H3 boundary helper';

            throw new RuntimeException(sprintf('Node H3 boundary helper failed: %s', $message));
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            throw new RuntimeException('Node H3 boundary helper returned an empty response');
        }

        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode boundary payload from Node H3 helper', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected boundary payload from Node H3 helper');
        }

        return array_map(
            static function ($vertex): array {
                if (!is_array($vertex) || !isset($vertex[0], $vertex[1])) {
                    throw new RuntimeException('Invalid vertex received from Node H3 helper');
                }

                return [(float) $vertex[0], (float) $vertex[1]];
            },
            $decoded
        );
    }

    /**
     * Resolve the path to the Node.js script used to convert lat/lng to H3 index.
     *
     * @return string
     */
    private function resolveIndexScript(): string
    {
        if ($this->indexScript !== null) {
            return $this->indexScript;
        }

        return $this->indexScript = $this->locateScript('H3-index.cjs');
    }

    /**
     * Resolve the path to the Node.js script used to fetch H3 cell boundaries.
     *
     * @return string
     */
    private function resolveBoundaryScript(): string
    {
        if ($this->boundaryScript !== null) {
            return $this->boundaryScript;
        }

        return $this->boundaryScript = $this->locateScript('h3-boundary.cjs');
    }

    /**
     * Locate the specified Node.js script file in known locations.
     *
     * @param string $fileName
     *
     * @return string
     */
    private function locateScript(string $fileName): string
    {
        $candidates = [];

        if (function_exists('base_path')) {
            $candidates[] = base_path("backend/scripts/{$fileName}");
            $candidates[] = base_path("scripts/{$fileName}");
        }

        $candidates[] = dirname(__DIR__, 3)."/scripts/{$fileName}";
        $candidates[] = dirname(__DIR__, 4)."/backend/scripts/{$fileName}";

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('Unable to locate Node H3 helper: %s', $fileName));
    }
}
