<?php

namespace Tests\Unit\Providers;

use App\Providers\BroadcastFallbackServiceProvider;
use Tests\TestCase;

class BroadcastFallbackServiceProviderTest extends TestCase
{
    public function test_it_marks_missing_pusher_credentials(): void
    {
        config([
            'broadcasting.fallback.pusher' => [
                'enabled' => true,
                'connection' => 'pusher',
            ],
            'broadcasting.connections.pusher.app_id' => '',
            'broadcasting.connections.pusher.key' => null,
            'broadcasting.connections.pusher.secret' => '',
        ]);

        $provider = new BroadcastFallbackServiceProvider($this->app);
        $provider->boot();

        $fallback = config('broadcasting.fallback.pusher');

        $this->assertTrue($fallback['requested']);
        $this->assertFalse($fallback['available']);
        $this->assertSame(
            ['PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET'],
            $fallback['missing']
        );
    }
}
