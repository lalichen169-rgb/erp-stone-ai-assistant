<?php

namespace Tests\Support;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Config;

trait CreatesErpServiceFixtures
{
    /**
     * Provider round-trip tests must exercise AI tool calls instead of deterministic stock grounding.
     */
    protected function disableAssistantStockGroundingForProviderToolTests(): void
    {
        Config::set('ai.assistant.enforce_stock_grounding', false);
    }
    protected function createCarraraMarbleTwoCmProduct(): Product
    {
        return Product::query()->create([
            'sku' => 'CARR-2CM-'.uniqid('', true),
            'name' => 'Carrara Marble 2cm',
            'material' => 'Marble',
            'color' => 'Carrara',
            'thickness_cm' => '2.00',
            'slab_size' => '120x60',
        ]);
    }

    protected function createCalacattaThreeCmDemoProduct(): Product
    {
        return Product::query()->updateOrCreate(
            ['sku' => 'TEST-STQ-CAL-30'],
            [
                'name' => 'Calacatta Quartz Slab',
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'slab_size' => '320x160 cm',
            ],
        );
    }

    protected function createHoustonDallasAustinWarehouses(): array
    {
        return [
            'houston' => Warehouse::query()->firstOrCreate(
                ['name' => 'Houston', 'city' => 'Houston'],
                ['name' => 'Houston', 'city' => 'Houston'],
            ),
            'dallas' => Warehouse::query()->firstOrCreate(
                ['name' => 'Dallas', 'city' => 'Dallas'],
                ['name' => 'Dallas', 'city' => 'Dallas'],
            ),
            'austin' => Warehouse::query()->firstOrCreate(
                ['name' => 'Austin', 'city' => 'Austin'],
                ['name' => 'Austin', 'city' => 'Austin'],
            ),
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

        Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouses['austin']->id,
            'slabs_on_hand' => 9,
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

    /**
     * @return array{product_id: int, warehouse_id: int, quantity: int, unit_cost: string}
     */
    protected function proposalLine(
        Product $product,
        int $quantity = 4,
        string $unitCost = '210.00',
        ?Warehouse $warehouse = null,
    ): array {
        if ($warehouse === null) {
            $warehouse = Warehouse::query()->create([
                'name' => 'Fixture Warehouse '.uniqid('', true),
                'city' => 'Fixture City',
            ]);
        }

        return [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ];
    }

    protected function createActiveSupplierProductMapping(
        Supplier $supplier,
        Product $product,
        ?string $unitCost = '100.00',
        ?string $supplierSku = null,
    ): SupplierProduct {
        return SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'supplier_sku' => $supplierSku,
            'unit_cost' => $unitCost,
            'is_active' => true,
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
