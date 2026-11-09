<?php

namespace Tests\Feature\AI\Grounding;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Grounding\LandedCostQuestionParser;
use App\Services\AI\Grounding\PurchaseOrderSearchReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 16.12 — PO search must not inherit prior supplier filters when a PO ID is given.
 */
class PurchaseOrderSequentialContextRegressionTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PurchaseOrderSeeder::class);
    }

    public function test_parser_normalizes_common_po_id_forms(): void
    {
        $parser = app(LandedCostQuestionParser::class);

        $brazos = Supplier::query()->where('name', 'like', '%Brazos Marble%')->firstOrFail();
        $lookup = PurchaseOrder::query()
            ->where('supplier_id', '!=', $brazos->id)
            ->firstOrFail();

        $id = $lookup->id;

        $this->assertSame($id, $parser->extractPurchaseOrderId('PO-'.$id));
        $this->assertSame($id, $parser->extractPurchaseOrderId('PO #'.$id));
        $this->assertSame($id, $parser->extractPurchaseOrderId('purchase order '.$id));
    }

    public function test_sequential_supplier_search_then_po_id_status_in_one_conversation(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        OpenAI::fake([]);

        $brazos = Supplier::query()->where('name', 'like', '%Brazos Marble%')->firstOrFail();
        $brazosOrders = PurchaseOrder::query()
            ->where('supplier_id', $brazos->id)
            ->orderByDesc('order_date')
            ->get();

        $this->assertGreaterThanOrEqual(3, $brazosOrders->count());

        $lookupOrder = PurchaseOrder::query()
            ->where('supplier_id', '!=', $brazos->id)
            ->firstOrFail();

        $expectedStatus = $lookupOrder->status->value;
        $lookupId = $lookupOrder->id;

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(ConversationService::class)->createForUser($user);

        $first = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Show me all the purchase orders for Brazos Marble LLC',
        ])->assertOk()->json();

        $firstMessage = (string) ($first['data']['message'] ?? '');
        $this->assertStringContainsString('Purchase orders', $firstMessage);
        $this->assertStringContainsString('Brazos Marble', $firstMessage);
        foreach ($brazosOrders->take(3) as $order) {
            $this->assertStringContainsString('PO #'.$order->id, $firstMessage);
        }
        $this->assertStringNotContainsString('PO #'.$lookupId, $firstMessage);

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'What is the status of purchase order PO-'.$lookupId,
        ])->assertOk()->json();

        $secondMessage = (string) ($second['data']['message'] ?? '');
        $this->assertFalse($second['data']['clarification_required'] ?? true);
        $this->assertStringContainsString('Purchase order #'.$lookupId, $secondMessage);
        $this->assertStringContainsString($expectedStatus, $secondMessage);
        $this->assertStringNotContainsString('Purchase orders (3)', $secondMessage);
        $this->assertStringNotContainsString('PO #'.$brazosOrders->first()->id.' — ordered', $secondMessage);
    }

    public function test_nonexistent_po_returns_clear_not_found(): void
    {
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $missingId = (int) (PurchaseOrder::query()->max('id') ?? 0) + 50_000;

        $reply = app(PurchaseOrderSearchReply::class);
        $user = $this->createSalesApiUser();

        $text = $reply->tryBuildReply(
            $user,
            'What is the status of purchase order PO-'.$missingId,
            'What is the status of purchase order PO-'.$missingId,
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString((string) $missingId, $text);
        $this->assertStringContainsString('No purchase order', $text);
    }

    public function test_genuine_supplier_status_follow_up_preserves_supplier(): void
    {
        $resolver = app(\App\Services\AI\Context\DeterministicFollowUpResolver::class);
        $anchor = 'Show me all the purchase orders for Brazos Marble LLC';

        $result = $resolver->tryExpand(
            'Only show ordered ones',
            $anchor,
            'Purchase orders (3):',
            \App\Services\AI\Context\FollowUpThreadType::PurchaseOrderSearch,
        );

        $this->assertNotNull($result);
        $resolved = (string) $result->resolvedStandaloneQuestion;
        $this->assertStringContainsStringIgnoringCase('ordered', $resolved);
        $this->assertStringContainsString('Brazos Marble', $resolved);
    }

    public function test_po_id_lookup_independent_of_prior_supplier_search(): void
    {
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $brazos = Supplier::query()->where('name', 'like', '%Brazos Marble%')->firstOrFail();
        $lookup = PurchaseOrder::query()->where('supplier_id', '!=', $brazos->id)->firstOrFail();

        $reply = app(PurchaseOrderSearchReply::class);
        $user = $this->createPurchasingApiUser();

        $text = $reply->tryBuildReply(
            $user,
            'Show purchase order '.$lookup->id,
            'What is the status of purchase order PO-'.$lookup->id,
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString($lookup->status->value, $text);
        $this->assertStringNotContainsString('Brazos Marble LLC', $text);
    }
}
