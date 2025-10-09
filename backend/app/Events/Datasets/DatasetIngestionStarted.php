<?php

namespace App\Events\Datasets;

use App\Models\Dataset;
use Illuminate\Foundation\Events\Dispatchable;

class DatasetIngestionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Dataset $dataset,
        public readonly ?float $progress = 0.0,
        public readonly ?string $message = null,
    ) {
    }
}
