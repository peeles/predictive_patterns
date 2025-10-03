<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\SanctumTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The SPA relies on the refresh cookie to restoreSession, so local HTTP environments must be
 * able to issue it without the Secure attribute when session.secure is disabled.
 */
class AuthCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_cookie_secure_attribute_respects_configuration(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Viewer,
        ]);

        config(['session.secure' => false]);

        $httpResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $httpResponse->assertOk();
        $httpCookie = $httpResponse->getCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);
        $this->assertNotNull($httpCookie);
        $this->assertFalse($httpCookie->isSecure());

        config(['session.secure' => true]);

        $httpsResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $httpsResponse->assertOk();
        $httpsCookie = $httpsResponse->getCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);
        $this->assertNotNull($httpsCookie);
        $this->assertTrue($httpsCookie->isSecure());
    }
}
