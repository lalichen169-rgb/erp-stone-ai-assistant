<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Services\AI\AiToolCallingService;
use App\Services\AI\Tools\ToolExecutionContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use Tests\Support\AiTestResponses;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class AiToolCallingServiceTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_openai_returns_final_text_without_tools(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'resp_final',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiAssistantMessage('Hello'),
                ],
                'usage' => AiTestResponses::openAiUsage(3, 2, 5),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $user = $this->createSalesApiUser();
        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Hi')]),
            $user,
        );

        $this->assertSame('Hello', $outcome->text);
        $this->assertSame(0, $outcome->toolIterations);
        $this->assertSame(0, $outcome->toolCallsExecuted);
        $this->assertTrue($outcome->completed);
        $this->assertSame(3, $outcome->inputTokens);
        $this->assertSame(2, $outcome->outputTokens);
    }

    public function test_openai_one_tool_then_final_answer(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $this->seedCalacattaStockScenario();

        $args = json_encode([
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'warehouse_name' => 'Houston',
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'resp_tool',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall('checkStock', $args, 'call_stock', 'fc_1'),
                ],
                'usage' => AiTestResponses::openAiUsage(10, 5, 15),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'resp_done',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiAssistantMessage('You have 42 slabs.'),
                ],
                'usage' => AiTestResponses::openAiUsage(20, 3, 23),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $user = $this->createSalesApiUser();
        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('How many slabs in Houston?')]),
            $user,
        );

        $this->assertStringContainsString('42', $outcome->text);
        $this->assertSame(1, $outcome->toolIterations);
        $this->assertSame(1, $outcome->toolCallsExecuted);
        $this->assertSame(30, $outcome->inputTokens);
        $this->assertSame(8, $outcome->outputTokens);

        OpenAI::assertSent(\OpenAI\Resources\Responses::class, function (string $method, array $parameters): bool {
            if ($method !== 'create') {
                return false;
            }

            $input = $parameters['input'] ?? null;

            return is_array($input)
                && isset($input[0]['call_id'], $input[0]['output'])
                && $input[0]['type'] === 'function_call_output';
        });
    }

    public function test_openai_multiple_tools_in_one_response(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $this->seedCalacattaStockScenario();

        $a1 = json_encode(['material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => '3.00', 'warehouse_name' => 'Houston'], JSON_THROW_ON_ERROR);
        $a2 = json_encode(['material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => '3.00', 'warehouse_name' => 'Dallas'], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'resp_multi',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall('checkStock', $a1, 'c1', 'fc1'),
                    AiTestResponses::openAiFunctionCall('checkStock', $a2, 'c2', 'fc2'),
                ],
                'usage' => AiTestResponses::openAiUsage(5, 4, 9),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'resp_final',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Done')],
                'usage' => AiTestResponses::openAiUsage(8, 1, 9),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Compare Houston and Dallas')]),
            $this->createSalesApiUser(),
        );

        $this->assertSame(1, $outcome->toolIterations);
        $this->assertSame(2, $outcome->toolCallsExecuted);
        $this->assertSame('Done', $outcome->text);
    }

    public function test_openai_multi_iteration_tool_sequence(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $scenario = $this->seedCalacattaStockScenario();

        $stockArgs = json_encode([
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'warehouse_name' => 'Houston',
        ], JSON_THROW_ON_ERROR);

        $supplier = $this->createSupplierWithName('SO Multi');
        $product = $scenario['product'];
        $this->createPurchaseOrderWithLines(
            $supplier,
            \App\Enums\PurchaseOrderStatus::Ordered,
            '2026-10-01',
            '2026-11-15',
            '10.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '50.00']]
        );

        $searchArgs = json_encode(['supplier_name' => 'SO Multi', 'limit' => 5], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'r1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('checkStock', $stockArgs, 't1', 'f1')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'r2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('searchPurchaseOrders', $searchArgs, 't2', 'f2')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'r3',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Summary complete.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Stock then orders')]),
            $this->createSalesApiUser(),
        );

        $this->assertSame(2, $outcome->toolIterations);
        $this->assertSame(2, $outcome->toolCallsExecuted);
        $this->assertStringContainsString('Summary', $outcome->text);
    }

    public function test_openai_unknown_tool_returns_error_to_model_and_can_finish(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'bad_tool',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall('not_a_real_tool', '{}', 'cx', 'fx'),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'after',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('I cannot use that tool.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Try bad tool')]),
            $this->createSalesApiUser(),
        );

        $this->assertStringContainsString('cannot', strtolower($outcome->text));
        $this->assertSame(1, $outcome->toolCallsExecuted);
    }

    public function test_openai_sales_denied_landed_cost_surfaces_permission_error(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $supplier = $this->createSupplierWithName('LC Deny');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            \App\Enums\PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '100.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '50.00']]
        );

        $args = json_encode(['purchase_order_id' => $order->id], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'lc_req',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('getLandedCost', $args, 'lc1', 'l1')],
                'usage' => AiTestResponses::openAiUsage(2, 1, 3),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'lc_fin',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Not permitted for your role.')],
                'usage' => AiTestResponses::openAiUsage(2, 2, 4),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $sales = $this->createSalesApiUser();

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Landed cost please')]),
            $sales,
        );

        $this->assertStringContainsString('Not permitted', $outcome->text);
        $this->assertSame(1, $outcome->toolCallsExecuted);
    }

    public function test_openai_max_iterations_enforced(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.tool_loop.max_iterations', 1);

        $args = json_encode(['material' => 'Q', 'color' => 'C', 'thickness_cm' => '3', 'warehouse_name' => 'H'], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'loop1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('checkStock', $args, 'c', 'f')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'loop2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('checkStock', $args, 'c', 'f')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Loop')]),
            $this->createSalesApiUser(),
        );

        $this->assertFalse($outcome->completed);
        $this->assertStringContainsStringIgnoringCase('tool-calling limit', $outcome->text);
    }

    public function test_openai_max_tool_calls_enforced(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.tool_loop.max_tool_calls', 1);

        $this->seedCalacattaStockScenario();

        $a1 = json_encode(['material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => '3.00', 'warehouse_name' => 'Houston'], JSON_THROW_ON_ERROR);
        $a2 = json_encode(['material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => '3.00', 'warehouse_name' => 'Dallas'], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'two',
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiFunctionCall('checkStock', $a1, 'c1', 'f1'),
                    AiTestResponses::openAiFunctionCall('checkStock', $a2, 'c2', 'f2'),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Two at once')]),
            $this->createSalesApiUser(),
        );

        $this->assertFalse($outcome->completed);
        $this->assertStringContainsStringIgnoringCase('too many tools', $outcome->text);
    }

    public function test_openai_repeated_identical_calls_stop_loop(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $this->seedCalacattaStockScenario();

        $args = json_encode([
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'warehouse_name' => 'Houston',
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'rep1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('checkStock', $args, 'c1', 'f1')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'rep2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('checkStock', $args, 'c2', 'f2')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Repeat')]),
            $this->createSalesApiUser(),
        );

        $this->assertFalse($outcome->completed);
        $this->assertStringContainsStringIgnoringCase('repeated', $outcome->text);
    }

    public function test_claude_tool_then_text_and_ordering(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');

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
                    'usage' => AiTestResponses::openAiUsage(5, 4, 9),
                ], 200)
                ->push([
                    'id' => 'msg_b',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'end_turn',
                    'content' => [
                        ['type' => 'text', 'text' => '42 slabs available.'],
                    ],
                    'usage' => AiTestResponses::openAiUsage(10, 6, 16),
                ], 200),
        ]);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Stock in Houston?')]),
            $this->createSalesApiUser(),
        );

        $this->assertStringContainsString('42', $outcome->text);
        $this->assertSame(1, $outcome->toolIterations);
        $this->assertSame(1, $outcome->toolCallsExecuted);
        $this->assertSame(15, $outcome->inputTokens);
        $this->assertSame(10, $outcome->outputTokens);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
                return false;
            }

            $data = $request->data();

            return isset($data['messages'][1]['role'], $data['messages'][2]['role'])
                && $data['messages'][1]['role'] === 'assistant'
                && $data['messages'][2]['role'] === 'user'
                && ($data['messages'][2]['content'][0]['type'] ?? '') === 'tool_result'
                && ($data['messages'][2]['content'][0]['tool_use_id'] ?? '') === 'toolu_1';
        });
    }

    public function test_claude_multiple_tool_use_blocks(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');

        $this->seedCalacattaStockScenario();

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push([
                    'id' => 'm1',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'tool_use',
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'tu_h',
                            'name' => 'checkStock',
                            'input' => [
                                'material' => 'Quartz',
                                'color' => 'Calacatta',
                                'thickness_cm' => '3.00',
                                'warehouse_name' => 'Houston',
                            ],
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'tu_d',
                            'name' => 'checkStock',
                            'input' => [
                                'material' => 'Quartz',
                                'color' => 'Calacatta',
                                'thickness_cm' => '3.00',
                                'warehouse_name' => 'Dallas',
                            ],
                        ],
                    ],
                    'usage' => AiTestResponses::openAiUsage(2, 2, 4),
                ], 200)
                ->push([
                    'id' => 'm2',
                    'model' => 'claude-test-model',
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => 'Compared.']],
                    'usage' => AiTestResponses::openAiUsage(2, 2, 4),
                ], 200),
        ]);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Compare')]),
            $this->createSalesApiUser(),
        );

        $this->assertSame(2, $outcome->toolCallsExecuted);
        $this->assertSame('Compared.', trim($outcome->text));
    }

    public function test_propose_draft_missing_context_fails_via_tool_result(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('PropCtx');
        $warehouseId = $this->createHoustonDallasAustinWarehouses()['houston']->id;

        $args = json_encode([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'freight_cost' => '10.00',
            'lines' => [
                ['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => 1, 'unit_cost' => '20.00'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'prop1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('proposeDraftPurchaseOrder', $args, 'p1', 'pf1')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'prop2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Cannot propose without server context.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Draft PO')]),
            $this->createPurchasingApiUser(),
        );

        $this->assertStringContainsString('Cannot propose', $outcome->text);
    }

    public function test_model_cannot_override_trusted_proposal_context_via_arguments(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('PropOverride');
        $warehouseId = $this->createHoustonDallasAustinWarehouses()['houston']->id;

        $args = json_encode([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'freight_cost' => '10.00',
            'conversation_key' => 'hacker',
            'idempotency_key' => 'bad',
            'lines' => [
                ['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => 1, 'unit_cost' => '20.00'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'ov1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('proposeDraftPurchaseOrder', $args, 'p', 'f')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'ov2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Blocked.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $purchasing = $this->createPurchasingApiUser();

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Try override')]),
            $purchasing,
            new ToolExecutionContext(
                $purchasing,
                conversationKey: 'trusted-conv',
                idempotencyKey: 'trusted-idem',
            ),
        );

        $this->assertSame('Blocked.', $outcome->text);
    }

    public function test_ollama_tool_then_text_and_ordering(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');

        $this->seedCalacattaStockScenario();

        Http::fake([
            'http://ollama.test/api/chat' => Http::sequence()
                ->push([
                    'model' => 'llama3.1:8b',
                    'prompt_eval_count' => 5,
                    'eval_count' => 4,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_stock_1',
                                'function' => [
                                    'name' => 'checkStock',
                                    'arguments' => [
                                        'material' => 'Quartz',
                                        'color' => 'Calacatta',
                                        'thickness_cm' => '3.00',
                                        'warehouse_name' => 'Houston',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'model' => 'llama3.1:8b',
                    'prompt_eval_count' => 10,
                    'eval_count' => 6,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '42 slabs available.',
                    ],
                ], 200),
        ]);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Stock in Houston?')]),
            $this->createSalesApiUser(),
        );

        $this->assertStringContainsString('42', $outcome->text);
        $this->assertSame(1, $outcome->toolIterations);
        $this->assertSame(1, $outcome->toolCallsExecuted);
        $this->assertSame(15, $outcome->inputTokens);
        $this->assertSame(10, $outcome->outputTokens);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'http://ollama.test/api/chat') {
                return false;
            }

            $data = $request->data();
            $messages = $data['messages'] ?? [];

            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'tool'
                    && ($message['tool_call_id'] ?? '') === 'call_stock_1') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_ollama_sales_denied_landed_cost_surfaces_permission_error(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');

        $supplier = $this->createSupplierWithName('Ollama LC Deny');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            \App\Enums\PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '100.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '50.00']]
        );

        Http::fake([
            'http://ollama.test/api/chat' => Http::sequence()
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'lc_ollama',
                                'function' => [
                                    'name' => 'getLandedCost',
                                    'arguments' => ['purchase_order_id' => $order->id],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Not permitted for your role.',
                    ],
                ], 200),
        ]);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Landed cost please')]),
            $this->createSalesApiUser(),
        );

        $this->assertStringContainsString('Not permitted', $outcome->text);
        $this->assertSame(1, $outcome->toolCallsExecuted);
    }

    public function test_ollama_builds_history_when_request_includes_prior_tool_messages(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');

        $this->seedCalacattaStockScenario();

        Http::fake([
            'http://ollama.test/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'dallas_call',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => [
                                    'material' => 'Quartz',
                                    'color' => 'Calacatta',
                                    'thickness_cm' => '3.00',
                                    'warehouse_name' => 'Dallas',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $history = [
            AiMessage::user('How many 3cm Calacatta slabs do we have in Houston?'),
            AiMessage::toolResult('houston_call', [
                'success' => true,
                'data' => ['slabs_on_hand' => 42],
            ], 'checkStock', ['warehouse_name' => 'Houston']),
            AiMessage::assistant('42 in Houston.'),
            AiMessage::user('And in Dallas?'),
        ];

        app(AiToolCallingService::class)->run(
            new AiRequest(messages: $history),
            $this->createSalesApiUser(),
        );

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'http://ollama.test/api/chat') {
                return false;
            }

            $messages = $request->data()['messages'] ?? [];
            $hasHistoricalTool = false;
            $hasHistoricalAssistantCalls = false;
            $hasDallasUser = false;

            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'tool' && ($message['tool_call_id'] ?? '') === 'houston_call') {
                    $hasHistoricalTool = true;
                }
                if (($message['role'] ?? '') === 'assistant' && isset($message['tool_calls'])) {
                    $hasHistoricalAssistantCalls = true;
                }
                if (($message['role'] ?? '') === 'user' && ($message['content'] ?? '') === 'And in Dallas?') {
                    $hasDallasUser = true;
                }
            }

            return $hasHistoricalTool && $hasHistoricalAssistantCalls && $hasDallasUser;
        });
    }

    public function test_ollama_failure_outcome_reports_configured_ollama_provider_and_model(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');

        Http::fake([
            'http://ollama.test/api/chat' => function (): void {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Hello')]),
            $this->createSalesApiUser(),
        );

        $this->assertFalse($outcome->completed);
        $this->assertSame('ollama', $outcome->provider);
        $this->assertSame('llama3.1:8b', $outcome->model);
        $this->assertSame('timeout', $outcome->errorCode);
    }
}
