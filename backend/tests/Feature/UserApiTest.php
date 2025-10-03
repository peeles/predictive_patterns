<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_users(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'password' => 'secret-password',
                'role' => Role::Analyst->value,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);

        $createdPayload = $createResponse->json('data');
        $this->assertSame('Jane Doe', $createdPayload['name']);
        $this->assertSame('jane@example.com', $createdPayload['email']);
        $this->assertSame(Role::Analyst->value, $createdPayload['role']);

        $userId = $createdPayload['id'];
        $this->assertNotNull($userId);

        $createdUser = User::find($userId);
        $this->assertNotNull($createdUser);
        $this->assertTrue(Hash::check('secret-password', $createdUser->password));

        $updateResponse = $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->patchJson('/api/v1/users/' . $userId, [
                'name' => 'Jane A. Doe',
                'password' => 'new-secret-password',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $this->assertSame('Jane A. Doe', $updateResponse->json('data.name'));

        $updatedUser = $createdUser->fresh();
        $this->assertSame('Jane A. Doe', $updatedUser->name);
        $this->assertTrue(Hash::check('new-secret-password', $updatedUser->password));

        $deleteResponse = $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->deleteJson('/api/v1/users/' . $userId);

        $deleteResponse->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_admin_can_assign_roles(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);
        $user = User::factory()->create([
            'role' => Role::Viewer,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->patchJson('/api/v1/users/' . $user->getKey() . '/role', [
                'role' => Role::Analyst->value,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.role', Role::Analyst->value);

        $this->assertTrue($user->fresh()->role() === Role::Analyst);
    }

    public function test_non_admins_are_forbidden(): void
    {
        $tokens = $this->issueTokensForRole(Role::Viewer);

        $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->getJson('/api/v1/users')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->postJson('/api/v1/users', [
                'name' => 'Blocked User',
                'email' => 'blocked@example.com',
                'password' => 'blocked-password',
                'role' => Role::Viewer->value,
            ])
            ->assertForbidden();

        $user = User::factory()->create([
            'role' => Role::Viewer,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $tokens['accessToken'])
            ->patchJson('/api/v1/users/' . $user->getKey() . '/role', [
                'role' => Role::Analyst->value,
            ])
            ->assertForbidden();
    }
}
