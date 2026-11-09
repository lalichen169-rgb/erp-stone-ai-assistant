<?php

namespace Tests\Feature\Api;

use App\Models\ConversationMessage;
use App\Models\ConversationToolCall;
use App\Models\PurchaseOrderProposal;
use App\Enums\PurchaseOrderProposalStatus;
use App\Services\AI\Conversations\ConversationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use Tests\Support\ActsAsApiUser;
use Tests\Support\AiTestResponses;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class AssistantApiTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/assistant', ['message' => 'Hello'])
            ->assertUnauthorized();
    }

    public function test_validation_rejects_missing_empty_or_oversized_message(): void
    {
        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);

        $this->postJson('/api/assistant', ['message' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);

        Config::set('ai.assistant.max_message_chars', 10);
        $this->postJson('/api/assistant', ['message' => str_repeat('a', 11)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_rejects_unknown_fields(): void
    {
        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'Hi', 'extra' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['extra']);
    }

    public function test_invalid_conversation_id_format_returns_422(): void
    {
        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'Hello', 'conversation_id' => 'not-a-uuid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conversation_id']);
    }

    public function test_idempotency_key_without_conversation_id_returns_422(): void
    {
        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'Hello'], [
            'Idempotency-Key' => 'idem-1',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conversation_id']);
    }

    public function test_idempotency_key_replay_returns_same_payload(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        $this->disableAssistantStockGroundingForProviderToolTests();

        $this->seedCalacattaStockScenario();

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'idem_r1',
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
                        'idem_call',
                        'idem_fc',
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'idem_r2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('42 slabs idem.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);

        $conv = app(ConversationService::class)->createForUser($user);

        $headers = ['Idempotency-Key' => 'turn-abc-001'];
        $body = [
            'conversation_id' => $conv->public_id,
            'message' => 'Stock idem question',
        ];

        $first = $this->postJson('/api/assistant', $body, $headers)->assertOk();
        $second = $this->postJson('/api/assistant', $body, $headers)->assertOk();

        $this->assertSame($first->json('data.message'), $second->json('data.message'));
        $this->assertSame(1, ConversationToolCall::query()->where('conversation_id', $conv->id)->count());
    }

    public function test_idempotency_key_with_different_body_returns_409(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $openai = $this->openAiFinalAnswerFake();
        $this->app->instance(ClientContract::class, $openai);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($openai));

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(ConversationService::class)->createForUser($user);

        $headers = ['Idempotency-Key' => 'same-key'];

        $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'First',
        ], $headers)->assertOk();

        $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Second',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_body_mismatch');
    }

    public function test_other_users_conversation_returns_403_or_404(): void
    {
        $owner = $this->createSalesApiUser();
        $otherConv = app(ConversationService::class)->createForUser($owner);

        $this->actingAsSanctum($this->createSalesApiUser());

        $openai = $this->openAiFinalAnswerFake();
        $this->app->instance(ClientContract::class, $openai);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($openai));

        $this->postJson('/api/assistant', [
            'conversation_id' => $otherConv->public_id,
            'message' => 'Hi',
        ])->assertStatus(403);
    }

    public function test_sales_stock_question_returns_seeded_quantity_openai(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        $this->disableAssistantStockGroundingForProviderToolTests();

        $this->seedCalacattaStockScenario();

        $openai = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'r_tool',
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
                        'call_houston',
                        'fc_houston',
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(5, 5, 10),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'r_final',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('You have 42 slabs in Houston.')],
                'usage' => AiTestResponses::openAiUsage(10, 5, 15),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $openai);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($openai));

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->postJson('/api/assistant', [
                'message' => 'How many 3cm Calacatta slabs do we have in Houston?',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.clarification_required', false)
            ->assertJsonPath('data.completed', true);

        $body = $response->json('data');
        $this->assertNotEmpty($body['conversation_id']);
        $this->assertStringContainsString('42', (string) $body['message']);

        $conversation = \App\Models\Conversation::query()
            ->where('public_id', $body['conversation_id'])
            ->first();
        $this->assertNotNull($conversation);
        $this->assertSame(1, ConversationToolCall::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_follow_up_dallas_resolves_and_uses_stock_tool(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        $this->disableAssistantStockGroundingForProviderToolTests();

        $this->seedCalacattaStockScenario();

        $this->actingAsSanctum($this->createSalesApiUser());

        $seq = [
            CreateResponse::fake([
                'id' => 'a1',
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
                        'call_turn1',
                        'fc_turn1',
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'a2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('42 in Houston.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'b1',
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
                        'call_turn2',
                        'fc_turn2',
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'b2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('18 in Dallas.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ];

        $client = OpenAI::fake($seq);
        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

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
        $this->assertStringContainsString('18', (string) $second->json('data.message'));

        $lastUser = ConversationMessage::query()
            ->where('role', \App\Enums\ConversationMessageRole::User)
            ->orderByDesc('sequence')
            ->first();
        $this->assertSame('And in Dallas?', $lastUser->content);
        $this->assertStringContainsString('Dallas', (string) ($lastUser->metadata['resolved_standalone_question'] ?? ''));
    }

    public function test_ollama_conversation_follow_up_does_not_fail_after_prior_tool_turn(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');
        Config::set('ai.assistant.enforce_stock_grounding', false);

        $this->seedCalacattaStockScenario();
        $this->actingAsSanctum($this->createSalesApiUser());

        Http::fake([
            'http://ollama.test/api/chat' => Http::sequence()
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_houston',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => [
                                    'material' => 'Quartz',
                                    'color' => 'Calacatta',
                                    'thickness_cm' => '3.00',
                                    'warehouse_name' => 'Houston',
                                ],
                            ],
                        ]],
                    ],
                ], 200)
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '42 slabs in Houston.',
                    ],
                ], 200)
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_dallas',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => [
                                    'material' => 'Quartz',
                                    'color' => 'Calacatta',
                                    'thickness_cm' => '3.00',
                                    'warehouse_name' => 'Dallas',
                                ],
                            ],
                        ]],
                    ],
                ], 200)
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '18 slabs in Dallas.',
                    ],
                ], 200),
        ]);

        $first = $this->postJson('/api/assistant', [
            'message' => 'How many 3cm Calacatta slabs do we have in Houston?',
        ]);
        $first->assertStatus(202);
        $run1 = \App\Models\AssistantRun::query()->where('public_id', $first->json('data.run_id'))->first();
        $this->assertNotNull($run1);
        app(\App\Services\AI\AssistantRequestService::class)->processQueuedAssistantRun($run1);

        $cid = $first->json('data.conversation_id');

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $cid,
            'message' => 'And in Dallas?',
        ]);

        $second->assertStatus(202);
        $run2 = \App\Models\AssistantRun::query()->where('public_id', $second->json('data.run_id'))->first();
        $this->assertNotNull($run2);
        app(\App\Services\AI\AssistantRequestService::class)->processQueuedAssistantRun($run2);

        $poll = $this->getJson('/api/assistant/runs/'.$run2->public_id);
        $poll->assertOk();
        $this->assertStringContainsString('18', (string) $poll->json('data.answer'));

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'http://ollama.test/api/chat') {
                return false;
            }

            $messages = $request->data()['messages'] ?? [];
            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'tool' && ($message['tool_name'] ?? '') === 'checkStock') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_clarification_does_not_call_tools(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.conversation.follow_up.use_ai_when_ambiguous', false);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);

        $openai = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'only',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('should not run')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $openai);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($openai));

        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'How many 3cm Calacatta slabs are in Houston?');
        $svc->appendAssistantMessage($user, $conv, 'You have 42 slabs in Houston.');

        $beforeCalls = ConversationToolCall::query()->count();

        $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'maybe?',
        ])
            ->assertOk()
            ->assertJsonPath('data.clarification_required', true);

        $this->assertSame($beforeCalls, ConversationToolCall::query()->count());
    }

    public function test_claude_path_works_with_http_fake(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');
        $this->disableAssistantStockGroundingForProviderToolTests();

        $this->seedCalacattaStockScenario();

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push([
                    'id' => 'msg_a',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'tool_use',
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'toolu_1',
                            'name' => 'checkStock',
                            'input' => [
                                'material' => 'Quartz',
                                'color' => 'Calacatta',
                                'thickness_cm' => '3.00',
                                'warehouse_name' => 'Houston',
                            ],
                        ],
                    ],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 4],
                ], 200)
                ->push([
                    'id' => 'msg_b',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'end_turn',
                    'content' => [
                        ['type' => 'text', 'text' => '42 slabs in Houston.'],
                    ],
                    'usage' => ['input_tokens' => 8, 'output_tokens' => 6],
                ], 200),
        ]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', [
                'message' => 'How many 3cm Calacatta slabs in Houston?',
            ])
            ->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.provider', 'claude');
    }

    public function test_openai_sales_supplier_bank_details_returns_role_refusal_not_execution_failed(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_sales_bank_refusal', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['completed'] ?? false);
        $this->assertStringContainsStringIgnoringCase('Sales role', (string) ($json['data']['message'] ?? ''));
        $this->assertNull($json['data']['provider']);
        $this->assertNotSame('assistant_execution_failed', $json['error']['code'] ?? null);
    }

    public function test_openai_purchasing_supplier_bank_details_uses_search_without_leak(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchasing_bank_disclosure', true);

        $supplier = $this->createSupplierWithName('Demo Supplier — Gulf Stone Imports');
        $supplier->bank_details = 'Demo Bank — Account DEMO-GSI-API — Routing DEMO-111000';
        $supplier->save();

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertStringContainsString('DEMO-GSI-API', (string) ($json['data']['message'] ?? ''));
        $this->assertNull($json['data']['provider']);
    }

    public function test_claude_sales_supplier_bank_details_returns_role_refusal_not_execution_failed(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');
        Config::set('ai.assistant.enforce_sales_bank_refusal', true);

        Http::fake();

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['completed'] ?? false);
        $this->assertNull($json['data']['provider']);
        $this->assertStringContainsStringIgnoringCase('Sales role', (string) ($json['data']['message'] ?? ''));
        $this->assertNotSame('assistant_execution_failed', $json['error']['code'] ?? null);
        Http::assertNothingSent();
    }

    public function test_claude_purchasing_supplier_bank_details_can_call_search_suppliers(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');
        Config::set('ai.assistant.enforce_purchasing_bank_disclosure', true);

        $supplier = $this->createSupplierWithName('Demo Supplier — Gulf Stone Imports');
        $supplier->bank_details = 'Demo Bank — Account DEMO-GSI-CLAUDE';
        $supplier->save();

        Http::fake();

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertNull($json['data']['provider']);
        $this->assertStringContainsString('DEMO-GSI-CLAUDE', (string) ($json['data']['message'] ?? ''));
        Http::assertNothingSent();
    }

    public function test_sales_get_landed_cost_tool_denied_in_answer(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'lc1',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall(
                        'getLandedCost',
                        json_encode(['purchase_order_id' => 999], JSON_THROW_ON_ERROR),
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'lc2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('That operation is not permitted for your role.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
                'message' => 'What is the landed cost for purchase order 999?',
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($json['success']);
        $this->assertStringContainsStringIgnoringCase('permit', (string) $json['data']['message']);
    }

    public function test_purchasing_can_run_landed_cost_tool(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchasing_landed_cost_disclosure', false);

        $supplier = $this->createSupplierWithName('LC Assist B');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            \App\Enums\PurchaseOrderStatus::Ordered,
            '2026-01-10',
            '2026-01-20',
            '100.00',
            [['product' => $product, 'quantity' => 2, 'unit_cost' => '50.00']],
        );

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'p1',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall(
                        'getLandedCost',
                        json_encode(['purchase_order_id' => $order->id], JSON_THROW_ON_ERROR),
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'p2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Here are the landed cost figures.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $this->postJson('/api/assistant', [
                'message' => 'Landed cost for PO '.$order->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        $this->assertSame(1, ConversationToolCall::query()->where('tool_name', 'getLandedCost')->count());
    }

    public function test_purchasing_proposal_tool_creates_pending_proposal_not_po(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Assist Supplier');
        $warehouseId = $this->createHoustonDallasAustinWarehouses()['houston']->id;

        $args = json_encode([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'freight_cost' => '10.00',
            'lines' => [
                ['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => 2, 'unit_cost' => '25.00'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'pr1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('proposeDraftPurchaseOrder', $args)],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'pr2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('A draft proposal was created; confirm via the API to create a PO.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $this->postJson('/api/assistant', [
                'message' => 'Create a draft purchase order for 2 slabs from Assist Supplier.',
            ])
            ->assertOk();

        $proposal = PurchaseOrderProposal::query()->first();
        $this->assertNotNull($proposal);
        $this->assertSame(PurchaseOrderProposalStatus::Pending, $proposal->status);
    }

    public function test_provider_authentication_failure_returns_502(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', '');

        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'Hello'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'ai_authentication_failed');
    }

    public function test_rate_limit_returns_429(): void
    {
        RateLimiter::clear('api-assistant');

        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.rate_limit_per_minute', 1);

        $openai = $this->openAiFinalAnswerFake();
        $this->app->instance(ClientContract::class, $openai);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($openai));

        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'A'])->assertOk();
        $this->postJson('/api/assistant', ['message' => 'B'])->assertStatus(429);
    }

    public function test_tool_loop_limit_maps_to_422(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.tool_loop.max_iterations', 0);

        $this->seedCalacattaStockScenario();

        $args = json_encode([
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'warehouse_name' => 'Houston',
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'loop',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('checkStock', $args)],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'Stock check Houston'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'tool_loop_limit');
    }

    private function openAiFinalAnswerFake(): \OpenAI\Contracts\ClientContract
    {
        return OpenAI::fake([
            CreateResponse::fake([
                'id' => 'final',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Ok.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);
    }
}
