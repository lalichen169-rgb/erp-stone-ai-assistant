<?php

namespace Tests\Feature\AI\Grounding;

use App\DTOs\AI\AiToolCall;
use App\Models\ConversationToolCall;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Context\DeterministicFollowUpResolver;
use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\Context\FollowUpThreadType;
use App\Services\AI\Grounding\ErpCrossDomainRequestDetector;
use App\Services\AI\Grounding\ErpQuestionStandaloneHeuristic;
use App\Services\AI\Grounding\OverduePurchaseOrderSearchReply;
use App\Services\AI\Grounding\PurchaseOrderSearchReply;
use App\Services\AI\Tools\ToolExecutor;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use Tests\Support\ActsAsApiUser;
use Tests\Support\AiTestResponses;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class CrossDomainIntentPhase16_31Test extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehouseSeeder::class);
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);
        $this->seed(PurchaseOrderSeeder::class);
    }

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();
        parent::tearDown();
    }

    private function conversationAfterPoListing(): \App\Models\Conversation
    {
        $user = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Show me the purchase orders');
        $svc->appendAssistantMessage($user, $conv, 'Purchase orders (25): PO #527 — draft…');

        return $conv->fresh();
    }

    public function test_reported_reproduction_product_request_not_po_supplier_filter(): void
    {
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $conv = $this->conversationAfterPoListing();
        $productQuestion = 'Show me all available products';

        $resolution = app(FollowUpQuestionResolver::class)->resolve($productQuestion, $conv);

        $this->assertFalse($resolution->needsClarification);
        $this->assertTrue($resolution->wasAlreadyStandalone);
        $this->assertSame('erp_actionable_standalone', $resolution->strategy);
        $this->assertSame($productQuestion, $resolution->resolvedStandaloneQuestion);

        $det = app(DeterministicFollowUpResolver::class)->tryExpand(
            $productQuestion,
            'Show me the purchase orders',
            'Purchase orders (25)',
            FollowUpThreadType::PurchaseOrderSearch,
        );
        $this->assertNull($det);

        $poReply = app(PurchaseOrderSearchReply::class);
        $user = $this->createPurchasingApiUser();

        $this->assertNull($poReply->tryBuildReply($user, $productQuestion, $productQuestion));
        $wrong = $poReply->tryBuildReply(
            $user,
            'List purchase orders for Show me all available products',
            $productQuestion,
        );
        $this->assertNotNull($wrong);
        $this->assertStringContainsString('Show me all available products', $wrong);
    }

    public function test_po_supplier_context_to_product_listing(): void
    {
        $user = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Show me purchase orders from Central Texas Granite');
        $svc->appendAssistantMessage($user, $conv, 'Listed POs for Central Texas Granite.');

        $productQuestion = 'Show me all available products';
        $resolution = app(FollowUpQuestionResolver::class)->resolve($productQuestion, $conv->fresh());

        $this->assertTrue($resolution->wasAlreadyStandalone);
        $this->assertSame($productQuestion, $resolution->resolvedStandaloneQuestion);
        $this->assertNull(
            app(PurchaseOrderSearchReply::class)->tryBuildReply($user, $productQuestion, $productQuestion),
        );
    }

    public function test_product_context_to_overdue_po_search(): void
    {
        Config::set('ai.assistant.enforce_overdue_purchase_order_disclosure', true);

        $user = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Show me all available products');
        $svc->appendAssistantMessage($user, $conv, 'Products: Calacatta Quartz SKU…');

        $overdueQuestion = 'Show me the overdue purchase orders';
        $resolution = app(FollowUpQuestionResolver::class)->resolve($overdueQuestion, $conv->fresh());

        $this->assertTrue($resolution->wasAlreadyStandalone);
        $this->assertNotNull(
            app(OverduePurchaseOrderSearchReply::class)->tryBuildReply($user, $overdueQuestion, $overdueQuestion),
        );
    }

    public function test_genuine_po_overdue_phrase_not_expanded_as_supplier_filter(): void
    {
        $conv = $this->conversationAfterPoListing();
        $followUp = 'Only the overdue ones';

        $det = app(DeterministicFollowUpResolver::class)->tryExpand(
            $followUp,
            'Show me the purchase orders',
            'Purchase orders (25)',
            FollowUpThreadType::PurchaseOrderSearch,
        );

        $this->assertNull($det);
        $this->assertNull(
            app(PurchaseOrderSearchReply::class)->tryBuildReply(
                $this->createPurchasingApiUser(),
                'List purchase orders for Only the overdue ones',
                $followUp,
            ),
        );
    }

    public function test_genuine_supplier_filter_follow_up_from_po_list(): void
    {
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $conv = $this->conversationAfterPoListing();
        $followUp = 'Only from Central Texas Granite';

        $resolution = app(FollowUpQuestionResolver::class)->resolve($followUp, $conv);
        $this->assertFalse($resolution->needsClarification);
        $resolved = (string) ($resolution->resolvedStandaloneQuestion ?? '');
        $this->assertStringContainsString('Central Texas Granite', $resolved);

        $reply = app(PurchaseOrderSearchReply::class)->tryBuildReply(
            $this->createPurchasingApiUser(),
            $resolved,
            $followUp,
        );
        $this->assertNotNull($reply);
        $this->assertStringContainsString('Central Texas Granite', $reply);
        $this->assertStringNotContainsString('Show me all available products', $reply);
    }

    public function test_product_listing_without_conversation_history(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $productQuestion = 'Show me all available products';
        $resolution = app(FollowUpQuestionResolver::class)->resolve($productQuestion, $conv);

        $this->assertTrue($resolution->wasAlreadyStandalone);
        $this->assertTrue(app(ErpQuestionStandaloneHeuristic::class)->isActionableStandalone($productQuestion));
        $this->assertTrue(app(ErpCrossDomainRequestDetector::class)->isProductCatalogListingRequest($productQuestion));
    }

    public function test_search_products_authorization_per_role(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $salesResult = $executor->execute(
            new \App\Services\AI\Tools\ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'searchProducts', ['color' => 'Calacatta']),
            'ollama',
        );
        $this->assertTrue($salesResult->success);
        $this->assertGreaterThanOrEqual(1, $salesResult->data['count'] ?? 0);

        $purchasingResult = $executor->execute(
            new \App\Services\AI\Tools\ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'searchProducts', ['color' => 'Calacatta']),
            'ollama',
        );
        $this->assertTrue($purchasingResult->success);
        $this->assertGreaterThanOrEqual(1, $purchasingResult->data['count'] ?? 0);
    }

    public function test_api_po_then_product_uses_search_products_not_po_search(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'output' => [
                    AiTestResponses::openAiFunctionCall('searchProducts', json_encode(['limit' => 25], JSON_THROW_ON_ERROR)),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'output' => [AiTestResponses::openAiAssistantMessage('Listed catalogue products from the ERP.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(
            \App\Services\AI\Providers\OpenAiProvider::class,
            new \App\Services\AI\Providers\OpenAiProvider($client),
        );

        $user = $this->createPurchasingApiUser();
        $this->actingAsSanctum($user);
        $conv = app(ConversationService::class)->createForUser($user);

        $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Show me the purchase orders',
        ])->assertOk();

        $json = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Show me all available products',
        ])->assertOk()->json();

        $this->assertTrue($json['success'] ?? false);
        $message = (string) ($json['data']['message'] ?? '');
        $this->assertStringNotContainsString('No purchase orders in the ERP matched supplier', $message);
        $this->assertStringContainsString('In-stock slabs', $message);

        $userMsg = $conv->fresh()->messages()->where('role', 'user')->orderByDesc('id')->first();
        $this->assertNotNull($userMsg);
        $resolved = $userMsg->metadata['resolved_standalone_question'] ?? null;
        $this->assertTrue($resolved === null || $resolved === 'Show me all available products');

        $toolNames = ConversationToolCall::query()
            ->where('conversation_id', $conv->id)
            ->pluck('tool_name')
            ->all();
        $this->assertNotContains('searchPurchaseOrders', $toolNames);
        $this->assertSame(0, (int) ($json['data']['tool_calls'] ?? -1));
    }
}
