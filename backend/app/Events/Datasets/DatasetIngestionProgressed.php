<?php

namespace App\Events\Datasets;

use App\Models\Dataset;
use Illuminate\Foundation\Events\Dispatchable;

class DatasetIngestionProgressed
{
    use Dispatchable;

    public function __construct(
        public readonly Dataset $dataset,
        public readonly ?float $progress,
        public readonly ?string $message = null,
    ) {
    }
}
