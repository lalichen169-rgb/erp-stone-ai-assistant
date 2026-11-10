<?php

namespace Tests\Feature\Api;

use App\Enums\AssistantRunStatus;
use App\Jobs\ProcessOllamaAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\Conversations\ConversationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use Tests\Support\ActsAsApiUser;
use Tests\Support\AiTestResponses;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class AssistantAsyncTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_ollama_submission_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        Config::set('ai.provider', 'ollama');

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->postJson('/api/assistant', [
            'message' => 'Hello async',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.async', true)
            ->assertJsonPath('data.status', 'queued');

        $runId = $response->json('data.run_id');
        $this->assertIsString($runId);

        Queue::assertPushed(ProcessOllamaAssistantRun::class, 1);
        Queue::assertPushed(ProcessOllamaAssistantRun::class, function (ProcessOllamaAssistantRun $job): bool {
            return $job->connection === 'redis'
                && $job->queue === config('ai.assistant.async.queue', 'default');
        });
    }

    public function test_ollama_api_does_not_run_inference_during_request(): void
    {
        Queue::fake();
        Http::fake();
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->postJson('/api/assistant', ['message' => 'No inline inference']);
        $response->assertStatus(202);

        Http::assertNothingSent();

        $run = AssistantRun::query()->where('public_id', $response->json('data.run_id'))->first();
        $this->assertNotNull($run);
        $this->assertSame(AssistantRunStatus::Queued, $run->status);
    }

    public function test_claude_submission_remains_synchronous_without_queue(): void
    {
        Queue::fake();
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'test-key');

        Http::fake([
            'https://api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'Claude sync.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $this->postJson('/api/assistant', ['message' => 'Claude question'])
            ->assertOk()
            ->assertJsonPath('data.message', 'Claude sync.');

        Queue::assertNothingPushed();
    }

    public function test_openai_submission_remains_synchronous_200(): void
    {
        Queue::fake();
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $openai = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'sync',
                'model' => 'gpt-4o-mini',
                'output' => [AiTestResponses::openAiAssistantMessage('Sync reply.')],
                'usage' => AiTestResponses::openAiUsage(1, 1, 2),
            ], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $openai);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($openai));

        $this->actingAsSanctum($this->createSalesApiUser());

        $response = $this->postJson('/api/assistant', ['message' => 'Sync question']);

        $response->assertOk()
            ->assertJsonPath('data.message', 'Sync reply.')
            ->assertJsonMissingPath('data.async');

        Queue::assertNothingPushed();
    }

    public function test_queue_dispatch_failure_marks_run_failed(): void
    {
        Config::set('ai.provider', 'ollama');
        $user = $this->createSalesApiUser();
        $conv = app(ConversationService::class)->createForUser($user);
        $msg = app(ConversationService::class)->appendUserMessage($user, $conv, 'Queue down');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Failed,
            'original_message' => 'Queue down',
            'request_hash' => hash('sha256', 'Queue down'),
            'error_code' => 'queue_dispatch_failed',
            'error_message' => 'The assistant request could not be queued. Try again.',
            'completed_at' => now(),
        ]);

        $this->actingAsSanctum($user);

        $this->getJson('/api/assistant/runs/'.$run->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.code', 'queue_dispatch_failed');
    }

    public function test_duplicate_idempotency_does_not_dispatch_second_job(): void
    {
        Queue::fake();
        Config::set('ai.provider', 'ollama');

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(ConversationService::class)->createForUser($user);

        $headers = ['Idempotency-Key' => 'async-idem-1'];
        $body = [
            'conversation_id' => $conv->public_id,
            'message' => 'Same async message',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/assistant', $body);
        $first->assertStatus(202);
        $this->assertSame(1, AssistantRun::query()->count());

        $second = $this->withHeaders($headers)->postJson('/api/assistant', $body);
        $second->assertStatus(202);
        $this->assertSame(1, AssistantRun::query()->count());
        $this->assertSame(1, ConversationMessage::query()->where('client_idempotency_key', 'async-idem-1')->count());
        $this->assertSame($first->json('data.run_id'), $second->json('data.run_id'));

        $runIds = collect(Queue::pushed(ProcessOllamaAssistantRun::class))
            ->map(static fn (ProcessOllamaAssistantRun $job) => $job->assistantRunId)
            ->unique()
            ->values();
        $this->assertSame(1, $runIds->count());
    }

    public function test_user_cannot_view_another_users_run(): void
    {
        Config::set('ai.provider', 'ollama');
        Queue::fake();

        $owner = $this->createSalesApiUser();
        $this->actingAsSanctum($owner);
        $res = $this->postJson('/api/assistant', ['message' => 'Mine']);
        $runId = $res->json('data.run_id');

        $this->actingAsSanctum($this->createSalesApiUser());
        $this->getJson('/api/assistant/runs/'.$runId)
            ->assertNotFound();
    }

    public function test_job_completes_run_and_persists_answer(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');
        Config::set('ai.ollama.model', 'llama3.1:8b');
        $this->seedCalacattaStockScenario();

        Http::fake([
            'http://ollama.test/api/chat' => Http::sequence()
                ->push([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Queued answer.',
                    ],
                ], 200),
        ]);

        Queue::fake();
        $this->actingAsSanctum($this->createSalesApiUser());
        $accepted = $this->postJson('/api/assistant', ['message' => 'Worker test']);

        $run = AssistantRun::query()->where('public_id', $accepted->json('data.run_id'))->first();
        $this->assertNotNull($run);

        app(AssistantRequestService::class)->processQueuedAssistantRun($run->fresh());

        $run->refresh();
        $this->assertSame(AssistantRunStatus::Completed, $run->status);
        $this->assertStringContainsString('Queued answer', (string) ($run->result_payload['data']['message'] ?? ''));

        $poll = $this->getJson('/api/assistant/runs/'.$run->public_id);
        $poll->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.answer', 'Queued answer.');
    }

    public function test_completed_run_is_not_processed_twice(): void
    {
        Config::set('ai.provider', 'ollama');
        Config::set('ai.ollama.base_url', 'http://ollama.test');

        Http::fake([
            'http://ollama.test/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'Once.'],
            ], 200),
        ]);

        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $userMessage = $conversationService->appendUserMessage($user, $conv, 'done');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $userMessage->id,
            'status' => AssistantRunStatus::Completed,
            'original_message' => 'done',
            'request_hash' => hash('sha256', 'done'),
            'result_payload' => ['success' => true, 'httpStatus' => 200, 'errorCode' => 'ok', 'message' => '', 'data' => []],
        ]);

        app(AssistantRequestService::class)->processQueuedAssistantRun($run);

        Http::assertNothingSent();
    }
}
