<?php

namespace App\Services\Erp\Concerns;

use App\Auth\ErpAbilities;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

trait AuthorizesErpUser
{
    protected function authorizeProductsAndStock(User $user): void
    {
        Gate::forUser($user)->authorize(ErpAbilities::VIEW_PRODUCTS);
        Gate::forUser($user)->authorize(ErpAbilities::VIEW_STOCK);
    }

    protected function authorizePurchaseOrderStatus(User $user): void
    {
        Gate::forUser($user)->authorize(ErpAbilities::VIEW_PURCHASE_ORDER_STATUS);
    }

    protected function authorizeLandedCosts(User $user): void
    {
        Gate::forUser($user)->authorize(ErpAbilities::VIEW_LANDED_COSTS);
    }

    protected function authorizeCreateDraftPurchaseOrders(User $user): void
    {
        Gate::forUser($user)->authorize(ErpAbilities::CREATE_DRAFT_PURCHASE_ORDERS);
    }

    /**
     * @return array<string, mixed>
     */
    protected function productSummary(\App\Models\Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'material' => $product->material,
            'color' => $product->color,
            'thickness_cm' => (string) $product->thickness_cm,
            'slab_size' => $product->slab_size,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function warehouseSummary(\App\Models\Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'city' => $warehouse->city,
        ];
    }
}
