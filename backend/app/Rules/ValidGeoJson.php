<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use JsonException;

class ValidGeoJson implements ValidationRule
{
    private const GEOJSON_MIME_TYPES = [
        'application/geo+json',
        'application/json',
        'text/json',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if (! $this->shouldValidateFile($value)) {
            return;
        }

        $contents = @file_get_contents($value->getRealPath());

        if ($contents === false) {
            $fail('The :attribute could not be read.');

            return;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $fail('The :attribute must contain valid GeoJSON.');

            return;
        }

        if (! is_array($payload)) {
            $fail('The :attribute must contain a GeoJSON object.');

            return;
        }

        foreach ($this->validateGeoJson($payload) as $error) {
            $fail($error);
        }
    }

    private function shouldValidateFile(UploadedFile $file): bool
    {
        $mimeType = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($mimeType, self::GEOJSON_MIME_TYPES, true)) {
            return true;
        }

        return in_array($extension, ['geojson', 'json'], true);
    }

    /**
     * @return list<string>
     */
    private function validateGeoJson(array $payload): array
    {
        $errors = [];

        if (isset($payload['crs'])) {
            $errors = array_merge($errors, $this->validateCrs($payload['crs']));
        }

        $type = $payload['type'] ?? null;

        if (! is_string($type)) {
            $errors[] = 'The uploaded GeoJSON is missing a valid "type" member.';

            return $errors;
        }

        return array_merge($errors, match ($type) {
            'FeatureCollection' => $this->validateFeatureCollection($payload),
            'Feature' => $this->validateFeature($payload, 'feature'),
            default => $this->validateGeometry($payload, 'geometry'),
        });
    }

    /**
     * @return list<string>
     */
    private function validateCrs(mixed $crs): array
    {
        if (! is_array($crs)) {
            return ['GeoJSON CRS definition must be an object.'];
        }

        $type = $crs['type'] ?? null;
        $name = $crs['properties']['name'] ?? null;

        if ($type !== 'name' || ! is_string($name)) {
            return ['GeoJSON CRS must use the named EPSG identifier.'];
        }

        $normalised = strtoupper(trim($name));

        if (! in_array($normalised, ['EPSG:4326', 'URN:OGC:DEF:CRS:EPSG::4326'], true)) {
            return ['GeoJSON must declare the WGS84 (EPSG:4326) coordinate reference system.'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateFeatureCollection(array $payload): array
    {
        if (! array_key_exists('features', $payload)) {
            return ['GeoJSON feature collections must include a "features" array.'];
        }

        if (! is_array($payload['features'])) {
            return ['GeoJSON "features" member must be an array.'];
        }

        $errors = [];

        foreach ($payload['features'] as $index => $feature) {
            $identifier = sprintf('features[%d]', $index);
            $errors = array_merge($errors, $this->validateFeature($feature, $identifier));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateFeature(mixed $feature, string $identifier): array
    {
        if (! is_array($feature)) {
            return [sprintf('GeoJSON feature %s must be an object.', $identifier)];
        }

        if (($feature['type'] ?? null) !== 'Feature') {
            return [sprintf('GeoJSON feature %s must declare type "Feature".', $identifier)];
        }

        $geometry = $feature['geometry'] ?? null;

        if ($geometry === null) {
            return [];
        }

        $errors = $this->validateGeometry($geometry, sprintf('%s.geometry', $identifier));

        if (isset($feature['crs'])) {
            $errors = array_merge($errors, $this->validateCrs($feature['crs']));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateGeometry(mixed $geometry, string $identifier): array
    {
        if (! is_array($geometry)) {
            return [sprintf('GeoJSON geometry %s must be an object.', $identifier)];
        }

        $type = $geometry['type'] ?? null;

        if (! is_string($type)) {
            return [sprintf('GeoJSON geometry %s must declare a type.', $identifier)];
        }

        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'GeometryCollection') {
            if (! isset($geometry['geometries']) || ! is_array($geometry['geometries'])) {
                return [sprintf('GeometryCollection %s must include a "geometries" array.', $identifier)];
            }

            $errors = [];

            foreach ($geometry['geometries'] as $index => $member) {
                $errors = array_merge(
                    $errors,
                    $this->validateGeometry($member, sprintf('%s.geometries[%d]', $identifier, $index))
                );
            }

            return $errors;
        }

        if ($coordinates === null) {
            return [sprintf('GeoJSON geometry %s must include "coordinates".', $identifier)];
        }

        return match ($type) {
            'Point' => $this->validatePosition($coordinates, $identifier),
            'MultiPoint' => $this->validatePositions($coordinates, $identifier, 1),
            'LineString' => $this->validatePositions($coordinates, $identifier, 2),
            'MultiLineString' => $this->validateMultiPositions($coordinates, $identifier, 2),
            'Polygon' => $this->validatePolygon($coordinates, $identifier),
            'MultiPolygon' => $this->validateMultiPolygon($coordinates, $identifier),
            default => [sprintf('Geometry %s uses unsupported type "%s".', $identifier, $type)],
        };
    }

    /**
     * @param mixed $coordinates
     * @return list<string>
     */
    private function validatePosition(mixed $coordinates, string $identifier): array
    {
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return [sprintf('Geometry %s must contain a longitude and latitude coordinate.', $identifier)];
        }

        $lon = $coordinates[0];
        $lat = $coordinates[1];

        if (! is_numeric($lon) || ! is_numeric($lat)) {
            return [sprintf('Geometry %s coordinates must be numeric.', $identifier)];
        }

        $lon = (float) $lon;
        $lat = (float) $lat;

        if ($lon < -180.0 || $lon > 180.0) {
            return [sprintf('Geometry %s longitude must be between -180 and 180 degrees.', $identifier)];
        }

        if ($lat < -90.0 || $lat > 90.0) {
            return [sprintf('Geometry %s latitude must be between -90 and 90 degrees.', $identifier)];
        }

        return [];
    }

    /**
     * @param mixed $positions
     * @return list<string>
     */
    private function validatePositions(mixed $positions, string $identifier, int $minimum): array
    {
        if (! is_array($positions)) {
            return [sprintf('Geometry %s must contain an array of coordinates.', $identifier)];
        }

        if (count($positions) < $minimum) {
            return [sprintf('Geometry %s must contain at least %d positions.', $identifier, $minimum)];
        }

        $errors = [];

        foreach ($positions as $index => $position) {
            $errors = array_merge(
                $errors,
                $this->validatePosition($position, sprintf('%s[%d]', $identifier, $index))
            );
        }

        return $errors;
    }

    /**
     * @param mixed $parts
     * @return list<string>
     */
    private function validateMultiPositions(mixed $parts, string $identifier, int $minimum): array
    {
        if (! is_array($parts)) {
            return [sprintf('Geometry %s must contain an array of coordinate arrays.', $identifier)];
        }

        if ($parts === []) {
            return [sprintf('Geometry %s must not be empty.', $identifier)];
        }

        $errors = [];

        foreach ($parts as $index => $segment) {
            $errors = array_merge(
                $errors,
                $this->validatePositions($segment, sprintf('%s[%d]', $identifier, $index), $minimum)
            );
        }

        return $errors;
    }

    /**
     * @param mixed $polygon
     * @return list<string>
     */
    private function validatePolygon(mixed $polygon, string $identifier): array
    {
        if (! is_array($polygon)) {
            return [sprintf('Polygon %s must contain linear ring coordinate arrays.', $identifier)];
        }

        if ($polygon === []) {
            return [sprintf('Polygon %s must contain at least one linear ring.', $identifier)];
        }

        $errors = [];

        foreach ($polygon as $index => $ring) {
            $ringIdentifier = sprintf('%s[%d]', $identifier, $index);

            $ringErrors = $this->validatePositions($ring, $ringIdentifier, 4);

            if ($ringErrors !== []) {
                $errors = array_merge($errors, $ringErrors);

                continue;
            }

            $first = $ring[0];
            $last = $ring[count($ring) - 1];

            if (! $this->positionsEquivalent($first, $last)) {
                $errors[] = sprintf('Polygon %s must have a closed linear ring.', $ringIdentifier);
            }
        }

        return $errors;
    }

    /**
     * @param mixed $polygons
     * @return list<string>
     */
    private function validateMultiPolygon(mixed $polygons, string $identifier): array
    {
        if (! is_array($polygons)) {
            return [sprintf('MultiPolygon %s must contain polygon coordinate arrays.', $identifier)];
        }

        if ($polygons === []) {
            return [sprintf('MultiPolygon %s must not be empty.', $identifier)];
        }

        $errors = [];

        foreach ($polygons as $index => $polygon) {
            $errors = array_merge(
                $errors,
                $this->validatePolygon($polygon, sprintf('%s[%d]', $identifier, $index))
            );
        }

        return $errors;
    }

    private function positionsEquivalent(mixed $first, mixed $last): bool
    {
        if (! is_array($first) || ! is_array($last) || count($first) < 2 || count($last) < 2) {
            return false;
        }

        $lonDelta = abs((float) $first[0] - (float) $last[0]);
        $latDelta = abs((float) $first[1] - (float) $last[1]);

        return $lonDelta <= 1e-7 && $latDelta <= 1e-7;
    }
}
