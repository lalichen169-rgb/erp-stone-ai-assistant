<?php

namespace Tests\Feature\Api;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ErpReadApiTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_sales_can_access_products_and_stock_without_cost_fields(): void
    {
        ['product' => $product, 'warehouses' => $warehouses] = $this->seedCalacattaStockScenario();
        $sales = $this->actingAsSanctum($this->createSalesApiUser());

        $products = $this->getJson('/api/products?material=Quartz&color=Calacatta&thickness_cm=3')
            ->assertOk();

        $products->assertJsonFragment(['sku' => 'TEST-STQ-CAL-30']);

        $stock = $this->getJson('/api/stock?material=Quartz&color=Calacatta&thickness_cm=3&warehouse_name=Houston')
            ->assertOk();

        $stock->assertJsonPath('data.slabs_on_hand', 42);

        $dallas = $this->getJson('/api/stock?sku=TEST-STQ-CAL-30&warehouse_name=Dallas')
            ->assertOk();

        $dallas->assertJsonPath('data.slabs_on_hand', 18);

        $encoded = json_encode($products->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight_cost', $encoded);
        $this->assertStringNotContainsString('bank_details', $encoded);
    }

    public function test_sales_cannot_access_landed_cost_endpoint(): void
    {
        $supplier = $this->createSupplierWithName('API Supplier');
        $product = Product::query()->create([
            'sku' => 'API-LC',
            'name' => 'API LC Product',
            'material' => 'Quartz',
            'color' => 'Gray',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '100.00',
            [['product' => $product, 'quantity' => 2, 'unit_cost' => '50.00']]
        );

        $this->actingAsSanctum($this->createSalesApiUser());

        $this->getJson('/api/purchase-orders/'.$order->id.'/landed-cost')
            ->assertForbidden();
    }

    public function test_purchasing_can_access_landed_cost_with_expected_values(): void
    {
        $supplier = $this->createSupplierWithName('Purchasing LC Supplier');
        $carrara = Product::query()->create([
            'sku' => 'API-CAR',
            'name' => 'Carrara',
            'material' => 'Marble',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x200',
        ]);
        $calacatta = Product::query()->create([
            'sku' => 'API-CAL',
            'name' => 'Calacatta',
            'material' => 'Marble',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'slab_size' => '320x160',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '500.00',
            [
                ['product' => $carrara, 'quantity' => 10, 'unit_cost' => '100.00'],
                ['product' => $calacatta, 'quantity' => 20, 'unit_cost' => '200.00'],
            ]
        );

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $response = $this->getJson('/api/purchase-orders/'.$order->id.'/landed-cost')->assertOk();

        $response->assertJsonPath('data.lines.0.landed_unit_cost', '110.00');
        $response->assertJsonPath('data.lines.1.landed_unit_cost', '220.00');
    }

    public function test_purchase_order_search_hides_costs_from_sales_response(): void
    {
        $supplier = $this->createSupplierWithName('PO Search Supplier');
        $product = Product::query()->create([
            'sku' => 'API-PO',
            'name' => 'PO Search Product',
            'material' => 'Quartz',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            '2026-12-01',
            '500.00',
            [['product' => $product, 'quantity' => 3, 'unit_cost' => '99.99']]
        );

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->getJson('/api/purchase-orders?product_sku=API-PO')->assertOk();

        $encoded = json_encode($response->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight_cost', $encoded);
        $this->assertStringNotContainsString('99.99', $encoded);
    }

    public function test_overdue_endpoint_excludes_non_ordered_statuses(): void
    {
        $supplier = $this->createSupplierWithName('Overdue API Supplier');
        $product = Product::query()->create([
            'sku' => 'API-OD',
            'name' => 'Overdue Product',
            'material' => 'Quartz',
            'color' => 'Gray',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $orderedPast = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Draft,
            '2026-08-01',
            '2026-09-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->getJson('/api/purchase-orders/overdue?as_of=2026-11-06')->assertOk();

        $ids = collect($response->json('data'))->pluck('purchase_order_id')->all();
        $this->assertContains($orderedPast->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_validation_rejects_invalid_filters(): void
    {
        $this->actingAsSanctum($this->createSalesApiUser());

        $this->getJson('/api/purchase-orders?status=not-a-status')->assertUnprocessable();
        $this->getJson('/api/purchase-orders?order_date_from=2026-11-10&order_date_to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/products?limit=9999')->assertUnprocessable();
    }

    public function test_landed_cost_returns_404_for_missing_purchase_order(): void
    {
        $this->actingAsSanctum($this->createPurchasingApiUser());

        $this->getJson('/api/purchase-orders/999999/landed-cost')->assertNotFound();
    }

    public function test_sales_can_retrieve_purchase_order_by_id_without_cost_fields(): void
    {
        $supplier = $this->createSupplierWithName('Direct Id Supplier');
        $product = Product::query()->create([
            'sku' => 'API-PO-ID',
            'name' => 'Direct Id Product',
            'material' => 'Granite',
            'color' => 'Black',
            'thickness_cm' => '3.00',
            'slab_size' => '300x180',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-15',
            '2026-06-30',
            '1100.00',
            [['product' => $product, 'quantity' => 18, 'unit_cost' => '295.00']]
        );

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->getJson('/api/purchase-orders?purchase_order_id='.$order->id)
            ->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.purchase_order_id', $order->id);
        $response->assertJsonPath('data.0.status', 'received');

        $encoded = json_encode($response->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight_cost', $encoded);
        $this->assertStringNotContainsString('295', $encoded);
    }

    public function test_purchasing_can_retrieve_purchase_order_by_id_and_landed_cost(): void
    {
        $supplier = $this->createSupplierWithName('Purchasing Id Supplier');
        $product = Product::query()->create([
            'sku' => 'API-PO-PUR',
            'name' => 'Purchasing Id Product',
            'material' => 'Granite',
            'color' => 'Black',
            'thickness_cm' => '3.00',
            'slab_size' => '300x180',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-15',
            '2026-06-30',
            '1100.00',
            [['product' => $product, 'quantity' => 18, 'unit_cost' => '295.00']]
        );

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $this->getJson('/api/purchase-orders?purchase_order_id='.$order->id)
            ->assertOk()
            ->assertJsonPath('data.0.status', 'received');

        $this->getJson('/api/purchase-orders/'.$order->id.'/landed-cost')
            ->assertOk()
            ->assertJsonPath('data.purchase_order_id', $order->id);
    }

    public function test_purchase_order_id_filter_returns_empty_when_not_found(): void
    {
        $this->actingAsSanctum($this->createSalesApiUser());

        $this->getJson('/api/purchase-orders?purchase_order_id=999999')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }
}
