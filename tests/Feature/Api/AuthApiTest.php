<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    public function test_valid_credentials_issue_token_and_user_payload(): void
    {
        $user = $this->createSalesApiUser('secret-pass-123');

        $response = $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'secret-pass-123',
            'device_name' => 'phpunit-device',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email', 'role']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.role', 'sales');

        $this->assertNotEmpty($response->json('token'));
        $this->assertStringNotContainsString('password', strtolower(json_encode($response->json())));
    }

    public function test_invalid_credentials_return_401(): void
    {
        $user = $this->createSalesApiUser('secret-pass-123');

        $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit-device',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_endpoint_returns_safe_fields(): void
    {
        $user = $this->actingAsSanctum($this->createPurchasingApiUser());

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.role', 'purchasing')
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_current_token_only(): void
    {
        $user = $this->createSalesApiUser();
        $tokenOne = $user->createToken('device-one');
        $tokenTwo = $user->createToken('device-two');

        $this->withToken($tokenOne->plainTextToken)
            ->deleteJson('/api/auth/token')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenOne->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenTwo->accessToken->id]);
    }

    public function test_login_rate_limiting_blocks_after_five_attempts(): void
    {
        RateLimiter::clear('api-login');

        $user = $this->createSalesApiUser('secret-pass-123');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/token', [
                'email' => $user->email,
                'password' => 'wrong',
                'device_name' => 'device',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'wrong',
            'device_name' => 'device',
        ])->assertStatus(429);
    }

    public function test_missing_token_returns_401_on_protected_route(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_token_request_validation_returns_422(): void
    {
        $this->postJson('/api/auth/token', [])->assertUnprocessable();
    }
}
