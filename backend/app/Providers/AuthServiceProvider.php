<?php

namespace App\Providers;

use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Policies\DatasetPolicy;
use App\Policies\PredictionPolicy;
use App\Policies\ModelPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Dataset::class => DatasetPolicy::class,
        PredictiveModel::class => ModelPolicy::class,
        Prediction::class => PredictionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
