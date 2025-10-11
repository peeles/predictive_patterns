<?php

namespace Tests\Unit\Actions;

use App\Actions\DatasetIngestionAction;
use App\Events\DatasetStatusChanged;
use App\Exceptions\QueueConnectionException;
use App\Http\Requests\DatasetIngestRequest;
use App\Models\Dataset;
use App\Models\User;
use App\Services\DatasetProcessingService;
use App\Services\Datasets\SchemaMapper;
use App\Support\Filesystem\CsvCombiner;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class DatasetIngestionActionTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    public function test_execute_persists_dataset_and_dispatches_events(): void
    {
        Storage::fake('local');
        Event::fake();

        $processingService = Mockery::mock(DatasetProcessingService::class);

        $expectedMetadata = [
            'source_files' => ['dataset.csv'],
            'source_file_count' => 1,
        ];

        $processingService->shouldReceive('mergeMetadata')
            ->once()
            ->with([], $expectedMetadata)
            ->andReturn($expectedMetadata);
        $processingService->shouldReceive('queueFinalise')
            ->once()
            ->andReturnUsing(function ($dataset, array $schema, array $metadata, bool $forceQueue) use ($expectedMetadata) {
                $this->assertSame([], $schema);
                $this->assertSame($expectedMetadata, $metadata);
                $this->assertTrue($forceQueue);

                return $dataset;
            });

        $action = new DatasetIngestionAction(
            $processingService,
            new SchemaMapper(),
            new CsvCombiner()
        );

        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('dataset.csv', "timestamp,latitude,longitude,category\n2024-01-01T00:00:00Z,51.5,-0.1,Robbery\n");

        /** @var DatasetIngestRequest $request */
        $request = DatasetIngestRequest::create('/datasets', 'POST', [
            'name' => 'Test dataset',
            'source_type' => 'file',
        ], [], ['file' => $file]);
        $request->setContainer(app());
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        $dataset = $action->execute($request);

        $this->assertDatabaseHas('datasets', [
            'id' => $dataset->id,
            'name' => 'Test dataset',
        ]);

        $this->assertSame($expectedMetadata, $dataset->metadata);

        Storage::disk('local')->assertExists($dataset->file_path);
        Event::assertDispatched(DatasetStatusChanged::class, function (DatasetStatusChanged $event) use ($dataset) {
            return $event->datasetId === $dataset->id
                && $event->status->value === $dataset->status->value
                && $event->progress === 0.0;
        });
    }

    public function test_execute_throws_queue_connection_exception_when_queue_connection_refused(): void
    {
        Storage::fake('local');
        Event::fake();
        Log::spy();

        $processingService = Mockery::mock(DatasetProcessingService::class);
        $processingService->shouldReceive('queueFinalise')->never();

        $action = new DatasetIngestionAction(
            $processingService,
            new SchemaMapper(),
            new CsvCombiner()
        );

        $dispatcher = Mockery::mock(Dispatcher::class)->shouldIgnoreMissing();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Redis connection refused'));

        $container = app();
        $container->instance(Dispatcher::class, $dispatcher);

        $this->expectException(QueueConnectionException::class);
        $this->expectExceptionMessage('"redis" queue connection');

        try {
            /** @var DatasetIngestRequest $request */
            $request = DatasetIngestRequest::create('/datasets', 'POST', [
                'name' => 'Remote dataset',
                'source_type' => 'url',
                'source_uri' => 'https://example.com/dataset.csv',
            ]);
            $request->setContainer($container);
            $request->setLaravelSession(app('session')->driver());
            $request->setUserResolver(fn () => User::factory()->create());
            $request->validateResolved();

            $action->execute($request);
        } catch (QueueConnectionException $exception) {
            $dataset = Dataset::firstOrFail();

            $this->assertDatabaseHas('datasets', [
                'id' => $dataset->id,
                'status' => 'pending',
            ]);

            Log::shouldHaveReceived('error')->once()->with(
                'Failed to queue remote dataset ingestion due to queue connection failure.',
                Mockery::on(function (array $context) use ($dataset) {
                    return ($context['dataset_id'] ?? null) === $dataset->id
                        && ($context['connection'] ?? null) === config('queue.default')
                        && ($context['host'] ?? null) === config('database.redis.queue.host');
                })
            );

            throw $exception;
        } finally {
            $container->forgetInstance(Dispatcher::class);
        }
    }

    public function test_execute_raises_non_connection_queue_errors(): void
    {
        Storage::fake('local');
        Event::fake();
        Log::spy();

        $processingService = Mockery::mock(DatasetProcessingService::class);
        $processingService->shouldReceive('queueFinalise')->never();

        $action = new DatasetIngestionAction(
            $processingService,
            new SchemaMapper(),
            new CsvCombiner()
        );

        $dispatcher = Mockery::mock(Dispatcher::class)->shouldIgnoreMissing();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Queue failure.'));

        $container = app();
        $container->instance(Dispatcher::class, $dispatcher);

        try {
            /** @var DatasetIngestRequest $request */
            $request = DatasetIngestRequest::create('/datasets', 'POST', [
                'name' => 'Remote dataset',
                'source_type' => 'url',
                'source_uri' => 'https://example.com/dataset.csv',
            ]);
            $request->setContainer($container);
            $request->setLaravelSession(app('session')->driver());
            $request->setUserResolver(fn () => User::factory()->create());
            $request->validateResolved();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Queue failure.');

            $action->execute($request);
        } finally {
            $container->forgetInstance(Dispatcher::class);
        }

        Log::shouldNotHaveReceived('error');
    }
}
