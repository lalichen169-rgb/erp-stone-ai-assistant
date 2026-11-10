<?php

namespace App\Services\Erp;

use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use Illuminate\Support\Facades\DB;

/**
 * Explicit replenishment only — never adjusts direct PO quantities.
 */
final class ReplenishmentQuantityService
{
    use AuthorizesErpUser;

    /**
     * shortage = max(0, target_quantity - eligible_current_stock)
     *
     * Uses physical slabs_on_hand summed across all warehouses (no reservations).
     *
     * @return array<string, mixed>
     */
    public function calculateShortage(User $user, int $productId, int $targetQuantity): array
    {
        $this->authorizeProductsAndStock($user);

        if ($targetQuantity <= 0) {
            return [
                'success' => false,
                'error_code' => 'invalid_target_quantity',
                'message' => 'Target quantity must be a positive integer.',
            ];
        }

        $product = Product::query()->find($productId);
        if ($product === null) {
            return [
                'success' => false,
                'error_code' => 'product_not_found',
                'message' => 'Product not found.',
            ];
        }

        $onHand = (int) DB::table('stock')
            ->where('product_id', $productId)
            ->sum('slabs_on_hand');

        $shortage = max(0, $targetQuantity - $onHand);

        return [
            'success' => true,
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'target_quantity' => $targetQuantity,
            'current_stock_on_hand' => $onHand,
            'shortage_quantity' => $shortage,
            'includes_incoming_purchase_orders' => false,
            'stock_basis' => 'physical_on_hand_all_warehouses',
        ];
    }

    /**
     * Warehouse-scoped replenishment: shortage = max(0, target - on_hand at that warehouse only).
     *
     * @return array<string, mixed>
     */
    public function calculateShortageAtWarehouse(
        User $user,
        int $productId,
        int $warehouseId,
        int $targetQuantity
    ): array {
        $this->authorizeProductsAndStock($user);

        if ($targetQuantity <= 0) {
            return [
                'success' => false,
                'error_code' => 'invalid_target_quantity',
                'message' => 'Target quantity must be a positive integer.',
            ];
        }

        $product = Product::query()->find($productId);
        $warehouse = Warehouse::query()->find($warehouseId);

        if ($product === null) {
            return [
                'success' => false,
                'error_code' => 'product_not_found',
                'message' => 'Product not found.',
            ];
        }

        if ($warehouse === null) {
            return [
                'success' => false,
                'error_code' => 'warehouse_not_found',
                'message' => 'Warehouse not found.',
            ];
        }

        $stock = Stock::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $onHand = $stock !== null ? (int) $stock->slabs_on_hand : 0;
        $shortage = max(0, $targetQuantity - $onHand);

        return [
            'success' => true,
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'warehouse_city' => $warehouse->city,
            'target_quantity' => $targetQuantity,
            'current_stock_on_hand' => $onHand,
            'shortage_quantity' => $shortage,
            'includes_incoming_purchase_orders' => false,
            'stock_basis' => 'physical_on_hand_single_warehouse',
        ];
    }
}
