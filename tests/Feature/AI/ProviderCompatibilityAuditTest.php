<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Models\ConversationMessage;
use App\Services\AI\AiToolCallingService;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Context\ConversationContextManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use Tests\Support\ActsAsApiUser;
use Tests\Support\AiTestResponses;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

/**
 * Post–Phase 16.5E provider compatibility and shared-context audit (mocked HTTP only).
 */
class ProviderCompatibilityAuditTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_openai_first_turn_plain_text_without_tools(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'plain_1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('No tools needed.')],
                'usage' => AiTestResponses::openAiUsage(3, 2, 5),
            ], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(
            \App\Services\AI\Providers\OpenAiProvider::class,
            new \App\Services\AI\Providers\OpenAiProvider($client),
        );

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Hello')]),
            $this->createSalesApiUser(),
        );

        $this->assertSame('No tools needed.', $outcome->text);
        $this->assertSame(0, $outcome->toolCallsExecuted);
        $this->assertSame('openai', $outcome->provider);
    }

    public function test_openai_provider_omits_tool_role_from_responses_api_input(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'hist_1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('OK.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $client);
        $provider = new \App\Services\AI\Providers\OpenAiProvider($client);

        $provider->complete(new AiRequest(messages: [
            AiMessage::user('Houston?'),
            AiMessage::toolResult('h1', ['success' => true, 'data' => ['slabs_on_hand' => 42]], 'checkStock'),
            AiMessage::assistant('42 in Houston.'),
            AiMessage::user('Dallas?'),
        ]));

        OpenAI::assertSent(Responses::class, function (string $method, array $parameters): bool {
            if ($method !== 'create') {
                return false;
            }

            $input = $parameters['input'] ?? [];

            foreach ($input as $item) {
                if (($item['type'] ?? null) === 'function_call_output') {
                    return false;
                }
            }

            $roles = array_map(static fn (array $row): string => (string) ($row['role'] ?? ''), $input);

            if ($roles !== ['user', 'assistant', 'user']) {
                return false;
            }

            foreach ($input as $item) {
                if (($item['role'] ?? '') === 'assistant') {
                    $type = $item['content'][0]['type'] ?? '';

                    return $type === 'output_text';
                }
            }

            return false;
        });
    }

    public function test_openai_cross_turn_conversation_api_still_executes_follow_up_tool(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        $this->disableAssistantStockGroundingForProviderToolTests();

        $this->seedCalacattaStockScenario();
        $this->actingAsSanctum($this->createSalesApiUser());

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 't1a',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall(
                        'checkStock',
                        json_encode([
                            'material' => 'Quartz',
                            'color' => 'Calacatta',
                            'thickness_cm' => '3.00',
                            'warehouse_name' => 'Houston',
                        ], JSON_THROW_ON_ERROR),
                        'houston',
                        'fc_h',
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 't1b',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('42 in Houston.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 't2a',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall(
                        'checkStock',
                        json_encode([
                            'material' => 'Quartz',
                            'color' => 'Calacatta',
                            'thickness_cm' => '3.00',
                            'warehouse_name' => 'Dallas',
                        ], JSON_THROW_ON_ERROR),
                        'dallas',
                        'fc_d',
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 't2b',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('18 in Dallas.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $first = $this->postJson('/api/assistant', [
            'message' => 'How many 3cm Calacatta slabs do we have in Houston?',
        ]);
        $first->assertOk();

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $first->json('data.conversation_id'),
            'message' => 'And in Dallas?',
        ]);

        $second->assertOk();
        $this->assertStringContainsString('18', (string) $second->json('data.message'));
    }

    public function test_claude_first_turn_tool_call_round_trip(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');
        $this->disableAssistantStockGroundingForProviderToolTests();

        $this->seedCalacattaStockScenario();

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push([
                    'id' => 'msg_tool',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'tool_use',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_stock',
                        'name' => 'checkStock',
                        'input' => [
                            'material' => 'Quartz',
                            'color' => 'Calacatta',
                            'thickness_cm' => '3.00',
                            'warehouse_name' => 'Houston',
                        ],
                    ]],
                    'usage' => ['input_tokens' => 4, 'output_tokens' => 3],
                ], 200)
                ->push([
                    'id' => 'msg_done',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => '42 slabs in Houston.']],
                    'usage' => ['input_tokens' => 6, 'output_tokens' => 4],
                ], 200),
        ]);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Houston Calacatta 3cm stock?')]),
            $this->createSalesApiUser(),
        );

        $this->assertStringContainsString('42', $outcome->text);
        $this->assertSame(1, $outcome->toolCallsExecuted);
        $this->assertSame('claude', $outcome->provider);
    }

    public function test_claude_cross_turn_with_persisted_tool_row_fails_pre_ollama_limitation(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');

        $this->seedCalacattaStockScenario();
        $this->actingAsSanctum($this->createSalesApiUser());

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push([
                    'id' => 'msg_a',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'tool_use',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'checkStock',
                        'input' => [
                            'material' => 'Quartz',
                            'color' => 'Calacatta',
                            'thickness_cm' => '3.00',
                            'warehouse_name' => 'Houston',
                        ],
                    ]],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 4],
                ], 200)
                ->push([
                    'id' => 'msg_b',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => '42 in Houston.']],
                    'usage' => ['input_tokens' => 8, 'output_tokens' => 6],
                ], 200)
                ->push([
                    'id' => 'msg_c',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'tool_use',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_2',
                        'name' => 'checkStock',
                        'input' => [
                            'material' => 'Quartz',
                            'color' => 'Calacatta',
                            'thickness_cm' => '3.00',
                            'warehouse_name' => 'Dallas',
                        ],
                    ]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200)
                ->push([
                    'id' => 'msg_d',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => '18 in Dallas.']],
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
                ], 200),
        ]);

        $first = $this->postJson('/api/assistant', [
            'message' => 'How many 3cm Calacatta slabs do we have in Houston?',
        ]);
        $first->assertOk();
        $cid = $first->json('data.conversation_id');

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $cid,
            'message' => 'And in Dallas?',
        ]);

        $second->assertOk();
        $resolvedHint = (string) ($second->json('data.resolved_question') ?? $second->json('data.message') ?? '');
        $this->assertTrue(
            stripos($resolvedHint, 'Dallas') !== false || stripos((string) $second->json('data.message'), 'Dallas') !== false,
            'Follow-up should resolve to Dallas warehouse context.',
        );
    }

    public function test_shared_context_rebuild_includes_enriched_tool_metadata_after_persist(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $userMsg = $svc->appendUserMessage($user, $conv, 'Overdue from Gulf Stone?');
        $recorder = new \App\Services\AI\Conversations\ConversationTurnRecorder($conv, $userMsg);
        $recorder->add(
            new \App\DTOs\AI\AiToolCall('po_call', 'findOverduePurchaseOrders', ['supplier_name' => 'Gulf']),
            new \App\Services\AI\Tools\ToolExecutionResult(success: true, data: ['count' => 2]),
        );
        $svc->persistToolTraces($user, $recorder);
        $svc->appendAssistantMessage($user, $conv, 'Two overdue orders.');

        Config::set('ai.provider', 'ollama');
        $messages = app(ConversationContextManager::class)->buildAiMessages($conv->fresh(), $user, aiProvider: 'ollama');

        $tool = null;
        foreach ($messages as $m) {
            if ($m->role === AiMessage::ROLE_TOOL) {
                $tool = $m;
                break;
            }
        }

        $this->assertNotNull($tool);
        $this->assertSame('findOverduePurchaseOrders', $tool->toolName);
        $this->assertSame('Gulf', $tool->toolCallArguments['supplier_name'] ?? null);

        $this->assertSame(1, ConversationMessage::query()->where('conversation_id', $conv->id)->where('role', 'tool')->count());
    }

    public function test_ollama_cross_turn_history_regression_from_phase_16_5e(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');

        Http::fake([
            'http://ollama.test/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Continuing.',
                ],
            ], 200),
        ]);

        $history = [
            AiMessage::user('Houston stock?'),
            AiMessage::toolResult('c1', ['success' => true, 'data' => ['slabs_on_hand' => 42]], 'checkStock', []),
            AiMessage::assistant('42 slabs.'),
            AiMessage::user('And in Dallas?'),
        ];

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: $history),
            $this->createSalesApiUser(),
        );

        $this->assertSame('Continuing.', $outcome->text);

        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'] ?? [];

            return collect($messages)->contains(
                fn (array $m): bool => ($m['role'] ?? '') === 'tool' && ($m['tool_call_id'] ?? '') === 'c1'
            );
        });
    }
}
