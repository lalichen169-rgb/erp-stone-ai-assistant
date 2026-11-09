<?php

namespace App\Auth;

/**
 * Central ERP authorization ability names for Laravel Gate checks.
 *
 * Future API controllers and AI tools must call Gate::authorize() or
 * Gate::check() with these abilities — never rely on prompt instructions alone.
 */
final class ErpAbilities
{
    public const VIEW_PRODUCTS = 'viewProducts';

    public const VIEW_STOCK = 'viewStock';

    public const VIEW_PURCHASE_ORDER_STATUS = 'viewPurchaseOrderStatus';

    public const VIEW_COSTS = 'viewCosts';

    public const VIEW_FREIGHT_COSTS = 'viewFreightCosts';

    public const VIEW_LANDED_COSTS = 'viewLandedCosts';

    public const VIEW_SUPPLIER_BANK_DETAILS = 'viewSupplierBankDetails';

    public const CREATE_DRAFT_PURCHASE_ORDERS = 'createDraftPurchaseOrders';

    /**
     * @return list<string>
     */
    public static function defined(): array
    {
        return [
            self::VIEW_PRODUCTS,
            self::VIEW_STOCK,
            self::VIEW_PURCHASE_ORDER_STATUS,
            self::VIEW_COSTS,
            self::VIEW_FREIGHT_COSTS,
            self::VIEW_LANDED_COSTS,
            self::VIEW_SUPPLIER_BANK_DETAILS,
            self::CREATE_DRAFT_PURCHASE_ORDERS,
        ];
    }
}
