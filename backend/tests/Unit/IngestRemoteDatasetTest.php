<?php

namespace Tests\Unit;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusUpdated;
use App\Jobs\IngestRemoteDataset;
use App\Models\Dataset;
use App\Services\DatasetProcessingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use function str_ends_with;

class IngestRemoteDatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_downloads_and_finalises_remote_dataset(): void
    {
        Storage::fake('local');
        Event::fake([DatasetStatusUpdated::class]);

        Http::fake(function ($request) {
            $body = "type,timestamp,latitude,longitude\nTheft,2024-01-01T00:00:00Z,51.5,-0.1\n";
            $sink = $request->options['sink'] ?? null;

            if (is_string($sink)) {
                file_put_contents($sink, $body);
            }

            return Http::response($body, 200, ['Content-Type' => 'text/csv']);
        });

        $dataset = Dataset::factory()->create([
            'source_uri' => 'https://example.com/data.csv',
            'status' => DatasetStatus::Pending,
            'metadata' => [],
            'schema_mapping' => [
                'timestamp' => 'timestamp',
                'latitude' => 'latitude',
                'longitude' => 'longitude',
                'category' => 'type',
            ],
            'file_path' => null,
            'checksum' => null,
            'mime_type' => null,
            'ingested_at' => null,
        ]);

        $job = new IngestRemoteDataset($dataset->id);
        $job->handle(app(DatasetProcessingService::class));

        $dataset->refresh();

        $this->assertSame(DatasetStatus::Ready, $dataset->status);
        $this->assertNotNull($dataset->file_path);
        Storage::disk('local')->assertExists($dataset->file_path);
        $this->assertEmpty(array_filter(
            Storage::disk('local')->allFiles('datasets'),
            static fn (string $path): bool => str_ends_with($path, '.tmp')
        ));
        $this->assertSame('text/csv', $dataset->mime_type);
        $this->assertSame(1, $dataset->metadata['row_count']);
        $this->assertArrayHasKey('schema_mapping', $dataset->metadata);
        $this->assertNotNull($dataset->ingested_at);
        $this->assertDatabaseHas('features', [
            'dataset_id' => $dataset->id,
        ]);

        Event::assertDispatched(DatasetStatusUpdated::class, function ($event) use ($dataset) {
            return $event->datasetId === $dataset->id && $event->status === DatasetStatus::Processing->value;
        });

        Event::assertDispatched(DatasetStatusUpdated::class, function ($event) use ($dataset) {
            return $event->datasetId === $dataset->id && $event->status === DatasetStatus::Ready->value;
        });

        Event::assertDispatchedTimes(DatasetStatusUpdated::class, 2);
    }

    public function test_job_marks_dataset_as_failed_when_download_fails(): void
    {
        Storage::fake('local');
        Event::fake([DatasetStatusUpdated::class]);

        Http::fake(function ($request) {
            $sink = $request->options['sink'] ?? null;

            if (is_string($sink)) {
                @unlink($sink);
            }

            return Http::response('', 500);
        });

        $dataset = Dataset::factory()->create([
            'source_uri' => 'https://example.com/data.csv',
            'status' => DatasetStatus::Pending,
            'metadata' => [],
            'schema_mapping' => [
                'timestamp' => 'timestamp',
                'latitude' => 'latitude',
                'longitude' => 'longitude',
                'category' => 'type',
            ],
            'file_path' => null,
            'checksum' => null,
            'mime_type' => null,
            'ingested_at' => null,
        ]);

        $job = new IngestRemoteDataset($dataset->id);

        try {
            $job->handle(app(DatasetProcessingService::class));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $dataset->refresh();

            $this->assertSame(DatasetStatus::Failed, $dataset->status);
            $this->assertArrayHasKey('ingest_error', $dataset->metadata);
            $this->assertNull($dataset->file_path);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }

        Event::assertDispatched(DatasetStatusUpdated::class, function ($event) use ($dataset) {
            return $event->datasetId === $dataset->id && $event->status === DatasetStatus::Processing->value;
        });

        Event::assertDispatched(DatasetStatusUpdated::class, function ($event) use ($dataset) {
            return $event->datasetId === $dataset->id && $event->status === DatasetStatus::Failed->value;
        });

        Event::assertDispatchedTimes(DatasetStatusUpdated::class, 2);
    }
}
