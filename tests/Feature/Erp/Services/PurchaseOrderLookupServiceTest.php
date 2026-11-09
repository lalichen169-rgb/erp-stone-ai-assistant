<?php

namespace Tests\Feature\Erp\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\PurchaseOrderLookupService;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\TestCase;

class PurchaseOrderLookupServiceTest extends TestCase
{
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private PurchaseOrderLookupService $service;

    private User $salesUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PurchaseOrderLookupService;
        $this->salesUser = User::factory()->create();
    }

    public function test_finds_orders_by_supplier_status_product_and_dates_without_cost_fields(): void
    {
        $supplierA = $this->createSupplierWithName('Alpha Stone');
        $supplierB = $this->createSupplierWithName('Beta Marble');

        $carrara = Product::query()->create([
            'sku' => 'TEST-CAR',
            'name' => 'Carrara Classic',
            'material' => 'Marble',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x200',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplierA,
            PurchaseOrderStatus::Ordered,
            '2026-08-01',
            '2026-09-01',
            '500.00',
            [['product' => $carrara, 'quantity' => 10, 'unit_cost' => '100.00']]
        );

        $this->createPurchaseOrderWithLines(
            $supplierB,
            PurchaseOrderStatus::Draft,
            '2026-07-01',
            null,
            '0.00',
            [['product' => $carrara, 'quantity' => 2, 'unit_cost' => '50.00']]
        );

        $bySupplier = $this->service->search($this->salesUser, ['supplier_name' => 'Alpha']);
        $this->assertCount(1, $bySupplier['data']);
        $this->assertSame($order->id, $bySupplier['data'][0]['purchase_order_id']);

        $byStatus = $this->service->search($this->salesUser, ['status' => PurchaseOrderStatus::Draft]);
        $this->assertCount(1, $byStatus['data']);
        $this->assertSame('draft', $byStatus['data'][0]['status']);

        $bySku = $this->service->search($this->salesUser, ['product_sku' => 'TEST-CAR']);
        $this->assertCount(2, $bySku['data']);

        $byName = $this->service->search($this->salesUser, ['product_name' => 'Carrara']);
        $this->assertGreaterThanOrEqual(1, count($byName['data']));

        $byDate = $this->service->search($this->salesUser, [
            'order_date_from' => '2026-08-01',
            'order_date_to' => '2026-08-31',
        ]);
        $this->assertCount(1, $byDate['data']);

        $encoded = json_encode($bySupplier['data']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight_cost', $encoded);
        $this->assertStringNotContainsString('500', $encoded);
    }

    public function test_finds_order_by_purchase_order_id(): void
    {
        $supplier = $this->createSupplierWithName('Id Lookup Supplier');
        $product = Product::query()->create([
            'sku' => 'TEST-ID-PO',
            'name' => 'Id Lookup Product',
            'material' => 'Quartz',
            'color' => 'White',
            'thickness_cm' => '3.00',
            'slab_size' => '300x150',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-15',
            '2026-06-30',
            '1100.00',
            [['product' => $product, 'quantity' => 5, 'unit_cost' => '99.00']]
        );

        $result = $this->service->search($this->salesUser, ['purchase_order_id' => $order->id]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame($order->id, $result['data'][0]['purchase_order_id']);
        $this->assertSame('received', $result['data'][0]['status']);

        $encoded = json_encode($result['data']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight_cost', $encoded);
    }

    public function test_purchase_order_id_filter_returns_empty_when_missing(): void
    {
        $result = $this->service->search($this->salesUser, ['purchase_order_id' => 9_999_999]);

        $this->assertSame(0, $result['meta']['total']);
        $this->assertSame([], $result['data']);
    }
}
