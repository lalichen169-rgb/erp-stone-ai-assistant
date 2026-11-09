<?php

namespace App\Services\Erp;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;

final class SupplierProductCatalogService
{
    /**
     * @return array{
     *     found: bool,
     *     active: bool,
     *     supplier_product_id: ?int,
     *     unit_cost: ?string,
     *     supplier_sku: ?string
     * }
     */
    public function lookup(Supplier $supplier, Product $product): array
    {
        $row = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->where('product_id', $product->id)
            ->first();

        if ($row === null) {
            return [
                'found' => false,
                'active' => false,
                'supplier_product_id' => null,
                'unit_cost' => null,
                'supplier_sku' => null,
            ];
        }

        $unitCost = $row->unit_cost !== null
            ? ErpMoney::formatCurrency(ErpMoney::fromDecimal((string) $row->unit_cost))
            : null;

        return [
            'found' => true,
            'active' => (bool) $row->is_active,
            'supplier_product_id' => $row->id,
            'unit_cost' => $unitCost,
            'supplier_sku' => $row->supplier_sku,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeProductsForSupplier(Supplier $supplier, int $limit = 50): array
    {
        return SupplierProduct::query()
            ->with('product')
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (SupplierProduct $row): array {
                $product = $row->product;

                return [
                    'supplier_product_id' => $row->id,
                    'product_id' => $product?->id,
                    'product_sku' => $product?->sku,
                    'product_name' => $product?->name,
                    'unit_cost' => $row->unit_cost !== null
                        ? ErpMoney::formatCurrency(ErpMoney::fromDecimal((string) $row->unit_cost))
                        : null,
                    'supplier_sku' => $row->supplier_sku,
                ];
            })
            ->all();
    }
}
