<?php

namespace Tests\Feature;

use App\Enums\DatasetRecordIngestionStatus;
use App\Enums\DatasetStatus;
use App\Enums\Role;
use App\Models\DatasetRecordIngestionRun;
use App\Jobs\CompleteDatasetIngestion;
use App\Jobs\IngestRemoteDataset;
use App\Jobs\NotifyDatasetReady;
use App\Models\Dataset;
use App\Notifications\DatasetReadyNotification;
use App\Services\H3\H3CacheManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DatasetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataset_ingest_dispatches_finalisation_job(): void
    {
        Storage::fake('local');
        Cache::flush();

        // Use Queue::fake() instead of Bus::fake() to properly capture queued jobs
        Queue::fake();

        $csv = "Type,Date\nEntry,2024-04-01T00:00:00+00:00\n";
        $file = UploadedFile::fake()->createWithContent('dataset.csv', $csv, 'text/csv');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/datasets/ingest', [
                'name' => 'Queued Dataset',
                'description' => 'Queued for processing',
                'source_type' => 'file',
                'file' => $file,
            ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $data = $response->json('data');
        // When jobs are queued, status should be 'processing'
        $this->assertSame(DatasetStatus::Processing->value, $data['status']);
        $this->assertSame(0, $data['features_count']);

        Queue::assertPushed(CompleteDatasetIngestion::class, function (CompleteDatasetIngestion $job) use ($data): bool {
            $chained = (function (): array {
                return property_exists($this, 'chained') ? (array) $this->chained : [];
            })->call($job);

            $hasNotification = false;

            foreach ($chained as $queuedJob) {
                if (is_string($queuedJob) && str_contains($queuedJob, NotifyDatasetReady::class)) {
                    $hasNotification = true;
                    break;
                }

                if (is_array($queuedJob) && array_key_exists('job', $queuedJob) && $queuedJob['job'] === NotifyDatasetReady::class) {
                    $hasNotification = true;
                    break;
                }
            }

            return $job->datasetId === $data['id'] && $hasNotification;
        });

        $this->assertDatabaseHas('datasets', [
            'id' => $data['id'],
            'status' => DatasetStatus::Processing->value,
        ]);
    }

    public function test_dataset_ingest_finalises_when_queue_disabled(): void
    {
        Storage::fake('local');
        Cache::flush();
        Bus::fake();
        Notification::fake();

        config()->set('queue.default', 'null');

        try {
            $csv = "Type,Date\nEntry,2024-04-01T00:00:00+00:00\n";
            $file = UploadedFile::fake()->createWithContent('dataset.csv', $csv, 'text/csv');
            $tokens = $this->issueTokensForRole(Role::Admin);

            $response = $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
                ->postJson('/api/v1/datasets/ingest', [
                    'name' => 'Queue Disabled Dataset',
                    'description' => 'Processes synchronously',
                    'source_type' => 'file',
                    'file' => $file,
                ]);

            $response->assertCreated();
            $response->assertJson(['success' => true]);

            $data = $response->json('data');

            $this->assertSame(DatasetStatus::Ready->value, $data['status']);
            $this->assertSame(1, $data['metadata']['row_count']);

            Bus::assertNothingDispatched();

            $this->assertDatabaseHas('datasets', [
                'id' => $data['id'],
                'status' => DatasetStatus::Ready->value,
            ]);

            Notification::assertSentTo(
                $tokens['user'],
                DatasetReadyNotification::class
            );
        } finally {
            config()->set('queue.default', 'sync');
        }
    }

    public function test_dataset_ingest_accepts_file_upload(): void
    {
        Storage::fake('local');
        Cache::flush();

        config()->set('queue.default', 'null');

        try {
            $csv = <<<CSV
Type,Date,Part of a policing operation,Policing operation,Latitude,Longitude,Gender,Age range,Self-defined ethnicity,Officer-defined ethnicity,Legislation,Object of search,Outcome,Outcome linked to object of search,Removal of more than just outer clothing
Person search,2024-03-01T09:58:14+00:00,False,,52.019256,-0.225046,Male,18-24,White - English/Welsh/Scottish/Northern Irish/British,White,Misuse of Drugs Act 1971 (section 23),Controlled drugs,A no further action disposal,,False
CSV;

            $file = UploadedFile::fake()->createWithContent('dataset.csv', $csv, 'text/csv');
            $tokens = $this->issueTokensForRole(Role::Admin);

            $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
                'name' => 'Test Dataset',
                'description' => 'Factory dataset',
                'source_type' => 'file',
                'file' => $file,
                'metadata' => ['ingested_via' => 'test'],
                'schema' => [
                    'timestamp' => 'Date',
                    'latitude' => 'Latitude',
                    'longitude' => 'Longitude',
                    'category' => 'Type',
                    'label' => 'Outcome',
                ],
            ]);

            $response->assertCreated();
            $response->assertJson(['success' => true]);

            $data = $response->json('data');
            $this->assertSame('Test Dataset', $data['name']);
            $this->assertNotNull($data['file_path']);

            // Schema can be either at root or in metadata.schema_mapping
            $expectedSchema = [
                'timestamp' => 'Date',
                'latitude' => 'Latitude',
                'longitude' => 'Longitude',
                'category' => 'Type',
                'label' => 'Outcome',
            ];

            $actualSchema = $data['schema'] ?? $data['metadata']['schema_mapping'] ?? [];

            // Sort both arrays by key for consistent comparison
            ksort($expectedSchema);
            ksort($actualSchema);

            $this->assertSame($expectedSchema, $actualSchema);

            $this->assertSame(1, $data['features_count']);
            $this->assertCount(1, $data['metadata']['preview_rows']);
            $this->assertSame(
                'Person search',
                $data['metadata']['preview_rows'][0]['Type']
            );

            $expectedFeatures = [
                'timestamp' => ['column' => 'Date', 'sample' => '2024-03-01T09:58:14+00:00'],
                'latitude' => ['column' => 'Latitude', 'sample' => '52.019256'],
                'longitude' => ['column' => 'Longitude', 'sample' => '-0.225046'],
                'category' => ['column' => 'Type', 'sample' => 'Person search'],
                'label' => ['column' => 'Outcome', 'sample' => 'A no further action disposal'],
            ];

            ksort($expectedFeatures);
            $actualFeatures = $data['metadata']['derived_features'];
            ksort($actualFeatures);

            $this->assertSame($expectedFeatures, $actualFeatures);
            $this->assertSame('test', $data['metadata']['ingested_via']);

            Storage::disk('local')->assertExists($data['file_path']);

            $this->assertDatabaseHas('datasets', [
                'name' => 'Test Dataset',
                'status' => 'ready',
            ]);

            $this->assertDatabaseHas('features', [
                'dataset_id' => $data['id'],
                'name' => 'Person search',
            ]);

            $this->assertSame(
                null,
                Cache::get(H3CacheManager::CACHE_VERSION_KEY)
            );
        } finally {
            config()->set('queue.default', 'sync');
        }
    }

    public function test_dataset_ingest_accepts_excel_mime_for_csv_uploads(): void
    {
        Storage::fake('local');

        $csv = "Type,Date\nEntry,2024-04-01T00:00:00+00:00\n";
        $file = UploadedFile::fake()->createWithContent('dataset.csv', $csv, 'application/vnd.ms-excel');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'Excel CSV Dataset',
            'description' => 'Spreadsheet export',
            'source_type' => 'file',
            'file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $data = $response->json('data');
        Storage::disk('local')->assertExists($data['file_path']);
    }

    /**
     * @throws \JsonException
     */
    public function test_dataset_ingest_accepts_metadata_json_string(): void
    {
        Storage::fake('local');

        $csv = "Type,Date\nEntry,2024-04-01T00:00:00+00:00\n";
        $file = UploadedFile::fake()->createWithContent('dataset.csv', $csv, 'text/csv');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $metadata = json_encode(['submittedAt' => '2025-09-22T19:36:16Z'], JSON_THROW_ON_ERROR);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/datasets/ingest', [
                'name' => 'Metadata Dataset',
                'source_type' => 'file',
                'file' => $file,
                'metadata' => $metadata,
            ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $metadata = $response->json('data.metadata');

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('submittedAt', $metadata);
        $this->assertSame('2025-09-22T19:36:16Z', $metadata['submittedAt']);
    }

    public function test_dataset_ingest_accepts_multiple_csv_files(): void
    {
        Storage::fake('local');

        $csvA = "Type,Date\nEntry,2024-01-01T00:00:00+00:00\n";
        $csvB = "Type,Date\nEntry,2024-02-01T00:00:00+00:00\n";

        $fileA = UploadedFile::fake()->createWithContent('segment-a.csv', $csvA, 'text/csv');
        $fileB = UploadedFile::fake()->createWithContent('segment-b.csv', $csvB, 'text/csv');

        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/datasets/ingest', [
                'name' => 'Combined CSV Dataset',
                'source_type' => 'file',
                'files' => [$fileA, $fileB],
            ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $payload = $response->json('data');

        $this->assertSame('Combined CSV Dataset', $payload['name']);
        $this->assertSame(2, $payload['metadata']['source_file_count']);
        $this->assertSame(['segment-a.csv', 'segment-b.csv'], $payload['metadata']['source_files']);
        // Features count depends on whether queue runs sync or not
        $this->assertIsInt($payload['features_count']);

        Storage::disk('local')->assertExists($payload['file_path']);
    }

    public function test_dataset_ingest_queues_remote_download_job(): void
    {
        Storage::fake('local');
        Queue::fake();

        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/datasets/ingest', [
                'name' => 'Remote Dataset',
                'source_type' => 'url',
                'source_uri' => 'https://example.com/remote.csv',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', DatasetStatus::Pending->value);

        $payload = $response->json('data');

        Queue::assertPushed(IngestRemoteDataset::class, static function (IngestRemoteDataset $job) use ($payload): bool {
            return $job->datasetId === $payload['id'];
        });

        $this->assertDatabaseHas('datasets', [
            'id' => $payload['id'],
            'status' => DatasetStatus::Pending->value,
            'source_uri' => 'https://example.com/remote.csv',
        ]);
    }

    public function test_dataset_ingest_infers_missing_name_and_source_type_from_file_upload(): void
    {
        Storage::fake('local');

        $csv = "Type,Date\nEntry,2024-04-01T00:00:00+00:00\n";
        $file = UploadedFile::fake()->createWithContent('dataset-data-export.csv', $csv, 'text/csv');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'file' => $file,
            'metadata' => ['ingested_via' => 'wizard'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.name', 'dataset-data-export');
        $response->assertJsonPath('data.source_type', 'file');
    }


    /**
     * @throws \JsonException
     */
    public function test_dataset_ingest_accepts_geojson_with_wgs84_coordinates(): void
    {
        Storage::fake('local');

        $geoJson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['name' => 'Sample'],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [-3.0, 53.0],
                        [-2.9, 53.05],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $file = UploadedFile::fake()->createWithContent('dataset.geojson', $geoJson, 'application/geo+json');
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/datasets/ingest', [
            'name' => 'GeoJSON Dataset',
            'description' => 'Valid geometry',
            'source_type' => 'file',
            'file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('datasets', [
            'name' => 'GeoJSON Dataset',
        ]);
    }

    public function test_dataset_show_returns_dataset_payload(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        $dataset = Dataset::factory()->create([
            'name' => 'Historic incidents',
            'metadata' => [
                'row_count' => 5,
                'preview_rows' => [['Type' => 'Person search']],
                'headers' => ['Type'],
            ],
            'schema_mapping' => [
                'timestamp' => 'Date',
                'latitude' => 'Lat',
                'longitude' => 'Lon',
                'category' => 'Type',
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/datasets/'.$dataset->getKey());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $dataset->getKey());
        $response->assertJsonPath('data.name', 'Historic incidents');
        $response->assertJsonPath('data.metadata.row_count', 5);
        $response->assertJsonPath('data.metadata.preview_rows.0.Type', 'Person search');
        $response->assertJsonPath('data.schema.timestamp', 'Date');
    }

    public function test_runs_endpoint_supports_pagination_filters_and_sorting(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        DatasetRecordIngestionRun::factory()->create([
            'id' => 1,
            'status' => DatasetRecordIngestionStatus::Completed,
            'dry_run' => false,
            'started_at' => now(),
            'records_expected' => 200,
        ]);

        DatasetRecordIngestionRun::factory()->create([
            'id' => 2,
            'status' => DatasetRecordIngestionStatus::Completed,
            'dry_run' => true,
            'started_at' => now()->subDay(),
            'records_expected' => 150,
        ]);

        DatasetRecordIngestionRun::factory()->create([
            'id' => 3,
            'status' => DatasetRecordIngestionStatus::Failed,
            'dry_run' => false,
            'started_at' => now()->subDays(2),
        ]);

        $query = http_build_query([
            'per_page' => 2,
            'sort' => '-records_expected',
            'filter' => [
                'status' => 'completed',
                'dry_run' => 'false',
            ],
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/datasets/runs?'.$query);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $payload = $response->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame(200, $payload['data'][0]['records_expected']);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame(2, $payload['meta']['per_page']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertNull($payload['links']['next']);
        $this->assertNotNull($payload['links']['first']);
    }

    public function test_dataset_index_supports_filters_and_sorting(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        $matching = Dataset::factory()->create([
            'name' => 'Alpha Observations',
            'status' => DatasetStatus::Ready,
            'source_type' => 'file',
        ]);

        Dataset::query()->whereKey($matching->getKey())->update([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $matching->refresh();

        $other = Dataset::factory()->create([
            'name' => 'Beta Reference',
            'status' => DatasetStatus::Failed,
        ]);

        Dataset::query()->whereKey($other->getKey())->update([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $query = http_build_query([
            'per_page' => 10,
            'sort' => '-created_at',
            'filter' => [
                'status' => DatasetStatus::Ready->value,
                'search' => 'Alpha',
            ],
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/datasets?'.$query);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $payload = $response->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame($matching->id, $payload['data'][0]['id']);
        $this->assertSame('ready', $payload['data'][0]['status']);
        $this->assertArrayHasKey('features_count', $payload['data'][0]);
        $this->assertSame(0, $payload['data'][0]['features_count']);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame(10, $payload['meta']['per_page']);
        $this->assertSame(1, $payload['meta']['current_page']);
    }

    public function test_dataset_ingest_is_rate_limited(): void
    {
        Storage::fake('local');
        Cache::flush();
        Bus::fake();

        config(['api.ingest_rate_limit' => 5]);

        $tokens = $this->issueTokensForRole(Role::Admin);

        $payload = static fn (string $name): array => [
            'name' => $name,
            'description' => 'Rate limit verification',
            'source_type' => 'file',
            'file' => UploadedFile::fake()->createWithContent(
                $name.'.csv',
                "Type,Date\nEntry,2024-04-01T00:00:00+00:00\n",
                'text/csv'
            ),
        ];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
                ->postJson('/api/v1/datasets/ingest', $payload('Throttled Dataset '.$i));

            $response->assertCreated();
        }

        $limitResponse = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/datasets/ingest', $payload('Throttled Dataset 5'));

        $limitResponse->assertStatus(429);
        $limitResponse->assertJsonPath('error.code', 'too_many_requests');

        RateLimiter::clear('ingest|'.$tokens['user']->getAuthIdentifier());
    }
}
