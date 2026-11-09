<?php

namespace Tests\Feature\Auth;

use App\Auth\ErpAbilities;
use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpGateAuthorizationTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function salesAbilityMatrixProvider(): array
    {
        return [
            ErpAbilities::VIEW_PRODUCTS => [ErpAbilities::VIEW_PRODUCTS, true, true],
            ErpAbilities::VIEW_STOCK => [ErpAbilities::VIEW_STOCK, true, true],
            ErpAbilities::VIEW_PURCHASE_ORDER_STATUS => [ErpAbilities::VIEW_PURCHASE_ORDER_STATUS, true, true],
            ErpAbilities::VIEW_COSTS => [ErpAbilities::VIEW_COSTS, false, true],
            ErpAbilities::VIEW_FREIGHT_COSTS => [ErpAbilities::VIEW_FREIGHT_COSTS, false, true],
            ErpAbilities::VIEW_LANDED_COSTS => [ErpAbilities::VIEW_LANDED_COSTS, false, true],
            ErpAbilities::VIEW_SUPPLIER_BANK_DETAILS => [ErpAbilities::VIEW_SUPPLIER_BANK_DETAILS, false, true],
            ErpAbilities::CREATE_DRAFT_PURCHASE_ORDERS => [ErpAbilities::CREATE_DRAFT_PURCHASE_ORDERS, false, true],
        ];
    }

    #[DataProvider('salesAbilityMatrixProvider')]
    public function test_erp_abilities_by_role(string $ability, bool $salesAllowed, bool $purchasingAllowed): void
    {
        $salesUser = User::factory()->create();
        $purchasingUser = User::factory()->create();
        $purchasingUser->assignRole(UserRole::Purchasing);

        $this->assertSame($salesAllowed, Gate::forUser($salesUser)->check($ability));
        $this->assertSame($purchasingAllowed, Gate::forUser($purchasingUser)->check($ability));
    }

    public function test_unknown_abilities_are_denied(): void
    {
        $user = User::factory()->create();
        $purchasingUser = User::factory()->create();
        $purchasingUser->assignRole(UserRole::Purchasing);

        $this->assertFalse(Gate::forUser($user)->check('deleteEverything'));
        $this->assertFalse(Gate::forUser($purchasingUser)->check('deleteEverything'));
    }
}
