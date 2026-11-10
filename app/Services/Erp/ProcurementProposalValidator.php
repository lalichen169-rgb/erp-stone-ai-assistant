<?php

namespace App\Services\Erp;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;

final class ProcurementProposalValidator
{
    public function __construct(
        private readonly SupplierProductCatalogService $supplierProductCatalogService,
        private readonly WarehouseLookupService $warehouseLookupService,
    ) {}

    /**
     * @return array{warehouse_id: ?int, warehouse_name: ?string, warehouse_city: ?string}
     */
    public function validateWarehouseDestination(?int $warehouseId): array
    {
        $require = (bool) config('erp.procurement.require_line_destination_warehouse', true);

        if ($warehouseId === null || $warehouseId <= 0) {
            if ($require) {
                throw new PurchaseOrderProposalException(
                    'Each purchase order line requires a destination warehouse. List warehouses with searchWarehouses and resolve with resolveWarehouseForPurchase before proposing.'
                );
            }

            return [
                'warehouse_id' => null,
                'warehouse_name' => null,
                'warehouse_city' => null,
            ];
        }

        try {
            $warehouse = $this->warehouseLookupService->requireWarehouseOrFail($warehouseId);
        } catch (\InvalidArgumentException) {
            throw new PurchaseOrderProposalException('Destination warehouse is invalid or does not exist.');
        }

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'warehouse_city' => $warehouse->city,
        ];
    }

    /**
     * @return array{
     *     unit_cost: string,
     *     unit_cost_source: string,
     *     supplier_product_id: ?int,
     *     warehouse_id: ?int,
     *     warehouse_name: ?string,
     *     warehouse_city: ?string
     * }
     */
    public function validateLine(
        Supplier $supplier,
        Product $product,
        int $quantity,
        string|int|float $unitCostInput,
        ?int $warehouseId = null,
    ): array {
        $maxQty = (int) config('erp.procurement.max_line_quantity', 10000);
        if ($quantity <= 0) {
            throw new PurchaseOrderProposalException('Line quantity must be a positive integer.');
        }
        if ($quantity > $maxQty) {
            throw new PurchaseOrderProposalException('Line quantity exceeds the configured maximum.');
        }

        $unitCost = ErpMoney::formatCurrency(ErpMoney::fromDecimal($unitCostInput));
        if (ErpMoney::compare($unitCost, '0') < 0) {
            throw new PurchaseOrderProposalException('Line unit cost cannot be negative.');
        }

        $catalog = $this->supplierProductCatalogService->lookup($supplier, $product);
        $enforceCatalog = (bool) config('erp.procurement.enforce_supplier_product_catalog', false);
        $requireActive = (bool) config('erp.procurement.require_active_supplier_product', true);
        $allowManual = (bool) config('erp.procurement.allow_manual_unit_cost_when_catalog_missing', true);
        $requireConfiguredPrice = (bool) config('erp.procurement.require_catalog_unit_cost_when_configured', true);

        if ($enforceCatalog) {
            if (! $catalog['found']) {
                throw new PurchaseOrderProposalException(
                    'Supplier is not configured to supply this product. Configure an active supplier-product mapping before creating a proposal.'
                );
            }
            if ($requireActive && ! $catalog['active']) {
                throw new PurchaseOrderProposalException('Supplier-product mapping is inactive.');
            }
        }

        $unitCostSource = 'proposal_manual';

        if ($catalog['found'] && $catalog['active']) {
            $catalogCost = $catalog['unit_cost'];
            if ($catalogCost !== null) {
                if ($requireConfiguredPrice && ErpMoney::compare($unitCost, $catalogCost) !== 0) {
                    throw new PurchaseOrderProposalException(
                        'Unit cost does not match the active supplier-product catalogue price.'
                    );
                }
                $unitCost = $catalogCost;
                $unitCostSource = 'supplier_product_catalog';
            } elseif (! $allowManual) {
                throw new PurchaseOrderProposalException(
                    'Supplier-product mapping exists but has no catalogue unit cost; manual pricing is not permitted.'
                );
            } else {
                $unitCostSource = 'proposal_manual_catalog_missing_price';
            }
        }

        $warehouse = $this->validateWarehouseDestination($warehouseId);

        return [
            'unit_cost' => $unitCost,
            'unit_cost_source' => $unitCostSource,
            'supplier_product_id' => $catalog['supplier_product_id'],
            'warehouse_id' => $warehouse['warehouse_id'],
            'warehouse_name' => $warehouse['warehouse_name'],
            'warehouse_city' => $warehouse['warehouse_city'],
        ];
    }

    public function validateFreight(string|int|float $freightCost): string
    {
        $freight = ErpMoney::formatCurrency(ErpMoney::fromDecimal($freightCost));
        if (ErpMoney::compare($freight, '0') < 0) {
            throw new PurchaseOrderProposalException('Freight cost cannot be negative.');
        }
        if (ErpMoney::compare($freight, '0') === 0 && ! config('erp.procurement.allow_zero_freight', true)) {
            throw new PurchaseOrderProposalException('Freight cost is required and cannot be zero.');
        }

        return $freight;
    }

    /**
     * Revalidate supplier-product compatibility at confirmation time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function revalidatePayloadOnConfirm(array $payload): void
    {
        if (! config('erp.procurement.revalidate_catalog_on_confirm', true)) {
            return;
        }

        $supplier = Supplier::query()->find((int) ($payload['supplier_id'] ?? 0));
        if ($supplier === null) {
            throw new PurchaseOrderProposalException('Supplier not found.');
        }

        $lines = $payload['lines'] ?? [];
        if (! is_array($lines)) {
            throw new PurchaseOrderProposalException('Proposal data is corrupted.');
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));
            if ($product === null) {
                throw new PurchaseOrderProposalException('Product not found.');
            }

            $quantity = (int) ($line['quantity'] ?? 0);
            $unitCost = (string) ($line['unit_cost'] ?? '0');

            $warehouseId = array_key_exists('warehouse_id', $line) && $line['warehouse_id'] !== null
                ? (int) $line['warehouse_id']
                : null;

            $this->validateLine($supplier, $product, $quantity, $unitCost, $warehouseId);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function revalidateWarehousesOnConfirm(array $payload): void
    {
        if (! config('erp.procurement.revalidate_warehouse_on_confirm', true)) {
            return;
        }

        $lines = $payload['lines'] ?? [];
        if (! is_array($lines)) {
            throw new PurchaseOrderProposalException('Proposal data is corrupted.');
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $warehouseId = array_key_exists('warehouse_id', $line) && $line['warehouse_id'] !== null
                ? (int) $line['warehouse_id']
                : null;

            $this->validateWarehouseDestination($warehouseId);
        }
    }
}
