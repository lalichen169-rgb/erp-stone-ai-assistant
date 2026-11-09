<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    public const SALES_EMAIL = 'sales@example.test';

    public const PURCHASING_EMAIL = 'purchasing@example.test';

    public function run(): void
    {
        $password = config('erp.demo_user_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'DEMO_USER_PASSWORD is not set. Add it to your local .env before seeding demo users (never commit the value).'
            );
        }

        $this->seedDemoUser(
            email: self::SALES_EMAIL,
            name: 'Demo Sales User',
            role: UserRole::Sales,
            password: $password
        );

        $this->seedDemoUser(
            email: self::PURCHASING_EMAIL,
            name: 'Demo Purchasing User',
            role: UserRole::Purchasing,
            password: $password
        );
    }

    private function seedDemoUser(string $email, string $name, UserRole $role, string $password): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = $name;
            $user->password = $password;
            $user->role = $role;
            $user->save();

            return;
        }

        if ($user->name !== $name) {
            $user->name = $name;
            $user->save();
        }

        if ($user->role !== $role) {
            $user->assignRole($role);
        }
    }
}
