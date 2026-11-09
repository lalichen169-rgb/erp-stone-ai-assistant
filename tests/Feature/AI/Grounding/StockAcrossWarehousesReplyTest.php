<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Grounding\StockAcrossWarehousesReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class StockAcrossWarehousesReplyTest extends TestCase
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

    public function test_deterministic_reply_lists_every_thickness_and_warehouse_for_calacatta(): void
    {
        Config::set('ai.assistant.enforce_stock_across_warehouses_disclosure', true);

        $user = $this->createSalesApiUser();
        $reply = app(StockAcrossWarehousesReply::class);

        $text = $reply->tryBuildReply(
            $user,
            'available products of Calacatta across all warehouses',
            'available products of Calacatta across all warehouses',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('2 cm', $text);
        $this->assertStringContainsString('3 cm', $text);
        $this->assertStringContainsString('Houston', $text);
        $this->assertStringContainsString('Dallas', $text);
        $this->assertStringContainsString('Austin', $text);
        $this->assertStringContainsString('Quartz', $text);
        $this->assertStringContainsString('25 slabs', $text);
        $this->assertStringContainsString('42 slabs', $text);
    }

    public function test_api_returns_deterministic_multi_thickness_stock_without_llm(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_stock_across_warehouses_disclosure', true);

        OpenAI::fake([]);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);

        $conv = app(\App\Services\AI\Conversations\ConversationService::class)->createForUser($user);

        $response = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'available products of Calacatta across all warehouses',
        ])->assertOk()->json();

        $message = (string) ($response['data']['message'] ?? '');
        $this->assertStringContainsString('2 cm', $message);
        $this->assertStringContainsString('3 cm', $message);
        $this->assertStringNotContainsString('Let me know if you need', $message);
    }
}
