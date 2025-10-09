<?php

namespace App\Observers;

use App\Models\Dataset;
use App\Services\DatasetAnalysisService;
use Illuminate\Cache\TaggableStore;
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
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            Cache::tags([$this->tagFor($dataset)])->flush();

            return;
        }

        Cache::forget($this->cacheKey($dataset));
    }

    private function tagFor(Dataset $dataset): string
    {
        return sprintf('dataset:%s', $dataset->getKey());
    }

    private function cacheKey(Dataset $dataset): string
    {
        return DatasetAnalysisService::cacheKeyFor($dataset);
    }
}
