<?php

namespace Tests\Feature\AI\Grounding;

use App\Enums\PurchaseOrderProposalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderProposal;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\Grounding\PurchaseOrderCreationIntentDetector;
use App\Services\AI\Grounding\StockCountIntentDetector;
use App\Services\Erp\DraftPurchaseOrderService;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 16.29 — acceptance verification of explicit draft-PO workflow (16.28) using seeder-aligned catalogue data.
 */
class PurchasingProcurementAcceptancePhase16_29Test extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private const DEMO_SKU = 'DEMO-STQ-CAL-20';

    private const SUPPLIER_NAME = 'Demo Supplier — Central Texas Granite';

    private const DALLAS_MESSAGE = 'Create a draft PO for 5 slabs of 2cm Calacatta quartz from Demo Supplier — Central Texas Granite delivered to Dallas warehouse. Freight 500.';

    private const HOUSTON_MESSAGE = 'Create a draft PO for 5 slabs of 2cm Calacatta quartz from Demo Supplier — Central Texas Granite delivered to Houston warehouse. Freight 500.';

    /**
     * @return array{product: Product, supplier: Supplier, warehouses: array<string, \App\Models\Warehouse>}
     */
    private function seedDemoCalacattaTwoCmLikeProductionCatalogue(int $houstonStock, bool $dallasStockRow): array
    {
        $product = Product::query()->updateOrCreate(
            ['sku' => self::DEMO_SKU],
            [
                'name' => 'Calacatta Quartz',
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '2.00',
                'slab_size' => '320x160 cm',
            ],
        );

        $supplier = Supplier::query()->firstOrCreate(
            ['name' => self::SUPPLIER_NAME],
            ['contact' => 'central@demo.test', 'payment_terms' => 'Net 30'],
        );

        $this->createActiveSupplierProductMapping($supplier, $product, '320.00');

        $warehouses = $this->createHoustonDallasAustinWarehouses();

        Stock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouses['houston']->id],
            ['slabs_on_hand' => $houstonStock],
        );

        if ($dallasStockRow) {
            Stock::query()->updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $warehouses['dallas']->id],
                ['slabs_on_hand' => 0],
            );
        } else {
            Stock::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouses['dallas']->id)
                ->delete();
        }

        return compact('product', 'supplier', 'warehouses');
    }

    /**
     * @return array{
     *     user: User,
     *     result: \App\Services\AI\AssistantRequestResult,
     *     proposal: array<string, mixed>,
     *     scenario: array{product: Product, supplier: Supplier, warehouses: array<string, \App\Models\Warehouse>}
     * }
     */
    private function assertValidExplicitDraftProposal(string $message, string $expectedWarehouseKey): array
    {
        Config::set('ai.assistant.enforce_stock_grounding', true);

        $scenario = $this->seedDemoCalacattaTwoCmLikeProductionCatalogue(
            houstonStock: 25,
            dallasStockRow: false,
        );

        $poIntent = app(PurchaseOrderCreationIntentDetector::class);
        $stockIntent = app(StockCountIntentDetector::class);

        $this->assertTrue($poIntent->isPurchaseOrderCreationRequest($message));
        $this->assertFalse($stockIntent->isWarehouseStockCountQuestion($message));

        $user = $this->createPurchasingApiUser();
        $result = app(AssistantRequestService::class)->handle($user, null, $message);

        $assistantText = (string) ($result->data['message'] ?? '');
        $this->assertStringContainsString('proposal', strtolower($assistantText));
        $this->assertStringNotContainsString('slabs in stock', strtolower($assistantText));

        $proposal = $result->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);

        $this->assertSame(self::SUPPLIER_NAME, $proposal['supplier_name'] ?? null);
        $this->assertSame('500.00', (string) ($proposal['freight_cost'] ?? ''));
        $this->assertSame(5, $proposal['lines'][0]['quantity'] ?? null);
        $this->assertSame(self::DEMO_SKU, $proposal['lines'][0]['product_sku'] ?? null);
        $this->assertSame($scenario['product']->id, $proposal['lines'][0]['product_id'] ?? null);
        $this->assertSame(
            $scenario['warehouses'][$expectedWarehouseKey]->id,
            $proposal['lines'][0]['warehouse_id'] ?? null,
        );
        $this->assertSame(
            $scenario['warehouses'][$expectedWarehouseKey]->name,
            $proposal['lines'][0]['warehouse_name'] ?? null,
        );

        $this->assertSame(1, PurchaseOrderProposal::query()->count());
        $this->assertSame(0, PurchaseOrder::query()->count());

        return [
            'user' => $user,
            'result' => $result,
            'proposal' => $proposal,
            'scenario' => $scenario,
        ];
    }

    public function test_scenario_a_dallas_zero_stock_explicit_draft_po(): void
    {
        $this->assertValidExplicitDraftProposal(self::DALLAS_MESSAGE, 'dallas');
    }

    public function test_scenario_b_houston_with_on_hand_stock_explicit_draft_po(): void
    {
        $this->assertValidExplicitDraftProposal(self::HOUSTON_MESSAGE, 'houston');
    }

    public function test_scenario_c_confirm_persists_po_and_repeat_confirm_is_idempotent(): void
    {
        $dallas = $this->assertValidExplicitDraftProposal(self::DALLAS_MESSAGE, 'dallas');
        $user = $dallas['user'];
        $service = app(DraftPurchaseOrderService::class);
        $conversationKey = (string) $dallas['result']->data['conversation_id'];
        $proposalId = (string) $dallas['proposal']['proposal_id'];

        $first = $service->confirmProposal($user, $proposalId, $conversationKey);
        $this->assertSame('confirmed', $first['status'] ?? null);
        $this->assertSame('draft', $first['purchase_order_status'] ?? null);
        $poId = (int) ($first['purchase_order_id'] ?? 0);
        $this->assertGreaterThan(0, $poId);

        $order = PurchaseOrder::query()->findOrFail($poId);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame($dallas['scenario']['supplier']->id, $order->supplier_id);
        $this->assertSame('500.00', (string) $order->freight_cost);

        $line = $order->lines()->firstOrFail();
        $this->assertSame($dallas['scenario']['product']->id, $line->product_id);
        $this->assertSame(5, $line->quantity);
        $this->assertSame($dallas['scenario']['warehouses']['dallas']->id, $line->warehouse_id);

        $proposalRow = PurchaseOrderProposal::query()->findOrFail($proposalId);
        $this->assertSame(PurchaseOrderProposalStatus::Confirmed, $proposalRow->status);
        $this->assertSame($poId, (int) $proposalRow->confirmed_purchase_order_id);

        $second = $service->confirmProposal($user, $proposalId, $conversationKey);
        $this->assertSame($poId, (int) ($second['purchase_order_id'] ?? 0));
        $this->assertSame(1, PurchaseOrder::query()->count());
    }

    public function test_scenario_c_houston_confirm_through_api_route(): void
    {
        $houston = $this->assertValidExplicitDraftProposal(self::HOUSTON_MESSAGE, 'houston');
        $user = $houston['user'];
        $this->actingAsSanctum($user);

        $conversationKey = (string) $houston['result']->data['conversation_id'];
        $proposalId = (string) $houston['proposal']['proposal_id'];

        $first = $this->postJson(
            '/api/purchase-order-proposals/'.$proposalId.'/confirm?conversation_key='.$conversationKey,
        )->assertOk();

        $first->assertJsonPath('data.status', 'confirmed');
        $first->assertJsonPath('data.purchase_order_status', 'draft');

        $poId = (int) $first->json('data.purchase_order_id');
        $line = PurchaseOrder::query()->findOrFail($poId)->lines()->firstOrFail();
        $this->assertSame($houston['scenario']['warehouses']['houston']->id, $line->warehouse_id);

        $this->postJson(
            '/api/purchase-order-proposals/'.$proposalId.'/confirm?conversation_key='.$conversationKey,
        )->assertOk();

        $this->assertSame(1, PurchaseOrder::query()->count());
    }

    public function test_scenario_d_sales_user_cannot_create_proposal_via_assistant(): void
    {
        $this->seedDemoCalacattaTwoCmLikeProductionCatalogue(houstonStock: 25, dallasStockRow: false);
        $user = $this->createSalesApiUser();
        $result = app(AssistantRequestService::class)->handle($user, null, self::HOUSTON_MESSAGE);

        $this->assertNull($result->data['purchase_order_proposal'] ?? null);
        $this->assertStringContainsString('purchasing', strtolower((string) ($result->data['message'] ?? '')));
        $this->assertSame(0, PurchaseOrderProposal::query()->count());
    }

    public function test_scenario_d_incomplete_request_still_uses_warehouse_clarification(): void
    {
        $this->createCarraraMarbleTwoCmProduct();
        $this->createSupplierWithName('Gulf Stone Imports');
        $this->createHoustonDallasAustinWarehouses();
        $user = $this->createPurchasingApiUser();

        $result = app(AssistantRequestService::class)->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );

        $this->assertTrue($result->data['clarification_required'] ?? false);
        $this->assertNull($result->data['purchase_order_proposal'] ?? null);
    }
}
