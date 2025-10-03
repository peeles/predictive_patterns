<?php

namespace App\Providers;

use App\Support\ResolvesRoles;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    use ResolvesRoles;

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(function ($request): bool {
            $user = $request->user();

            if ($user === null) {
                return false;
            }

            $role = $this->resolveRole($user);

            return $role->canManageModels();
        });

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return in_array(optional($user)->email, [
                //
            ]);
        });
    }
}
