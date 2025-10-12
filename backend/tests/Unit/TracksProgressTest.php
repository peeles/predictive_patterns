<?php

declare(strict_types=1);

use App\Events\ProgressUpdated;
use App\Jobs\Concerns\TracksProgress;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('stores progress with metrics and broadcasts updates', function (): void {
    Event::fake([ProgressUpdated::class]);

    $model = PredictiveModel::factory()->create([
        'metadata' => [],
    ]);

    $job = new class ($model) {
        use TracksProgress;

        public function __construct(private PredictiveModel $model)
        {
            $this->progressModel = $model;
            $this->progressEntityId = $model->id;
            $this->progressStage = 'training';
        }

        public function run(int $progress, string $message, ?array $metrics = null): void
        {
            $this->updateProgress($progress, $message, $metrics);
        }
    };

    $job->run(25, '', [
        'current_epoch' => 3,
        'total_epochs' => 10,
        'loss' => 0.3456,
        'accuracy' => 0.82,
    ]);

    $cacheKey = sprintf('progress.%s.%s', $model->id, 'training');
    $payload = Cache::get($cacheKey);

    expect($payload)
        ->toBeArray()
        ->and($payload['percent'])->toBe(25.0)
        ->and($payload['message'])->toBe('Starting training')
        ->and($payload['current_epoch'])->toBe(3)
        ->and($payload['total_epochs'])->toBe(10)
        ->and($payload['loss'])->toBe(0.3456)
        ->and($payload['accuracy'])->toBe(0.82);

    Event::assertDispatched(ProgressUpdated::class, function (ProgressUpdated $event) use ($model): bool {
        return $event->entityId === $model->id
            && $event->stage === 'training'
            && abs($event->percent - 25.0) < 0.001
            && $event->metrics['current_epoch'] === 3
            && $event->metrics['total_epochs'] === 10
            && $event->metrics['loss'] === 0.3456
            && $event->metrics['accuracy'] === 0.82;
    });

    $model->refresh();
    expect($model->metadata['training_progress'])
        ->toBeArray()
        ->and($model->metadata['training_progress']['percent'])->toBe(25.0)
        ->and($model->metadata['training_progress']['message'])->toBe('Starting training');
});

it('throttles broadcasts for minor progress changes', function (): void {
    Event::fake([ProgressUpdated::class]);

    $model = PredictiveModel::factory()->create([
        'metadata' => [],
    ]);

    $job = new class ($model) {
        use TracksProgress;

        public function __construct(private PredictiveModel $model)
        {
            $this->progressModel = $model;
            $this->progressEntityId = $model->id;
            $this->progressStage = 'training';
        }

        public function run(int $progress): void
        {
            $this->updateProgress($progress, 'Checkpoint');
        }
    };

    $job->run(10);
    $job->run(12);
    $job->run(16);

    Event::assertDispatchedTimes(ProgressUpdated::class, 2);
});
