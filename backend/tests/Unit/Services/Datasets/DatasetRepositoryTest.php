<?php

namespace Tests\Unit\Services\Datasets;

use App\Enums\DatasetStatus;
use App\Events\Datasets\DatasetIngestionFailed;
use App\Models\Dataset;
use App\Services\Datasets\DatasetRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DatasetRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private DatasetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DatasetRepository();
    }

    public function test_merge_metadata_overrides_values_and_skips_nulls(): void
    {
        $metadata = $this->repository->mergeMetadata(['keep' => 'value'], [
            'keep' => 'override',
            'skip_null' => null,
            'skip_empty' => [],
        ]);

        $this->assertSame(['keep' => 'override'], $metadata);
    }

    public function test_mark_as_failed_updates_status_and_dispatches_event(): void
    {
        Event::fake();

        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Processing,
            'metadata' => [],
        ]);

        $this->repository->markAsFailed($dataset, 'boom');

        $dataset->refresh();

        $this->assertSame(DatasetStatus::Failed, $dataset->status);
        $this->assertSame('boom', $dataset->metadata['ingest_error']);

        Event::assertDispatched(DatasetIngestionFailed::class, function (DatasetIngestionFailed $event) use ($dataset) {
            return $event->dataset->is($dataset) && $event->message === 'boom';
        });
    }

    public function test_features_table_exists_returns_boolean(): void
    {
        $this->assertTrue($this->repository->featuresTableExists());
    }
}
