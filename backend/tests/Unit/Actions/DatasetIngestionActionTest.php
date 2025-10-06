<?php

namespace Tests\Unit\Actions;

use App\Actions\DatasetIngestionAction;
use App\Events\DatasetStatusUpdated;
use App\Http\Requests\DatasetIngestRequest;
use App\Models\User;
use App\Services\DatasetProcessingService;
use App\Services\Datasets\SchemaMapper;
use App\Support\Filesystem\CsvCombiner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
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
            ->andReturnUsing(function ($dataset, array $schema, array $metadata) use ($expectedMetadata) {
                $this->assertSame([], $schema);
                $this->assertSame($expectedMetadata, $metadata);

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
        Event::assertDispatched(DatasetStatusUpdated::class);
    }
}
