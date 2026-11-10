<?php

namespace Tests\Feature\Erp;

use App\Models\PendingProcurementClarification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\AI\AssistantRequestService;
use App\Services\Erp\DraftPurchaseOrderService;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use App\Services\Erp\PendingProcurementClarificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ProcurementWarehouseClarificationTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private function seedCarraraPoScenario(): array
    {
        $product = $this->createCarraraMarbleTwoCmProduct();
        $supplier = $this->createSupplierWithName('Gulf Stone Imports');
        $warehouses = $this->createHoustonDallasAustinWarehouses();

        return compact('product', 'supplier', 'warehouses');
    }

    public function test_begin_clarification_lists_database_warehouses(): void
    {
        $scenario = $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );

        $this->assertTrue($first->data['clarification_required'] ?? false);
        $this->assertStringContainsString('Houston', (string) ($first->data['message'] ?? ''));
        $this->assertStringContainsString('Dallas', (string) ($first->data['message'] ?? ''));
        $this->assertSame(0, PurchaseOrderProposal::count());
        $this->assertNotNull(PendingProcurementClarification::query()->first());
    }

    public function test_houston_reply_creates_pending_proposal_retaining_details(): void
    {
        $scenario = $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle($user, $conversationId, 'Houston.');

        $this->assertFalse($second->data['clarification_required'] ?? true);
        $proposal = $second->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertSame($scenario['supplier']->id, $proposal['supplier_id']);
        $this->assertSame(10, $proposal['lines'][0]['quantity']);
        $this->assertSame($scenario['product']->id, $proposal['lines'][0]['product_id']);
        $this->assertSame($scenario['warehouses']['houston']->id, $proposal['lines'][0]['warehouse_id']);
        $this->assertSame(1, PurchaseOrderProposal::count());
        $this->assertNull(PendingProcurementClarification::query()->first());
    }

    public function test_quantity_can_change_during_clarification(): void
    {
        $scenario = $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle(
            $user,
            $conversationId,
            'Actually make it 15 slabs for Houston.',
        );

        $proposal = $second->data['purchase_order_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertSame(15, $proposal['lines'][0]['quantity']);
        $this->assertSame($scenario['warehouses']['houston']->id, $proposal['lines'][0]['warehouse_id']);
    }

    public function test_unknown_warehouse_prompts_again(): void
    {
        $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle($user, $conversationId, 'Chicago');

        $this->assertTrue($second->data['clarification_required'] ?? false);
        $this->assertStringContainsString('not found', strtolower((string) ($second->data['message'] ?? '')));
        $this->assertSame(0, PurchaseOrderProposal::count());
        $this->assertNotNull(PendingProcurementClarification::query()->first());
    }

    public function test_ambiguous_warehouse_never_picks_arbitrarily(): void
    {
        $this->seedCarraraPoScenario();
        Warehouse::query()->create(['name' => 'Houston North', 'city' => 'Houston']);
        Warehouse::query()->create(['name' => 'Houston South', 'city' => 'Houston']);

        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle($user, $conversationId, 'Houston');

        $this->assertTrue($second->data['clarification_required'] ?? false);
        $this->assertStringContainsString('Multiple warehouses', (string) ($second->data['message'] ?? ''));
        $this->assertSame(0, PurchaseOrderProposal::count());
    }

    public function test_unrelated_question_does_not_consume_pending_clarification(): void
    {
        $this->seedCalacattaStockScenario();
        $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle(
            $user,
            $conversationId,
            'How many 3cm Calacatta slabs do we have in Houston?',
        );

        $this->assertNull($second->data['purchase_order_proposal'] ?? null);
        $this->assertNotNull(PendingProcurementClarification::query()->first());
    }

    public function test_pending_clarification_scoped_to_owner_conversation(): void
    {
        $scenario = $this->seedCarraraPoScenario();
        $owner = $this->createPurchasingApiUser();
        $other = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $owner,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $otherConv = $assistant->handle($other, null, 'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.');
        $otherConversationId = (string) $otherConv->data['conversation_id'];

        $resumeWrongUser = app(PendingProcurementClarificationService::class)->tryResumeWarehouseDestination(
            $other,
            \App\Models\Conversation::query()->where('public_id', $conversationId)->firstOrFail(),
            'Houston',
            $conversationId,
            'wrong-idem',
        );
        $this->assertSame('no_pending', $resumeWrongUser['status']);

        $resumeWrongConversation = app(PendingProcurementClarificationService::class)->tryResumeWarehouseDestination(
            $owner,
            \App\Models\Conversation::query()->where('public_id', $otherConversationId)->firstOrFail(),
            'Houston',
            $otherConversationId,
            'idem-2',
        );
        $this->assertSame('no_pending', $resumeWrongConversation['status']);
    }

    public function test_pending_clarification_expiry(): void
    {
        $this->seedCarraraPoScenario();
        Config::set('erp.procurement.pending_clarification_ttl_minutes', 1);
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $row = PendingProcurementClarification::query()->firstOrFail();
        $row->expires_at = Carbon::now()->subMinute();
        $row->save();

        $second = $assistant->handle($user, $conversationId, 'Houston.');
        $this->assertNull($second->data['purchase_order_proposal'] ?? null);
        $this->assertNull(PendingProcurementClarification::query()->first());
        $this->assertStringContainsString('expired', strtolower((string) ($second->data['message'] ?? '')));
    }

    public function test_new_po_request_replaces_pending_clarification(): void
    {
        $carrara = $this->seedCarraraPoScenario();
        $calacatta = $this->createCalacattaThreeCmDemoProduct();

        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $pending = PendingProcurementClarification::query()->firstOrFail();
        $this->assertSame($carrara['product']->id, (int) $pending->payload['product_id']);

        $second = $assistant->handle(
            $user,
            $conversationId,
            'Create a draft PO for 20 slabs of Calacatta Quartz 3cm from Gulf Stone Imports.',
        );

        $this->assertTrue($second->data['clarification_required'] ?? false);
        $pendingAfter = PendingProcurementClarification::query()->firstOrFail();
        $this->assertSame($calacatta->id, (int) $pendingAfter->payload['product_id']);
        $this->assertSame(20, (int) $pendingAfter->payload['quantity']);
    }

    public function test_unrelated_stock_question_still_returns_grounded_count(): void
    {
        $scenario = $this->seedCalacattaStockScenario();
        $this->createCarraraMarbleTwoCmProduct();
        $this->createSupplierWithName('Gulf Stone Imports');
        $user = $this->createPurchasingApiUser();
        $assistant = app(AssistantRequestService::class);

        $first = $assistant->handle(
            $user,
            null,
            'Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.',
        );
        $conversationId = (string) $first->data['conversation_id'];

        $second = $assistant->handle(
            $user,
            $conversationId,
            'How many 3cm Calacatta slabs do we have in Dallas?',
        );

        $this->assertStringContainsString('18', (string) ($second->data['message'] ?? ''));
        $this->assertNotNull(PendingProcurementClarification::query()->first());
    }

    public function test_warehouse_deleted_before_confirm_fails_without_po_or_stock_change(): void
    {
        $scenario = $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $warehouse = Warehouse::query()->create(['name' => 'Temp Receiving', 'city' => 'Temp']);
        $stockBefore = Stock::query()->sum('slabs_on_hand');

        $proposal = $service->createProposal($user, 'conv-del', 'idem-del', [
            'supplier_id' => $scenario['supplier']->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($scenario['product'], 5, '10.00', $warehouse),
            ],
        ]);

        $warehouse->delete();

        try {
            $service->confirmProposal($user, $proposal['proposal_id'], 'conv-del');
            $this->fail('Expected confirmation to fail after warehouse deletion.');
        } catch (PurchaseOrderProposalException $exception) {
            $this->assertStringContainsString('warehouse', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame($stockBefore, Stock::query()->sum('slabs_on_hand'));
    }

    public function test_warehouse_with_po_line_reference_cannot_be_deleted(): void
    {
        $scenario = $this->seedCarraraPoScenario();
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);
        $warehouses = $scenario['warehouses'];

        $proposal = $service->createProposal($user, 'conv-fk', 'idem-fk', [
            'supplier_id' => $scenario['supplier']->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($scenario['product'], 3, '10.00', $warehouses['houston']),
            ],
        ]);

        $service->confirmProposal($user, $proposal['proposal_id'], 'conv-fk');

        $line = PurchaseOrderLine::query()->firstOrFail();
        $this->assertSame($warehouses['houston']->id, $line->warehouse_id);

        $this->expectException(QueryException::class);
        Warehouse::query()->whereKey($warehouses['houston']->id)->delete();
    }
}
