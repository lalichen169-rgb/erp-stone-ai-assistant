<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ErpGateRegistrar
{
    public static function register(): void
    {
        Gate::define(ErpAbilities::VIEW_PRODUCTS, fn (User $user): bool => self::hasErpRole($user));

        Gate::define(ErpAbilities::VIEW_STOCK, fn (User $user): bool => self::hasErpRole($user));

        Gate::define(ErpAbilities::VIEW_PURCHASE_ORDER_STATUS, fn (User $user): bool => self::hasErpRole($user));

        Gate::define(ErpAbilities::VIEW_COSTS, fn (User $user): bool => $user->isPurchasing());

        Gate::define(ErpAbilities::VIEW_FREIGHT_COSTS, fn (User $user): bool => $user->isPurchasing());

        Gate::define(ErpAbilities::VIEW_LANDED_COSTS, fn (User $user): bool => $user->isPurchasing());

        Gate::define(ErpAbilities::VIEW_SUPPLIER_BANK_DETAILS, fn (User $user): bool => $user->isPurchasing());

        Gate::define(ErpAbilities::CREATE_DRAFT_PURCHASE_ORDERS, fn (User $user): bool => $user->isPurchasing());
    }

    private static function hasErpRole(User $user): bool
    {
        return $user->isSales() || $user->isPurchasing();
    }
}
