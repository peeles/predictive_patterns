<?php

namespace Tests\Feature\Listeners;

use App\Models\PredictiveModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorised_user_can_authenticate_for_model_status_channel(): void
    {
        $user = User::factory()->create();
        $model = PredictiveModel::factory()->create();

        $this->actingAs($user, 'sanctum');

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => 'private-models.'.$model->getKey().'.status',
            'socket_id' => '12345.67890',
        ]);

        $response->assertOk();
    }

    public function test_request_is_rejected_for_unknown_model(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum');

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => 'private-models.999999.status',
            'socket_id' => '98765.43210',
        ]);

        $response->assertForbidden();
    }
}
