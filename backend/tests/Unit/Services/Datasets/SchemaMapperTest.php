<?php

namespace Tests\Unit\Services\Datasets;

use App\Services\Datasets\SchemaMapper;
use PHPUnit\Framework\TestCase;

class SchemaMapperTest extends TestCase
{
    private SchemaMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new SchemaMapper();
    }

    public function test_normalise_filters_and_requires_columns(): void
    {
        $normalised = $this->mapper->normalise([
            'timestamp' => ' observed_at ',
            'latitude' => 'lat',
            'longitude' => 'lng',
            'category' => ' type ',
            'risk' => '',
            'invalid' => 'ignored',
        ]);

        $this->assertSame([
            'timestamp' => 'observed_at',
            'latitude' => 'lat',
            'longitude' => 'lng',
            'category' => 'type',
        ], $normalised);
    }

    public function test_normalise_returns_empty_when_required_missing(): void
    {
        $this->assertSame([], $this->mapper->normalise([
            'timestamp' => 'observed_at',
            'latitude' => 'lat',
        ]));
    }

    public function test_summarise_derived_features_extracts_samples(): void
    {
        $summary = $this->mapper->summariseDerivedFeatures([
            'timestamp' => 'observed_at',
            'category' => 'type',
        ], ['observed_at', 'type'], [
            ['observed_at' => '2024-01-01T00:00:00Z', 'type' => 'Robbery'],
        ]);

        $this->assertSame([
            'timestamp' => [
                'column' => 'observed_at',
                'sample' => '2024-01-01T00:00:00Z',
            ],
            'category' => [
                'column' => 'type',
                'sample' => 'Robbery',
            ],
        ], $summary);
    }
}
