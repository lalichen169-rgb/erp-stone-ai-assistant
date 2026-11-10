<?php

namespace Tests\Feature\Erp;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ErpModelRelationshipsTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    public function test_product_and_warehouse_relationships_with_stock(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-STOCK-1',
            'name' => 'Calacatta Sample',
            'material' => 'Marble',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x200',
        ]);

        $warehouse = Warehouse::query()->create([
            'name' => 'Central Yard',
            'city' => 'Austin',
        ]);

        $stock = Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'slabs_on_hand' => 12,
        ]);

        $this->assertTrue($product->stock()->whereKey($stock->id)->exists());
        $this->assertTrue($warehouse->stock()->whereKey($stock->id)->exists());
        $this->assertTrue($stock->product->is($product));
        $this->assertTrue($stock->warehouse->is($warehouse));
    }

    public function test_product_and_purchase_order_line_relationships(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-PO-1',
            'name' => 'Travertine',
            'material' => 'Travertine',
            'color' => 'Beige',
            'thickness_cm' => '3.00',
            'slab_size' => '280x180',
        ]);

        $supplier = Supplier::query()->create(['name' => 'Stone Imports LLC']);

        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
            'order_date' => '2026-11-01',
            'freight_cost' => '150.00',
        ]);

        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost' => '420.50',
        ]);

        $this->assertTrue($product->purchaseOrderLines()->whereKey($line->id)->exists());
        $this->assertTrue($line->product->is($product));
    }

    public function test_supplier_and_purchase_order_relationships(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Global Granite Co']);

        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Ordered,
            'order_date' => '2026-10-15',
            'eta' => '2026-11-20',
            'freight_cost' => '0.00',
        ]);

        $this->assertTrue($supplier->purchaseOrders()->whereKey($order->id)->exists());
        $this->assertTrue($order->supplier->is($supplier));
    }

    public function test_purchase_order_and_line_relationships(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Quarry Direct']);

        $product = Product::query()->create([
            'sku' => 'SKU-PO-2',
            'name' => 'Black Galaxy',
            'material' => 'Granite',
            'color' => 'Black',
            'thickness_cm' => '2.00',
            'slab_size' => '300x190',
        ]);

        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
            'order_date' => '2026-11-05',
            'freight_cost' => '75.25',
        ]);

        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_cost' => '310.00',
        ]);

        $this->assertTrue($order->lines()->whereKey($line->id)->exists());
        $this->assertTrue($line->purchaseOrder->is($order));
    }
}
