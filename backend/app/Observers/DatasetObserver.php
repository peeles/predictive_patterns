<?php

namespace App\Observers;

use App\Models\Dataset;
use Illuminate\Support\Facades\Cache;

class DatasetObserver
{
    public function saved(Dataset $dataset): void
    {
        $this->flushAnalysisCache($dataset);
    }

    public function deleted(Dataset $dataset): void
    {
        $this->flushAnalysisCache($dataset);
    }

    private function flushAnalysisCache(Dataset $dataset): void
    {
        Cache::tags([$this->tagFor($dataset)])->flush();
    }

    private function tagFor(Dataset $dataset): string
    {
        return sprintf('dataset:%s', $dataset->getKey());
    }
}
