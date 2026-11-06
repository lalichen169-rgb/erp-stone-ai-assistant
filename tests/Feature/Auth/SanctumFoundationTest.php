<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SanctumFoundationTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    public function test_sanctum_is_installed_and_configured(): void
    {
        $this->assertTrue(class_exists(Sanctum::class));
        $this->assertTrue(in_array(HasApiTokens::class, class_uses_recursive(User::class), true));
        $this->assertTrue(file_exists(config_path('sanctum.php')));
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
    }

    public function test_user_model_can_create_token_without_persisting_in_test_output(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device');

        $this->assertNotEmpty($token->accessToken->id);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test-device',
        ]);
    }
}
