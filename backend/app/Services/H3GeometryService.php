<?php



namespace App\Services;

use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Normalises access to H3 boundary helpers across the different PHP bindings.
 */
class H3GeometryService
{
    /**
     * Callback that resolves the boundary vertices for a given H3 index.
     *
     * @var Closure(string):array
     */
    private Closure $boundaryResolver;

    /**
     * Indicates whether the resolver returns GeoJSON ordered vertices.
     */
    private bool $geoJsonOrder;

    /**
     * @throws RuntimeException When the application cannot resolve a boundary helper
     */
    public function __construct()
    {
        [$resolver, $geoJsonOrder] = $this->resolveBoundaryResolver();
        $this->boundaryResolver = $resolver;
        $this->geoJsonOrder = $geoJsonOrder;
    }

    /**
     * Build a closed polygon suitable for GeoJSON responses for the supplied H3 index.
     *
     * @return array<int, array{0: float, 1: float}>
     *
     * @throws RuntimeException When the H3 extension returns an unexpected payload
     */
    public function polygonCoordinates(string $h3): array
    {
        $boundary = ($this->boundaryResolver)($h3);
        if (!is_array($boundary)) {
            throw new RuntimeException('Invalid boundary response from H3 library');
        }

        $coordinates = [];

        if ($this->geoJsonOrder) {
            foreach ($boundary as $vertex) {
                if (!is_array($vertex) || !isset($vertex[0], $vertex[1])) {
                    throw new RuntimeException('Unexpected vertex format from H3 boundary resolver');
                }

                $coordinates[] = [(float) $vertex[0], (float) $vertex[1]];
            }
        } else {
            foreach ($boundary as $vertex) {
                if (!is_array($vertex)) {
                    throw new RuntimeException('Unexpected vertex format from H3 boundary resolver');
                }

                $lng = $this->extractLongitude($vertex);
                $lat = $this->extractLatitude($vertex);

                $coordinates[] = [$lng, $lat];
            }
        }

        if ($coordinates) {
            $first = $coordinates[0];
            $last = end($coordinates);
            if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                $coordinates[] = $first;
            }
        }

        return $coordinates;
    }

    /**
     * Resolve a callable to generate boundary coordinates and whether its output follows GeoJSON order.
     *
     * @return array{0: Closure(string):array, 1: bool}
     *
     * @throws RuntimeException When no compatible binding is available
     */
    private function resolveBoundaryResolver(): array
    {
        if (class_exists('\\H3\\H3')) {
            $h3 = new \App\Support\H3\H3();

            if (method_exists($h3, 'cellToBoundary')) {
                return [fn (string $index): array => $h3->cellToBoundary($index, true), true];
            }

            if (method_exists($h3, 'cellToGeoBoundary')) {
                return [fn (string $index): array => $h3->cellToGeoBoundary($index), false];
            }

            if (method_exists($h3, 'h3ToGeoBoundary')) {
                return [fn (string $index): array => $h3->h3ToGeoBoundary($index), false];
            }
        }

        if (function_exists('H3\\cellToBoundary')) {
            return [fn (string $index): array => \H3\cellToBoundary($index, true), true];
        }

        if (function_exists('H3\\cellToGeoBoundary')) {
            return [fn (string $index): array => \H3\cellToGeoBoundary($index), false];
        }

        if (function_exists('H3\\h3ToGeoBoundary')) {
            return [fn (string $index): array => \H3\h3ToGeoBoundary($index), false];
        }

        if (function_exists('cellToBoundary')) {
            return [fn (string $index): array => cellToBoundary($index, true), true];
        }

        if (function_exists('cellToGeoBoundary')) {
            return [fn (string $index): array => cellToGeoBoundary($index), false];
        }

        if (function_exists('h3ToGeoBoundary')) {
            return [fn (string $index): array => h3ToGeoBoundary($index), false];
        }

        if ($this->nodeBoundaryHelperAvailable()) {
            return [$this->buildNodeBoundaryResolver(), true];
        }

        throw new RuntimeException('H3 boundary conversion is not available');
    }

    private function nodeBoundaryHelperAvailable(): bool
    {
        $script = $this->nodeBoundaryScript();

        return is_file($script) && is_readable($script);
    }

    private function nodeBoundaryScript(): string
    {
        return base_path('scripts/h3-boundary.cjs');
    }

    /**
     * @return Closure(string): array
     */
    private function buildNodeBoundaryResolver(): Closure
    {
        $script = $this->nodeBoundaryScript();

        return function (string $index) use ($script): array {
            $process = new Process(['node', $script, $index]);
            $process->setTimeout(5.0);
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());
                $message = $errorOutput !== '' ? $errorOutput : 'Unknown error from Node boundary helper';

                throw new RuntimeException(sprintf('Node H3 boundary helper failed: %s', $message));
            }

            $output = trim($process->getOutput());

            if ($output === '') {
                throw new RuntimeException('Node H3 boundary helper returned an empty response');
            }

            try {
                $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Failed to decode boundary data from Node helper', 0, $exception);
            }

            if (!is_array($decoded)) {
                throw new RuntimeException('Unexpected boundary payload received from Node helper');
            }

            return $decoded;
        };
    }

    /**
     * Extract the longitude component from the raw vertex structure returned by the H3 extension.
     *
     * @param array<int|string, mixed> $vertex
     */
    private function extractLongitude(array $vertex): float
    {
        if (array_key_exists('lng', $vertex)) {
            return (float) $vertex['lng'];
        }

        if (array_key_exists('lon', $vertex)) {
            return (float) $vertex['lon'];
        }

        if (array_key_exists('longitude', $vertex)) {
            return (float) $vertex['longitude'];
        }

        if (array_key_exists(1, $vertex)) {
            return (float) $vertex[1];
        }

        throw new RuntimeException('Longitude missing from H3 boundary vertex');
    }

    /**
     * Extract the latitude component from the raw vertex structure returned by the H3 extension.
     *
     * @param array<int|string, mixed> $vertex
     */
    private function extractLatitude(array $vertex): float
    {
        if (array_key_exists('lat', $vertex)) {
            return (float) $vertex['lat'];
        }

        if (array_key_exists('latitude', $vertex)) {
            return (float) $vertex['latitude'];
        }

        if (array_key_exists(0, $vertex)) {
            return (float) $vertex[0];
        }

        throw new RuntimeException('Latitude missing from H3 boundary vertex');
    }
}
