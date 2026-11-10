<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait ActsAsApiUser
{
    protected function createSalesApiUser(string $password = 'password'): User
    {
        return User::factory()->create([
            'password' => $password,
        ]);
    }

    protected function createPurchasingApiUser(string $password = 'password'): User
    {
        $user = User::factory()->create([
            'password' => $password,
        ]);
        $user->assignRole(UserRole::Purchasing);

        return $user;
    }

    protected function actingAsSanctum(User $user): User
    {
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
