<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_user_role_enum_values(): void
    {
        $this->assertSame(['sales', 'purchasing'], UserRole::values());
        $this->assertSame('sales', UserRole::Sales->value);
        $this->assertSame('purchasing', UserRole::Purchasing->value);
    }
}
