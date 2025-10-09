<?php

namespace App\Providers;

use App\Events\Datasets\DatasetIngestionCompleted;
use App\Events\Datasets\DatasetIngestionFailed;
use App\Events\Datasets\DatasetIngestionProgressed;
use App\Events\Datasets\DatasetIngestionStarted;
use App\Domain\Models\Events\ModelStatusChanged;
use App\Domain\Models\Events\ModelTrained;
use App\Listeners\BroadcastDatasetIngestionEvent;
use App\Listeners\BroadcastModelStatusUpdate;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        DatasetIngestionStarted::class => [
            BroadcastDatasetIngestionEvent::class,
        ],
        DatasetIngestionProgressed::class => [
            BroadcastDatasetIngestionEvent::class,
        ],
        DatasetIngestionCompleted::class => [
            BroadcastDatasetIngestionEvent::class,
        ],
        DatasetIngestionFailed::class => [
            BroadcastDatasetIngestionEvent::class,
        ],
        ModelStatusChanged::class => [
            BroadcastModelStatusUpdate::class,
        ],
        ModelTrained::class => [
            BroadcastModelStatusUpdate::class,
        ],
    ];
}
