<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Grounding\StockWarehouseCountReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class StockWarehouseCountReplyTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);
    }

    public function test_deterministic_reply_lists_quartz_and_marble_at_austin(): void
    {
        Config::set('ai.assistant.enforce_stock_grounding', true);

        $user = $this->createSalesApiUser();
        $reply = app(StockWarehouseCountReply::class);

        $text = $reply->tryBuildReply(
            $user,
            'How many 3cm Calacatta slabs do we have in Austin?',
            'How many 3cm Calacatta slabs do we have in Austin?',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('DEMO-STQ-CAL-30', $text);
        $this->assertStringContainsString('DEMO-MAR-CAL-30', $text);
        $this->assertStringContainsString('9', $text);
        $this->assertStringContainsString('7', $text);
        $this->assertStringContainsString('Quartz', $text);
        $this->assertStringContainsString('Marble', $text);
    }

    public function test_api_returns_both_materials_without_llm(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_stock_grounding', true);

        OpenAI::fake([]);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(\App\Services\AI\Conversations\ConversationService::class)->createForUser($user);

        $response = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'How many 3cm Calacatta slabs do we have in Austin?',
        ])->assertOk()->json();

        $message = (string) ($response['data']['message'] ?? '');
        $this->assertStringContainsString('DEMO-STQ-CAL-30', $message);
        $this->assertStringContainsString('DEMO-MAR-CAL-30', $message);
    }
}
