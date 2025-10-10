<?php

namespace App\Providers;

use App\Events\DatasetStatusChanged;
use App\Domain\Models\Events\ModelStatusChanged;
use App\Domain\Models\Events\ModelTrained;
use App\Listeners\BroadcastDatasetStatus;
use App\Listeners\BroadcastModelStatusUpdate;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        DatasetStatusChanged::class => [
            BroadcastDatasetStatus::class,
        ],
        ModelStatusChanged::class => [
            BroadcastModelStatusUpdate::class,
        ],
        ModelTrained::class => [
            BroadcastModelStatusUpdate::class,
        ],
    ];
}
