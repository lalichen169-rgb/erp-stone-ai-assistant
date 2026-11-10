<?php

namespace Tests\Feature\AI\Grounding;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderProposal;
use App\Models\Stock;
use App\Models\Supplier;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\Grounding\SalesPurchaseOrderCreationRefusal;
use App\Services\AI\Grounding\StockCountIntentDetector;
use App\Services\AI\Grounding\StockWarehouseCountReply;
use App\Services\Erp\DraftPurchaseOrderService;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class PurchasingExplicitDraftPurchaseOrderPhase16_28Test extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private const SUPPLIER_NAME = 'Demo Supplier — Central Texas Granite';

    private const DALLAS_MESSAGE = 'Create a draft PO for 5 slabs of 2cm Calacatta quartz from Demo Supplier — Central Texas Granite delivered to Dallas warehouse. Freight 500.';

    private const HOUSTON_MESSAGE = 'Create a draft PO for 5 slabs of 2cm Calacatta quartz from Demo Supplier — Central Texas Granite delivered to Houston warehouse. Freight 500.';

    /**
     * @return array{product: Product, supplier: Supplier, warehouses: array<string, \App\Models\Warehouse>}
     */
    private function seedCalacattaTwoCmProcurementScenario(int $houstonStock, int $dallasStock): array
    {
        $product = Product::query()->updateOrCreate(
            ['sku' => 'TEST-STQ-CAL-20-PHASE28'],
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
            ['contact' => 'central@example.test', 'payment_terms' => 'Net 30'],
        );

        $this->createActiveSupplierProductMapping($supplier, $product, '320.00');

        $warehouses = $this->createHoustonDallasAustinWarehouses();

        Stock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouses['houston']->id],
            ['slabs_on_hand' => $houstonStock],
        );

        Stock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouses['dallas']->id],
            ['slabs_on_hand' => $dallasStock],
        );

        return compact('product', 'supplier', 'warehouses');
    }

    public function test_draft_po_intent_is_not_classified_as_warehouse_stock_count(): void
    {
        $detector = app(StockCountIntentDetector::class);

        $this->assertFalse($detector->isWarehouseStockCountQuestion(self::DALLAS_MESSAGE));
        $this->assertFalse($detector->isWarehouseStockCountQuestion(self::HOUSTON_MESSAGE));
    }

    public function test_explicit_draft_po_with_zero_dallas_stock_creates_proposal_not_stock_reply(): void
    {
        Config::set('ai.assistant.enforce_stock_grounding', true);
        $scenario = $this->seedCalacattaTwoCmProcurementScenario(houstonStock: 25, dallasStock: 0);
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $result = $assistant->handle($user, null, self::DALLAS_MESSAGE);

        $message = (string) ($result->data['message'] ?? '');
        $this->assertStringNotContainsString('slabs in stock', strtolower($message));
        $this->assertStringContainsString('proposal', strtolower($message));

        $proposal = $result->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertSame(5, $proposal['lines'][0]['quantity']);
        $this->assertSame('500.00', (string) $proposal['freight_cost']);
        $this->assertSame($scenario['warehouses']['dallas']->id, $proposal['lines'][0]['warehouse_id']);
        $this->assertSame($scenario['product']->id, $proposal['lines'][0]['product_id']);
        $this->assertSame(1, PurchaseOrderProposal::count());
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_explicit_draft_po_with_houston_stock_creates_proposal_not_stock_reply(): void
    {
        Config::set('ai.assistant.enforce_stock_grounding', true);
        $scenario = $this->seedCalacattaTwoCmProcurementScenario(houstonStock: 25, dallasStock: 0);
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $result = $assistant->handle($user, null, self::HOUSTON_MESSAGE);

        $message = (string) ($result->data['message'] ?? '');
        $this->assertStringNotContainsString('25 slabs', $message);
        $this->assertStringNotContainsString('available at', strtolower($message));

        $proposal = $result->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertSame(5, $proposal['lines'][0]['quantity']);
        $this->assertSame($scenario['warehouses']['houston']->id, $proposal['lines'][0]['warehouse_id']);
        $this->assertSame(1, PurchaseOrderProposal::count());
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_stock_warehouse_reply_still_handles_inventory_questions(): void
    {
        Config::set('ai.assistant.enforce_stock_grounding', true);
        $this->seedCalacattaTwoCmProcurementScenario(houstonStock: 25, dallasStock: 0);
        $user = $this->createPurchasingApiUser();
        $reply = app(StockWarehouseCountReply::class)->tryBuildReply(
            $user,
            'How many 2cm Calacatta slabs do we have in Houston?',
            'How many 2cm Calacatta slabs do we have in Houston?',
        );

        $this->assertNotNull($reply);
        $this->assertStringContainsString('25', (string) $reply);
    }

    public function test_sales_user_still_refused_for_explicit_draft_po(): void
    {
        $user = $this->createSalesApiUser();
        $refusal = app(SalesPurchaseOrderCreationRefusal::class)->refusalMessageFor(
            $user,
            self::HOUSTON_MESSAGE,
            self::HOUSTON_MESSAGE,
        );

        $this->assertNotNull($refusal);
    }

    public function test_unknown_destination_warehouse_starts_clarification_not_stock_reply(): void
    {
        $this->seedCalacattaTwoCmProcurementScenario(houstonStock: 25, dallasStock: 0);
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $message = 'Create a draft PO for 5 slabs of 2cm Calacatta quartz from Demo Supplier — Central Texas Granite delivered to Chicago warehouse. Freight 500.';
        $result = $assistant->handle($user, null, $message);

        $this->assertTrue($result->data['clarification_required'] ?? false);
        $this->assertStringContainsString('warehouse', strtolower((string) ($result->data['message'] ?? '')));
        $this->assertNull($result->data['purchase_order_proposal'] ?? null);
        $this->assertSame(0, PurchaseOrderProposal::count());
    }

    public function test_supplier_without_catalogue_mapping_fails_when_catalog_enforced(): void
    {
        Config::set('erp.procurement.enforce_supplier_product_catalog', true);
        $product = Product::query()->updateOrCreate(
            ['sku' => 'TEST-STQ-CAL-20-NOCAT'],
            [
                'name' => 'Calacatta Quartz',
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '2.00',
                'slab_size' => '320x160 cm',
            ],
        );
        Supplier::query()->firstOrCreate(
            ['name' => self::SUPPLIER_NAME],
            ['contact' => 'central@example.test', 'payment_terms' => 'Net 30'],
        );
        $this->createHoustonDallasAustinWarehouses();

        $user = $this->createPurchasingApiUser();
        $result = app(AssistantRequestService::class)->handle($user, null, self::HOUSTON_MESSAGE);

        $this->assertNull($result->data['purchase_order_proposal'] ?? null);
        $this->assertStringContainsString('supplier-product', strtolower((string) ($result->data['message'] ?? '')));
        $this->assertSame(0, PurchaseOrderProposal::count());
    }

    public function test_confirmed_po_only_after_explicit_confirm(): void
    {
        $scenario = $this->seedCalacattaTwoCmProcurementScenario(houstonStock: 25, dallasStock: 0);
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);
        $service = app(DraftPurchaseOrderService::class);

        $result = $assistant->handle($user, null, self::DALLAS_MESSAGE);
        $proposal = $result->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);

        $service->confirmProposal($user, $proposal['proposal_id'], (string) $result->data['conversation_id']);

        $this->assertSame(1, PurchaseOrder::count());
        $line = PurchaseOrder::query()->first()?->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(5, $line->quantity);
        $this->assertSame($scenario['warehouses']['dallas']->id, $line->warehouse_id);
    }
}
