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

    public function latLngToCell(float $lat, float $lng, int $resolution): string
    {
        return $this->geoToH3($lat, $lng, $resolution);
    }

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

    public function cellToGeoBoundary(string $index): array
    {
        return $this->cellToBoundary($index, false);
    }

    public function h3ToGeoBoundary(string $index): array
    {
        return $this->cellToGeoBoundary($index);
    }

    /**
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

    private function resolveIndexScript(): string
    {
        if ($this->indexScript !== null) {
            return $this->indexScript;
        }

        return $this->indexScript = $this->locateScript('H3-index.cjs');
    }

    private function resolveBoundaryScript(): string
    {
        if ($this->boundaryScript !== null) {
            return $this->boundaryScript;
        }

        return $this->boundaryScript = $this->locateScript('h3-boundary.cjs');
    }

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
