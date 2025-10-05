<?php

namespace Tests\Unit\Services\Datasets;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Services\Datasets\CsvParser;
use App\Services\Datasets\FeatureGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeatureGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private FeatureGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new FeatureGenerator(new CsvParser());
    }

    public function test_build_feature_from_row_generates_geometry_and_properties(): void
    {
        $dataset = Dataset::factory()->create();

        $feature = $this->generator->buildFeatureFromRow($dataset, [
            'timestamp' => 'observed_at',
            'latitude' => 'lat',
            'longitude' => 'lng',
            'category' => 'type',
        ], [
            'observed_at' => '2024-01-01T00:00:00Z',
            'lat' => '51.5',
            'lng' => '-0.1',
            'type' => 'Robbery',
        ], 0);

        $this->assertNotNull($feature);
        $this->assertSame('Robbery', $feature['name']);
        $this->assertSame([-0.1, 51.5], $feature['geometry']['coordinates']);
        $this->assertArrayHasKey('timestamp', $feature['properties']);
    }

    public function test_populate_from_mapping_writes_features_to_database(): void
    {
        Storage::fake('local');

        $csv = "timestamp,latitude,longitude,category\n2024-01-01T00:00:00Z,51.5,-0.1,Robbery\n";
        Storage::disk('local')->put('datasets/test.csv', $csv);

        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Processing,
            'file_path' => 'datasets/test.csv',
            'mime_type' => 'text/csv',
        ]);

        $this->generator->populateFromMapping($dataset, [
            'timestamp' => 'timestamp',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'category' => 'category',
        ]);

        $this->assertDatabaseHas('features', [
            'dataset_id' => $dataset->id,
            'name' => 'Robbery',
        ]);
    }
}
