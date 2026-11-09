<?php

namespace Tests\Feature\Api;

use App\Enums\AssistantRunStatus;
use App\Jobs\ProcessOllamaAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Services\AI\AssistantRunCoordinator;
use App\Services\AI\Conversations\ConversationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class AssistantRunRecoveryTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_idempotent_replay_redispatches_when_queue_lease_elapsed(): void
    {
        Queue::fake();
        Config::set('ai.provider', 'ollama');
        Config::set('ai.assistant.async.queue_dispatch_lease_seconds', 60);
        Config::set('ai.assistant.async.queued_run_grace_seconds', 5);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(ConversationService::class)->createForUser($user);

        $headers = ['Idempotency-Key' => 'redispatch-idem'];
        $body = [
            'conversation_id' => $conv->public_id,
            'message' => 'Recover this run',
        ];

        $this->withHeaders($headers)->postJson('/api/assistant', $body)->assertStatus(202);
        Queue::assertPushed(ProcessOllamaAssistantRun::class, 1);

        $this->travel(3)->minutes();

        $this->withHeaders($headers)->postJson('/api/assistant', $body)->assertStatus(202);

        Queue::assertPushed(ProcessOllamaAssistantRun::class, 2);
        $this->assertSame(1, AssistantRun::query()->count());
    }

    public function test_reconcile_does_not_redispatch_within_lease_window(): void
    {
        Queue::fake();
        Config::set('ai.assistant.async.queue_dispatch_lease_seconds', 300);
        Config::set('ai.assistant.async.queued_run_grace_seconds', 5);

        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'Lease test');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Queued,
            'original_message' => 'Lease test',
            'request_hash' => hash('sha256', 'Lease test'),
            'last_queue_dispatch_at' => now()->subSeconds(30),
            'queue_dispatch_attempts' => 1,
            'created_at' => now()->subMinutes(2),
        ]);

        app(AssistantRunCoordinator::class)->reconcileOrphanedQueuedRun($run);

        Queue::assertNothingPushed();
    }

    public function test_coordinator_marks_queue_dispatch_failed_via_mark_failed_if_active(): void
    {
        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'Dispatch fail');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Queued,
            'original_message' => 'Dispatch fail',
            'request_hash' => hash('sha256', 'Dispatch fail'),
        ]);

        app(AssistantRunCoordinator::class)->markFailedIfActive(
            $run,
            'queue_dispatch_failed',
            'The assistant request could not be queued. Try again.',
        );

        $run->refresh();
        $this->assertSame(AssistantRunStatus::Failed, $run->status);
        $this->assertSame('queue_dispatch_failed', $run->error_code);
    }

    public function test_exhausted_dispatch_attempts_mark_run_failed_after_abandon_window(): void
    {
        Queue::fake();
        Config::set('ai.assistant.async.max_queue_dispatch_attempts', 2);
        Config::set('ai.assistant.async.queued_run_abandon_seconds', 60);

        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'Abandon');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Queued,
            'original_message' => 'Abandon',
            'request_hash' => hash('sha256', 'Abandon'),
        ]);
        $run->update([
            'queue_dispatch_attempts' => 2,
            'created_at' => now()->subMinutes(5),
        ]);

        app(AssistantRunCoordinator::class)->reconcileOrphanedQueuedRun($run->fresh());

        $run->refresh();
        $this->assertSame(AssistantRunStatus::Failed, $run->status);
        $this->assertSame('queue_recovery_exhausted', $run->error_code);
        Queue::assertNothingPushed();
    }

    public function test_status_poll_triggers_bounded_redispatch(): void
    {
        Queue::fake();
        Config::set('ai.provider', 'ollama');
        Config::set('ai.assistant.async.queue_dispatch_lease_seconds', 60);
        Config::set('ai.assistant.async.queued_run_grace_seconds', 5);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $accepted = $this->postJson('/api/assistant', ['message' => 'Poll redispatch']);
        $runId = $accepted->json('data.run_id');
        Queue::assertPushed(ProcessOllamaAssistantRun::class, 1);

        $this->travel(3)->minutes();

        $this->getJson('/api/assistant/runs/'.$runId)->assertOk();

        Queue::assertPushed(ProcessOllamaAssistantRun::class, 2);
    }
}
