<?php

namespace Tests\Feature\Erp\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Erp\Exceptions\LandedCostCalculationException;
use App\Services\Erp\LandedCostService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\TestCase;

class LandedCostServiceTest extends TestCase
{
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private LandedCostService $service;

    private User $salesUser;

    private User $purchasingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LandedCostService;
        $this->salesUser = User::factory()->create();
        $this->purchasingUser = User::factory()->create();
        $this->purchasingUser->assignRole(UserRole::Purchasing);
    }

    public function test_five_hundred_freight_example_matches_expected_landed_costs(): void
    {
        $supplier = $this->createSupplierWithName('Freight Example Supplier');

        $carrara = Product::query()->create([
            'sku' => 'LC-CAR',
            'name' => 'Carrara',
            'material' => 'Marble',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x200',
        ]);

        $calacatta = Product::query()->create([
            'sku' => 'LC-CAL',
            'name' => 'Calacatta',
            'material' => 'Marble',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'slab_size' => '320x160',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-08-01',
            '2026-09-01',
            '500.00',
            [
                ['product' => $carrara, 'quantity' => 10, 'unit_cost' => '100.00'],
                ['product' => $calacatta, 'quantity' => 20, 'unit_cost' => '200.00'],
            ]
        );

        $beforeFreight = $order->freight_cost;
        $beforeLineCosts = $order->lines->pluck('unit_cost', 'id')->all();

        $result = $this->service->calculateForPurchaseOrder($this->purchasingUser, $order->id);

        $bySku = collect($result['lines'])->keyBy('product_sku');

        $this->assertSame('100.00', $bySku['LC-CAR']['freight_share']);
        $this->assertSame('10.00', $bySku['LC-CAR']['freight_per_unit']);
        $this->assertSame('110.00', $bySku['LC-CAR']['landed_unit_cost']);

        $this->assertSame('400.00', $bySku['LC-CAL']['freight_share']);
        $this->assertSame('20.00', $bySku['LC-CAL']['freight_per_unit']);
        $this->assertSame('220.00', $bySku['LC-CAL']['landed_unit_cost']);

        $this->assertTrue($result['freight_reconciliation']['matches_po_freight']);
        $this->assertSame('5000.00', $result['total_line_value']);

        $order->refresh();
        $this->assertSame((string) $beforeFreight, (string) $order->freight_cost);
        foreach ($order->lines as $line) {
            $this->assertSame((string) $beforeLineCosts[$line->id], (string) $line->unit_cost);
        }
    }

    public function test_single_line_and_zero_freight_scenarios(): void
    {
        $supplier = $this->createSupplierWithName('Single Line Supplier');
        $product = Product::query()->create([
            'sku' => 'LC-ONE',
            'name' => 'Single',
            'material' => 'Quartz',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-08-01',
            null,
            '250.00',
            [['product' => $product, 'quantity' => 5, 'unit_cost' => '80.00']]
        );

        $result = $this->service->calculateForPurchaseOrder($this->purchasingUser, $order->id);

        $this->assertSame('250.00', $result['lines'][0]['freight_share']);
        $this->assertSame('50.00', $result['lines'][0]['freight_per_unit']);
        $this->assertSame('130.00', $result['lines'][0]['landed_unit_cost']);

        $zeroFreightOrder = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-08-02',
            null,
            '0.00',
            [['product' => $product, 'quantity' => 3, 'unit_cost' => '90.00']]
        );

        $zeroResult = $this->service->calculateForPurchaseOrder($this->purchasingUser, $zeroFreightOrder->id);
        $this->assertSame('0.00', $zeroResult['lines'][0]['freight_share']);
        $this->assertSame('90.00', $zeroResult['lines'][0]['landed_unit_cost']);
    }

    public function test_zero_total_line_value_and_invalid_quantity_are_rejected(): void
    {
        $supplier = $this->createSupplierWithName('Edge Case Supplier');
        $product = Product::query()->create([
            'sku' => 'LC-ZERO',
            'name' => 'Zero Cost',
            'material' => 'Quartz',
            'color' => 'Gray',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $zeroValueOrder = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Ordered,
            'order_date' => '2026-08-01',
            'freight_cost' => '100.00',
        ]);

        \App\Models\PurchaseOrderLine::query()->create([
            'purchase_order_id' => $zeroValueOrder->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost' => '0.00',
        ]);

        $this->expectException(LandedCostCalculationException::class);
        $this->service->calculateForPurchaseOrder($this->purchasingUser, $zeroValueOrder->id);
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        $supplier = $this->createSupplierWithName('Bad Qty Supplier');
        $product = Product::query()->create([
            'sku' => 'LC-BADQ',
            'name' => 'Bad Qty',
            'material' => 'Quartz',
            'color' => 'Gray',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Ordered,
            'order_date' => '2026-08-01',
            'freight_cost' => '50.00',
        ]);

        \App\Models\PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 0,
            'unit_cost' => '100.00',
        ]);

        $this->expectException(LandedCostCalculationException::class);
        $this->service->calculateForPurchaseOrder($this->purchasingUser, $order->id);
    }

    public function test_rounding_remainder_reconciles_freight_to_header_total(): void
    {
        $supplier = $this->createSupplierWithName('Rounding Supplier');
        $productA = Product::query()->create([
            'sku' => 'LC-R1',
            'name' => 'Round A',
            'material' => 'Quartz',
            'color' => 'A',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);
        $productB = Product::query()->create([
            'sku' => 'LC-R2',
            'name' => 'Round B',
            'material' => 'Quartz',
            'color' => 'B',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-08-01',
            null,
            '100.00',
            [
                ['product' => $productA, 'quantity' => 3, 'unit_cost' => '33.33'],
                ['product' => $productB, 'quantity' => 3, 'unit_cost' => '33.33'],
            ]
        );

        $result = $this->service->calculateForPurchaseOrder($this->purchasingUser, $order->id);

        $this->assertTrue($result['freight_reconciliation']['matches_po_freight']);
        $this->assertSame(
            '100.00',
            $result['freight_reconciliation']['allocated_freight_total']
        );
    }

    public function test_sales_is_denied_and_purchasing_is_allowed(): void
    {
        $supplier = $this->createSupplierWithName('Auth Supplier');
        $product = Product::query()->create([
            'sku' => 'LC-AUTH',
            'name' => 'Auth',
            'material' => 'Quartz',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-08-01',
            null,
            '10.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '50.00']]
        );

        $this->expectException(AuthorizationException::class);
        $this->service->calculateForPurchaseOrder($this->salesUser, $order->id);
    }
}
