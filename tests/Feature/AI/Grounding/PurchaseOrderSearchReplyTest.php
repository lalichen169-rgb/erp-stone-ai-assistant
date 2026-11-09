<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Grounding\PurchaseOrderSearchReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class PurchaseOrderSearchReplyTest extends TestCase
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

    public function test_sales_user_lists_draft_purchase_orders_without_llm(): void
    {
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $reply = app(PurchaseOrderSearchReply::class);
        $user = $this->createSalesApiUser();

        $text = $reply->tryBuildReply(
            $user,
            'Show me the draft purchase orders',
            'Show me the draft purchase orders',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('draft', strtolower($text));
        $this->assertStringContainsString('Purchase orders', $text);
        $this->assertGreaterThan(1, substr_count(strtolower($text), 'draft'));
    }

    public function test_draft_filter_for_central_texas_granite_supplier(): void
    {
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        $reply = app(PurchaseOrderSearchReply::class);
        $user = $this->createSalesApiUser();

        $text = $reply->tryBuildReply(
            $user,
            'List draft purchase orders for Central Texas Granite',
            'Central Texas Granite',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('Central Texas Granite', $text);
        $this->assertStringContainsString('draft', strtolower($text));
    }

    public function test_api_sales_user_gets_draft_list_without_provider(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        OpenAI::fake([]);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(\App\Services\AI\Conversations\ConversationService::class)->createForUser($user);

        $response = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Show me the draft purchase orders',
        ])->assertOk()->json();

        $message = (string) ($response['data']['message'] ?? '');
        $this->assertStringContainsString('Purchase orders', $message);
        $this->assertStringContainsString('draft', strtolower($message));
    }

    public function test_api_sales_supplier_follow_up_after_draft_list_without_tool_loop(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        OpenAI::fake([]);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(\App\Services\AI\Conversations\ConversationService::class)->createForUser($user);

        $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Show me the draft purchase orders',
        ])->assertOk();

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Central Texas Granite',
        ])->assertOk()->json();

        $this->assertFalse($second['data']['clarification_required'] ?? true);
        $message = (string) ($second['data']['message'] ?? '');
        $this->assertStringContainsString('Central Texas Granite', $message);
        $this->assertStringContainsString('draft', strtolower($message));
        $this->assertStringNotContainsString('tool_loop', strtolower($message));
    }
}
