<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Services\AI\AssistantSystemInstructionBuilder;
use App\Services\AI\Context\ConversationContextManager;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\ToolCalling\ClaudeToolCallingDriver;
use App\Services\AI\ToolCalling\OllamaToolCallingDriver;
use App\Services\AI\Tools\Contracts\OllamaExclusiveTool;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\TestCase;

class ProviderIsolationRegressionTest extends TestCase
{
    use ActsAsApiUser;

    public function test_openai_legacy_system_instruction_matches_pre_ollama_baseline(): void
    {
        Config::set('ai.provider', 'openai');
        $sales = $this->createSalesApiUser();
        $legacy = app(AssistantSystemInstructionBuilder::class)->buildLegacy($sales);

        $this->assertStringContainsString('Stone ERP AI assistant', $legacy);
        $this->assertStringNotContainsString('Tool argument grounding', $legacy);
        $this->assertStringContainsString('Sales role', $legacy);
    }

    public function test_ollama_baseline_system_instruction_includes_tool_grounding(): void
    {
        Config::set('ai.provider', 'ollama');
        $sales = $this->createSalesApiUser();
        $text = app(AssistantSystemInstructionBuilder::class)->build($sales, false);

        $this->assertStringContainsString('Tool argument grounding', $text);
        $this->assertStringContainsString('searchProducts', $text);
    }

    public function test_openai_and_claude_expose_identical_tool_names_per_role(): void
    {
        $registry = app(ToolRegistry::class);

        foreach ([$this->createSalesApiUser(), $this->createPurchasingApiUser()] as $user) {
            $openAi = collect($registry->definitionsFor($user, 'openai'))->pluck('name')->sort()->values()->all();
            $claude = collect($registry->definitionsFor($user, 'claude'))->pluck('name')->sort()->values()->all();

            $this->assertSame($openAi, $claude, 'OpenAI and Claude must offer the same tools for '.$user->role->value);
        }
    }

    public function test_only_search_products_is_marked_ollama_exclusive(): void
    {
        $exclusive = [];
        $registry = app(ToolRegistry::class);

        foreach ($registry->registeredNames() as $name) {
            $tool = $registry->get($name);
            if ($tool instanceof OllamaExclusiveTool) {
                $exclusive[] = $name;
            }
        }

        sort($exclusive);
        $this->assertSame(['searchProducts'], $exclusive);
    }

    public function test_legacy_system_instruction_documents_banking_policy(): void
    {
        $text = app(AssistantSystemInstructionBuilder::class)->buildLegacy($this->createPurchasingApiUser());

        $this->assertStringContainsString('Supplier banking (Purchasing):', $text);
        $this->assertStringContainsString('searchSuppliers', $text);
        $this->assertStringContainsString('checkStock', $text);
        $this->assertStringContainsString('product_not_found', $text);
    }

    public function test_legacy_tool_catalog_excludes_ollama_discovery_tools_for_sales(): void
    {
        $sales = $this->createSalesApiUser();
        $openAiDefs = app(ToolRegistry::class)->definitionsFor($sales, 'openai');
        $names = collect($openAiDefs)->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['checkStock', 'findOverduePurchaseOrders', 'searchPurchaseOrders'],
            $names,
        );

        Config::set('ai.provider', 'ollama');
        $ollamaNames = collect(app(ToolRegistry::class)->definitionsFor($sales, 'ollama'))
            ->pluck('name')->sort()->values()->all();

        $this->assertContains('searchProducts', $ollamaNames);
        $this->assertCount(4, $ollamaNames);
    }

    public function test_openai_check_stock_schema_uses_legacy_contract(): void
    {
        $sales = $this->createSalesApiUser();
        $defs = app(ToolRegistry::class)->definitionsFor($sales, 'openai');
        $check = collect($defs)->firstWhere('name', 'checkStock');
        $this->assertNotNull($check);
        $props = $check->parameters['properties'] ?? [];
        $this->assertArrayHasKey('product_sku', $props);
        $this->assertArrayNotHasKey('description', $props['product_sku'] ?? []);
        $this->assertStringContainsString('Check slab stock', $check->description);
    }

    public function test_claude_driver_rejects_historical_tool_messages_in_initial_request(): void
    {
        $driver = app(ClaudeToolCallingDriver::class);
        $request = new AiRequest(messages: [
            AiMessage::user('Prior question'),
            AiMessage::toolResult('call_1', ['success' => true], 'checkStock'),
            AiMessage::user('Follow up'),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('cannot include tool result messages');

        $driver->buildFirstRequest($request, []);
    }

    public function test_ollama_driver_rebuilds_historical_tool_round_in_payload(): void
    {
        Config::set('ai.provider', 'ollama');
        $driver = app(OllamaToolCallingDriver::class);
        $request = new AiRequest(messages: [
            AiMessage::user('Houston?'),
            AiMessage::toolResult('c1', ['success' => true, 'data' => ['slabs_on_hand' => 42]], 'checkStock', ['warehouse_name' => 'Houston']),
            AiMessage::assistant('42.'),
            AiMessage::user('Dallas?'),
        ], systemInstruction: 'Test');

        $built = $driver->buildFirstRequest($request, []);
        $messages = $built->ollamaMessages ?? [];
        $this->assertGreaterThanOrEqual(5, count($messages));

        $assistantWithTools = collect($messages)->first(
            fn (array $row) => ($row['role'] ?? '') === 'assistant' && isset($row['tool_calls']),
        );
        $this->assertIsArray($assistantWithTools);
        $this->assertSame('checkStock', $assistantWithTools['tool_calls'][0]['function']['name'] ?? null);
    }

    public function test_context_enrichment_only_for_ollama_provider(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(\App\Services\AI\Conversations\ConversationService::class);
        $conv = $svc->createForUser($user);
        $userMsg = $svc->appendUserMessage($user, $conv, 'Stock?');
        $recorder = new \App\Services\AI\Conversations\ConversationTurnRecorder($conv, $userMsg);
        $recorder->add(
            new \App\DTOs\AI\AiToolCall('trace_1', 'checkStock', ['warehouse_name' => 'Houston']),
            new \App\Services\AI\Tools\ToolExecutionResult(success: true, data: ['slabs_on_hand' => 1]),
        );
        $svc->persistToolTraces($user, $recorder);

        $openAiCtx = app(ConversationContextManager::class)->buildAiMessages($conv->fresh(), $user, aiProvider: 'openai');
        $tool = collect($openAiCtx)->first(fn ($m) => $m->role === AiMessage::ROLE_TOOL);
        $this->assertNotNull($tool);
        $this->assertNull($tool->toolName);
        $this->assertNull($tool->toolCallArguments);

        $ollamaCtx = app(ConversationContextManager::class)->buildAiMessages($conv->fresh(), $user, aiProvider: 'ollama');
        $ollamaTool = collect($ollamaCtx)->first(fn ($m) => $m->role === AiMessage::ROLE_TOOL);
        $this->assertSame('checkStock', $ollamaTool?->toolName);
        $this->assertSame('Houston', $ollamaTool?->toolCallArguments['warehouse_name'] ?? null);
    }
}
