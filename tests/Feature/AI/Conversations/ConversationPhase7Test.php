<?php

namespace Tests\Feature\AI\Conversations;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiToolCall;
use App\Enums\ConversationMessageRole;
use App\Enums\ConversationToolCallStatus;
use App\Models\Conversation;
use App\Models\ConversationAiInteraction;
use App\Models\ConversationMessage;
use App\Models\ConversationSummary;
use App\Models\ConversationToolCall;
use App\Services\AI\AiConversationRecording;
use App\Services\AI\AiToolCallingService;
use App\Services\AI\Conversations\ConversationAccessException;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Conversations\ConversationSummaryService;
use App\Services\AI\Context\ConversationContextManager;
use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Support\AiSafeText;
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

class ConversationPhase7Test extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_user_can_create_conversation_and_belongs_to_owner(): void
    {
        $user = $this->createSalesApiUser();
        $conversation = app(ConversationService::class)->createForUser($user, 'Stock questions');

        $this->assertNotEmpty($conversation->public_id);
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertTrue($conversation->user->is($user));
    }

    public function test_cannot_access_another_users_conversation(): void
    {
        $owner = $this->createSalesApiUser();
        $other = $this->createSalesApiUser();
        $conv = app(ConversationService::class)->createForUser($owner);

        $this->expectException(ConversationAccessException::class);
        app(ConversationService::class)->getOwnedByPublicId($other, $conv->public_id);
    }

    public function test_messages_store_original_and_resolved_metadata(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $msg = $svc->appendUserMessage(
            $user,
            $conv,
            'And in Dallas?',
            resolvedStandaloneQuestion: 'How many 3cm Calacatta slabs are in Dallas?',
            resolutionMetadata: ['strategy' => 'test'],
        );

        $this->assertSame('And in Dallas?', $msg->content);
        $this->assertSame(
            'How many 3cm Calacatta slabs are in Dallas?',
            $msg->metadata['resolved_standalone_question'] ?? null
        );

        $rows = $svc->chronologicalMessages($conv->fresh());
        $this->assertCount(1, $rows);
    }

    public function test_tool_traces_persist_with_order_and_safe_results(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $userMsg = $svc->appendUserMessage($user, $conv, 'Check stock');

        $recorder = new \App\Services\AI\Conversations\ConversationTurnRecorder($conv, $userMsg);
        $recorder->add(
            new AiToolCall('tc1', 'checkStock', ['warehouse_name' => 'Houston']),
            new ToolExecutionResult(success: true, data: ['slabs_on_hand' => 5])
        );

        $svc->persistToolTraces($user, $recorder);

        $this->assertCount(1, ConversationToolCall::query()->where('conversation_id', $conv->id)->get());
        $tool = ConversationToolCall::query()->first();
        $this->assertSame('tc1', $tool->provider_tool_call_id);
        $this->assertSame(ConversationToolCallStatus::Completed, $tool->status);

        $messages = ConversationMessage::query()->where('conversation_id', $conv->id)->orderBy('sequence')->get();
        $this->assertTrue($messages->last()->role === ConversationMessageRole::Tool);
    }

    public function test_context_manager_respects_max_messages(): void
    {
        Config::set('ai.conversation.context.max_messages', 3);
        Config::set('ai.conversation.context.max_chars', 50000);

        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        for ($i = 1; $i <= 6; $i++) {
            $svc->appendUserMessage($user, $conv, "Question {$i}");
            $svc->appendAssistantMessage($user, $conv, "Answer {$i}");
        }

        $ctx = app(ConversationContextManager::class)->buildAiMessages($conv->fresh(), $user);

        $nonSystem = array_values(array_filter($ctx, fn ($m) => $m->role !== AiMessage::ROLE_SYSTEM));
        $this->assertLessThanOrEqual(3, count($nonSystem));
    }

    public function test_sales_context_excludes_prior_get_landed_cost_tool_rows(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $userMsg = $svc->appendUserMessage($user, $conv, 'Show landed cost for PO 1');
        $recorder = new \App\Services\AI\Conversations\ConversationTurnRecorder($conv, $userMsg);
        $recorder->add(
            new AiToolCall('call-lc', 'getLandedCost', ['purchase_order_id' => 1]),
            new ToolExecutionResult(
                success: true,
                data: ['purchase_order_id' => 1, 'lines' => [['landed_unit_cost' => '99.99']]],
            )
        );
        $svc->persistToolTraces($user, $recorder);

        $messages = app(ConversationContextManager::class)->buildAiMessages($conv->fresh(), $user);

        $toolMessages = array_values(array_filter($messages, fn ($m) => $m->role === AiMessage::ROLE_TOOL));
        $this->assertCount(0, $toolMessages);
    }

    public function test_purchasing_context_includes_get_landed_cost_tool_rows(): void
    {
        $user = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $userMsg = $svc->appendUserMessage($user, $conv, 'Landed cost for PO 1');
        $recorder = new \App\Services\AI\Conversations\ConversationTurnRecorder($conv, $userMsg);
        $recorder->add(
            new AiToolCall('call-lc', 'getLandedCost', ['purchase_order_id' => 1]),
            new ToolExecutionResult(
                success: true,
                data: ['purchase_order_id' => 1, 'lines' => [['landed_unit_cost' => '12.34']]],
            )
        );
        $svc->persistToolTraces($user, $recorder);

        $messages = app(ConversationContextManager::class)->buildAiMessages($conv->fresh(), $user);
        $found = false;
        foreach ($messages as $m) {
            if ($m->role === AiMessage::ROLE_TOOL && $m->toolPayload !== null) {
                $payloadJson = json_encode($m->toolPayload);
                if (str_contains($payloadJson, 'landed_unit_cost')) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assertTrue($found, 'Purchasing viewers should receive getLandedCost tool payloads in context.');
    }

    public function test_follow_up_deterministic_city_swap(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $svc->appendUserMessage(
            $user,
            $conv,
            'How many 3cm Calacatta slabs are in Houston?',
        );
        $svc->appendAssistantMessage($user, $conv, 'You have 42 slabs in Houston.');

        $resolver = app(FollowUpQuestionResolver::class);
        $result = $resolver->resolve('And in Dallas?', $conv->fresh());

        $this->assertFalse($result->needsClarification);
        $this->assertStringContainsString('Dallas', $result->resolvedStandaloneQuestion ?? '');
        $this->assertStringContainsString('Calacatta', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_follow_up_deterministic_thickness(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $svc->appendUserMessage($user, $conv, 'How many 3cm Calacatta slabs are in Houston?');
        $svc->appendAssistantMessage($user, $conv, '42 slabs.');

        $result = app(FollowUpQuestionResolver::class)->resolve('What about 2cm?', $conv->fresh());

        $this->assertFalse($result->needsClarification);
        $this->assertStringContainsString('2 cm', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_follow_up_overdue_supplier_swap(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $svc->appendUserMessage($user, $conv, 'Show overdue purchase orders for Supplier Alpha Tools.');
        $svc->appendAssistantMessage($user, $conv, 'Listed 0 overdue orders.');

        $result = app(FollowUpQuestionResolver::class)->resolve('What about Supplier Beta?', $conv->fresh());

        $this->assertFalse($result->needsClarification);
        $this->assertStringContainsString('Supplier Beta', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_standalone_question_not_rewritten(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Previous short question');
        $svc->appendAssistantMessage($user, $conv, 'Ok');

        $long = str_repeat('word ', 25).'about stock levels in multiple warehouses and suppliers?';
        $result = app(FollowUpQuestionResolver::class)->resolve($long, $conv->fresh());

        $this->assertTrue($result->wasAlreadyStandalone);
    }

    public function test_follow_up_ai_resolver_parses_json(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'output' => [
                    AiTestResponses::openAiAssistantMessage(
                        '{"is_standalone":false,"needs_clarification":false,"resolved_question":"Full rewritten question here?","clarification_question":null}'
                    ),
                ],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Show stock for SKU X in warehouse Y');
        $svc->appendAssistantMessage($user, $conv, '10 units.');

        $result = app(FollowUpQuestionResolver::class)->resolve('maybe tweak?', $conv->fresh());

        $this->assertFalse($result->wasAlreadyStandalone);
        $this->assertSame('Full rewritten question here?', $result->resolvedStandaloneQuestion);
    }

    public function test_summary_triggers_after_threshold(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.conversation.summary.min_messages_for_summary', 2);
        Config::set('ai.conversation.summary.refresh_message_interval', 1);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'output' => [AiTestResponses::openAiAssistantMessage('Summary line 1.')],
                'usage' => AiTestResponses::openAiUsage(2, 2, 4),
            ], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Q1');
        $svc->appendAssistantMessage($user, $conv, 'A1');
        $svc->appendUserMessage($user, $conv, 'Q2');
        $svc->appendAssistantMessage($user, $conv, 'A2');

        app(ConversationSummaryService::class)->maybeRefreshAfterTurn($conv->fresh());

        $this->assertSame(1, ConversationSummary::query()->where('conversation_id', $conv->id)->count());
        $this->assertGreaterThan(0, ConversationMessage::query()->where('conversation_id', $conv->id)->count());
    }

    public function test_proposal_tool_receives_trusted_context_from_conversation_recording(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $scenario = $this->seedCalacattaStockScenario();
        $product = $scenario['product'];
        $supplier = $this->createSupplierWithName('Conv Proposal Supplier');

        $args = json_encode([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'freight_cost' => '10.00',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '20.00'],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'p1',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiFunctionCall('proposeDraftPurchaseOrder', $args, 'call_p', 'fc_p')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
            CreateResponse::fake([
                'id' => 'p2',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Proposal pending.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->app->instance(ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $purchasing = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($purchasing);
        $userMsg = $svc->appendUserMessage($purchasing, $conv, 'Draft a PO');

        $recording = new AiConversationRecording($purchasing, $conv, $userMsg, $svc);

        $outcome = app(AiToolCallingService::class)->run(
            new AiRequest(messages: [AiMessage::user('Draft a PO')]),
            $purchasing,
            conversationRecording: $recording,
        );

        $this->assertStringContainsString('Proposal', $outcome->text);

        $proposal = \App\Models\PurchaseOrderProposal::query()->first();
        $this->assertNotNull($proposal);
        $this->assertSame($conv->public_id, $proposal->conversation_key);
        $this->assertSame($userMsg->turn_idempotency_key, $proposal->idempotency_key);

        $interaction = ConversationAiInteraction::query()->where('conversation_id', $conv->id)->first();
        $this->assertNotNull($interaction);
        $this->assertSame($outcome->toolCallsExecuted, $interaction->tool_calls_executed);
    }

    public function test_ai_safe_text_for_loop_exception(): void
    {
        $e = new \App\Services\AI\Exceptions\AiToolLoopException('x', \App\Services\AI\Exceptions\AiToolLoopException::CODE_MAX_ITERATIONS);
        $this->assertNotSame('', AiSafeText::forLoopException($e));
    }
}
