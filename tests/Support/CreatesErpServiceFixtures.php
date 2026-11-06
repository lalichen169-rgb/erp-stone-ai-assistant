<?php

namespace Tests\Support;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Warehouse;

trait CreatesErpServiceFixtures
{
    protected function createCalacattaThreeCmDemoProduct(): Product
    {
        return Product::query()->create([
            'sku' => 'TEST-STQ-CAL-30',
            'name' => 'Calacatta Quartz Slab',
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'slab_size' => '320x160 cm',
        ]);
    }

    protected function createHoustonDallasAustinWarehouses(): array
    {
        return [
            'houston' => Warehouse::query()->create(['name' => 'Houston', 'city' => 'Houston']),
            'dallas' => Warehouse::query()->create(['name' => 'Dallas', 'city' => 'Dallas']),
            'austin' => Warehouse::query()->create(['name' => 'Austin', 'city' => 'Austin']),
        ];
    }

    protected function seedCalacattaStockScenario(): array
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $warehouses = $this->createHoustonDallasAustinWarehouses();

        Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouses['houston']->id,
            'slabs_on_hand' => 42,
        ]);

        Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouses['dallas']->id,
            'slabs_on_hand' => 18,
        ]);

        return compact('product', 'warehouses');
    }

    protected function createSupplierWithName(string $name): Supplier
    {
        return Supplier::query()->create([
            'name' => $name,
            'contact' => 'demo@example.test',
            'payment_terms' => 'Net 30',
        ]);
    }

    protected function createPurchaseOrderWithLines(
        Supplier $supplier,
        PurchaseOrderStatus $status,
        string $orderDate,
        ?string $eta,
        string $freightCost,
        array $lines
    ): PurchaseOrder {
        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => $status,
            'order_date' => $orderDate,
            'eta' => $eta,
            'freight_cost' => $freightCost,
        ]);

        foreach ($lines as $line) {
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $order->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
            ]);
        }

        return $order->fresh(['lines']);
    }
}
