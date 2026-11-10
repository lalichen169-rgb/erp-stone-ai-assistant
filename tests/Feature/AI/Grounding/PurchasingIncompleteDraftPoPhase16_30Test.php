<?php

namespace Tests\Feature\AI\Grounding;

use App\Models\PendingProcurementClarification;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderProposal;
use App\Models\Supplier;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Grounding\PurchaseOrderSearchReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class PurchasingIncompleteDraftPoPhase16_30Test extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private const INCOMPLETE = 'Create a draft PO for 3 slabs of 2cm Calacatta quartz';

    protected function seedCatalogue(): void
    {
        $this->createHoustonDallasAustinWarehouses();
        Product::query()->updateOrCreate(
            ['sku' => 'DEMO-STQ-CAL-20'],
            [
                'name' => 'Calacatta Quartz',
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '2.00',
                'slab_size' => '320x160 cm',
            ],
        );
    }

    public function test_incomplete_draft_po_starts_supplier_clarification_not_po_listing(): void
    {
        $this->seedCatalogue();
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);
        $user = $this->createPurchasingApiUser();
        $result = app(AssistantRequestService::class)->handle($user, null, self::INCOMPLETE);

        $message = (string) ($result->data['message'] ?? '');
        $this->assertTrue($result->data['clarification_required'] ?? false);
        $this->assertStringContainsString('supplier', strtolower($message));
        $this->assertStringContainsString('3 slabs', $message);
        $this->assertStringContainsString('Calacatta', $message);
        $this->assertStringNotContainsString('Purchase orders (', $message);
        $this->assertNull($result->data['purchase_order_proposal'] ?? null);
        $this->assertNotNull(PendingProcurementClarification::query()->first());
    }

    public function test_after_po_status_question_incomplete_draft_does_not_list_orders(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PurchaseOrderSeeder::class);
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $order = PurchaseOrder::query()->firstOrFail();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);
        $conv = app(ConversationService::class)->createForUser($user);

        $assistant->handle($user, $conv->public_id, 'Has PO #'.$order->id.' been received?');

        $second = $assistant->handle($user, $conv->public_id, self::INCOMPLETE);
        $message = (string) ($second->data['message'] ?? '');
        $this->assertStringNotContainsString('Purchase orders (', $message);
        $this->assertTrue($second->data['clarification_required'] ?? false);
        $this->assertStringContainsString('supplier', strtolower($message));
    }

    public function test_supplier_present_warehouse_missing_does_not_list_pos(): void
    {
        $scenario = $this->seedCalacattaSupplierScenario();
        $user = $this->createPurchasingApiUser();
        $msg = 'Create a draft PO for 3 slabs of 2cm Calacatta quartz from '.$scenario['supplier']->name;
        $result = app(AssistantRequestService::class)->handle($user, null, $msg);

        $this->assertTrue($result->data['clarification_required'] ?? false);
        $this->assertStringContainsString('warehouse', strtolower((string) ($result->data['message'] ?? '')));
        $this->assertStringContainsString('3 slabs', (string) ($result->data['message'] ?? ''));
        $this->assertNull(app(PurchaseOrderSearchReply::class)->tryBuildReply($user, $msg, $msg));
    }

    public function test_warehouse_present_supplier_missing_asks_for_supplier(): void
    {
        $this->seedCatalogue();
        $user = $this->createPurchasingApiUser();
        $msg = 'Create a draft PO for 3 slabs of 2cm Calacatta quartz delivered to Houston warehouse';
        $result = app(AssistantRequestService::class)->handle($user, null, $msg);

        $this->assertTrue($result->data['clarification_required'] ?? false);
        $this->assertStringContainsString('supplier', strtolower((string) ($result->data['message'] ?? '')));
    }

    public function test_follow_up_supplier_then_warehouse_retains_quantity_and_creates_proposal(): void
    {
        $scenario = $this->seedCalacattaSupplierScenario();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle($user, null, self::INCOMPLETE);
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle($user, $conversationId, $scenario['supplier']->name);
        $this->assertTrue($second->data['clarification_required'] ?? false);
        $this->assertStringContainsString('warehouse', strtolower((string) ($second->data['message'] ?? '')));

        $third = $assistant->handle($user, $conversationId, 'Houston.');
        $proposal = $third->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertSame(3, $proposal['lines'][0]['quantity']);
        $this->assertSame($scenario['product']->id, $proposal['lines'][0]['product_id']);
        $this->assertSame($scenario['supplier']->id, $proposal['supplier_id']);
        $this->assertSame($scenario['warehouses']['houston']->id, $proposal['lines'][0]['warehouse_id']);
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrderProposal::count());
    }

    public function test_explicit_po_listing_unchanged(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PurchaseOrderSeeder::class);
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);
        $user = $this->createPurchasingApiUser();

        $text = app(PurchaseOrderSearchReply::class)->tryBuildReply(
            $user,
            'Show my purchase orders',
            'Show my purchase orders',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('Purchase orders', $text);
    }

    public function test_po_status_lookup_unchanged(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PurchaseOrderSeeder::class);
        $order = PurchaseOrder::query()->firstOrFail();
        $user = $this->createPurchasingApiUser();

        $text = app(PurchaseOrderSearchReply::class)->tryBuildReply(
            $user,
            'What is the status of PO #'.$order->id.'?',
            'What is the status of PO #'.$order->id.'?',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('Purchase order #'.$order->id, $text);
    }

    /**
     * @return array{product: Product, supplier: Supplier, warehouses: array<string, \App\Models\Warehouse>}
     */
    private function seedCalacattaSupplierScenario(): array
    {
        $product = Product::query()->updateOrCreate(
            ['sku' => 'TEST-CAL-20-PH30'],
            [
                'name' => 'Calacatta Quartz',
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '2.00',
                'slab_size' => '320x160 cm',
            ],
        );
        $supplier = $this->createSupplierWithName('Gulf Stone Imports');
        $this->createActiveSupplierProductMapping($supplier, $product, '300.00');
        $warehouses = $this->createHoustonDallasAustinWarehouses();

        return compact('product', 'supplier', 'warehouses');
    }
}
