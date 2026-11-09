<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRoleMigrationTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    public function test_users_table_has_role_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'role'));
    }

    public function test_new_user_defaults_to_sales_role(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->fresh()->isSales());
        $this->assertSame(UserRole::Sales, $user->fresh()->role);
    }

    public function test_user_can_be_explicitly_assigned_purchasing_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Purchasing);

        $this->assertTrue($user->fresh()->isPurchasing());
    }

    public function test_user_cannot_elevate_role_through_mass_assignment(): void
    {
        $user = User::query()->create([
            'name' => 'Mass Assign Test',
            'email' => 'mass-assign@example.com',
            'password' => Hash::make('password'),
            'role' => UserRole::Purchasing,
        ]);

        $this->assertTrue($user->fresh()->isSales());
    }

    public function test_user_rows_store_sales_role_by_default_in_database(): void
    {
        $user = User::factory()->create();

        $this->assertSame('sales', $user->fresh()->role->value);
    }
}
