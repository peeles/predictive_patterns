<?php

namespace App\Events\Datasets;

use App\Models\Dataset;
use Illuminate\Foundation\Events\Dispatchable;

class DatasetIngestionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Dataset $dataset,
        public readonly ?string $message = null,
    ) {
    }
}
