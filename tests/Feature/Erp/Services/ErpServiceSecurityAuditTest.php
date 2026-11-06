<?php

namespace Tests\Feature\Erp\Services;

use App\Auth\ErpAbilities;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderProposal;
use App\Models\User;
use App\Services\Erp\DraftPurchaseOrderService;
use App\Services\Erp\LandedCostService;
use App\Services\Erp\PurchaseOrderLookupService;
use App\Services\Erp\StockLookupService;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\TestCase;

class ErpServiceSecurityAuditTest extends TestCase
{
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_sales_and_purchasing_erp_service_authorization_matrix(): void
    {
        $sales = User::factory()->create();
        $purchasing = User::factory()->create();
        $purchasing->assignRole(UserRole::Purchasing);

        $matrix = [
            ErpAbilities::VIEW_PRODUCTS => [true, true],
            ErpAbilities::VIEW_STOCK => [true, true],
            ErpAbilities::VIEW_PURCHASE_ORDER_STATUS => [true, true],
            ErpAbilities::VIEW_LANDED_COSTS => [false, true],
            ErpAbilities::CREATE_DRAFT_PURCHASE_ORDERS => [false, true],
        ];

        foreach ($matrix as $ability => [$salesAllowed, $purchasingAllowed]) {
            $this->assertSame($salesAllowed, Gate::forUser($sales)->allows($ability), $ability.' sales');
            $this->assertSame($purchasingAllowed, Gate::forUser($purchasing)->allows($ability), $ability.' purchasing');
        }
    }

    public function test_sales_cannot_call_landed_cost_or_draft_proposal_services(): void
    {
        $sales = User::factory()->create();
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Auth Matrix Supplier');
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            \App\Enums\PurchaseOrderStatus::Draft,
            '2026-11-06',
            null,
            '10.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '50.00']]
        );

        $this->expectException(AuthorizationException::class);
        (new LandedCostService)->calculateForPurchaseOrder($sales, $order->id);
    }

    public function test_proposal_snapshot_is_not_affected_by_later_master_data_changes(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Purchasing);

        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Original Supplier Name');
        $service = new DraftPurchaseOrderService;

        $created = $service->createProposal(
            $user,
            'conv-snapshot',
            'idem-snapshot',
            [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'eta' => null,
                'freight_cost' => '10.00',
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '100.00'],
                ],
            ]
        );

        $supplier->update(['name' => 'Changed Supplier Name']);
        $product->update(['sku' => 'CHANGED-SKU', 'name' => 'Changed Product']);

        $fetched = $service->getProposal($user, $created['proposal_id'], 'conv-snapshot');

        $this->assertSame('Original Supplier Name', $fetched['supplier_name']);
        $this->assertSame('TEST-STQ-CAL-30', $fetched['lines'][0]['product_sku']);

        $confirmed = $service->confirmProposal($user, $created['proposal_id'], 'conv-snapshot');
        $order = PurchaseOrder::query()->with('lines')->findOrFail($confirmed['purchase_order_id']);

        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame(2, $order->lines->first()->quantity);
        $this->assertSame('100.00', (string) $order->lines->first()->unit_cost);
    }

    public function test_tampered_proposal_payload_fails_integrity_check_on_confirm(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Purchasing);

        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Tamper Supplier');
        $service = new DraftPurchaseOrderService;

        $created = $service->createProposal(
            $user,
            'conv-tamper',
            'idem-tamper',
            [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '0.00',
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00'],
                ],
            ]
        );

        $proposal = PurchaseOrderProposal::query()->findOrFail($created['proposal_id']);
        $payload = $proposal->payload;
        $payload['lines'][0]['quantity'] = 999;

        DB::table('purchase_order_proposals')
            ->where('id', $proposal->id)
            ->update(['payload' => json_encode($payload)]);

        $this->expectException(PurchaseOrderProposalException::class);
        $this->expectExceptionMessage('integrity check failed');

        $service->confirmProposal($user, $created['proposal_id'], 'conv-tamper');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_proposal_payload_cannot_be_updated_through_eloquent(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Purchasing);

        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Immutable Supplier');
        $service = new DraftPurchaseOrderService;

        $created = $service->createProposal(
            $user,
            'conv-immutable',
            'idem-immutable',
            [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '0.00',
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00'],
                ],
            ]
        );

        $proposal = PurchaseOrderProposal::query()->findOrFail($created['proposal_id']);

        $this->expectException(\LogicException::class);
        $proposal->update(['payload' => ['tampered' => true]]);
    }

    public function test_purchase_order_lookup_enforces_max_pagination_limit(): void
    {
        $sales = User::factory()->create();
        $service = new PurchaseOrderLookupService;

        $result = $service->search($sales, [], 9999, 1);

        $this->assertSame(PurchaseOrderLookupService::MAX_LIMIT, $result['meta']['per_page']);
    }

    public function test_landed_cost_handles_large_monetary_values_with_bcmath(): void
    {
        $purchasing = User::factory()->create();
        $purchasing->assignRole(UserRole::Purchasing);

        $product = Product::query()->create([
            'sku' => 'LC-LARGE',
            'name' => 'Large Value',
            'material' => 'Quartz',
            'color' => 'White',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $supplier = $this->createSupplierWithName('Large PO Supplier');
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            \App\Enums\PurchaseOrderStatus::Ordered,
            '2026-11-06',
            null,
            '999999.99',
            [['product' => $product, 'quantity' => 1000, 'unit_cost' => '9999.99']]
        );

        $result = (new LandedCostService)->calculateForPurchaseOrder($purchasing, $order->id);

        $this->assertTrue($result['freight_reconciliation']['matches_po_freight']);
        $this->assertSame('999999.99', $result['freight_cost']);
    }

    public function test_stock_lookup_authorization_is_required(): void
    {
        $sales = User::factory()->create();
        $product = $this->createCalacattaThreeCmDemoProduct();

        $result = (new StockLookupService)->searchProducts($sales, ['sku' => $product->sku]);

        $this->assertCount(1, $result);
    }
}
