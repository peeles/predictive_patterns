<?php

namespace App\Domain\Models\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ModelStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly string $state,
        public readonly ?float $progress,
        public readonly string $updatedAt,
        public readonly ?string $message = null,
    ) {
    }
}
