<?php

namespace App\Domain\Models\Events;

use App\Models\PredictiveModel;
use Illuminate\Foundation\Events\Dispatchable;

class ModelTrained
{
    use Dispatchable;

    public function __construct(
        public readonly PredictiveModel $model,
    ) {
    }
}
