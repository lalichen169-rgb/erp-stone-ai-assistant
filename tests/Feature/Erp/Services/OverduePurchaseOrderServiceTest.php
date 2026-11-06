<?php

namespace Tests\Feature\Erp\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\OverduePurchaseOrderService;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\TestCase;

class OverduePurchaseOrderServiceTest extends TestCase
{
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private OverduePurchaseOrderService $service;

    private User $salesUser;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OverduePurchaseOrderService;
        $this->salesUser = User::factory()->create();

        $this->product = Product::query()->create([
            'sku' => 'TEST-OD',
            'name' => 'Overdue Sample',
            'material' => 'Quartz',
            'color' => 'Gray',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);
    }

    public function test_overdue_rule_for_ordered_status_and_eta_only(): void
    {
        $supplier = $this->createSupplierWithName('Overdue Supplier');
        $today = Carbon::parse('2026-11-06');

        $pastOrdered = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $todayEtaOrdered = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-10-01',
            '2026-11-06',
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $futureOrdered = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-10-01',
            '2026-12-01',
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $draftPast = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Draft,
            '2026-08-01',
            '2026-09-01',
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $receivedPast = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-08-01',
            '2026-09-01',
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $cancelledPast = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Cancelled,
            '2026-08-01',
            '2026-09-01',
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $nullEta = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-08-01',
            null,
            '0.00',
            [['product' => $this->product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $this->assertTrue($this->service->isOverdue($pastOrdered, $today));
        $this->assertFalse($this->service->isOverdue($todayEtaOrdered, $today));
        $this->assertFalse($this->service->isOverdue($futureOrdered, $today));
        $this->assertFalse($this->service->isOverdue($draftPast, $today));
        $this->assertFalse($this->service->isOverdue($receivedPast, $today));
        $this->assertFalse($this->service->isOverdue($cancelledPast, $today));
        $this->assertFalse($this->service->isOverdue($nullEta, $today));

        $list = $this->service->listOverdue($this->salesUser, $today);
        $ids = collect($list)->pluck('purchase_order_id')->all();

        $this->assertContains($pastOrdered->id, $ids);
        $this->assertNotContains($todayEtaOrdered->id, $ids);
        $this->assertNotContains($draftPast->id, $ids);
        $this->assertSame(36, $list[0]['days_overdue']);
    }
}
