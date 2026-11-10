<?php

namespace Tests\Feature\Jobs;

use App\Enums\AssistantRunStatus;
use App\Jobs\ProcessOllamaAssistantRun;
use App\Models\AssistantRun;
use App\Services\AI\AssistantRunCoordinator;
use App\Services\AI\Conversations\ConversationService;
use RuntimeException;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ProcessOllamaAssistantRunFailedTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_failed_marks_processing_run_as_failed(): void
    {
        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'Fail job');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Processing,
            'original_message' => 'Fail job',
            'request_hash' => hash('sha256', 'Fail job'),
            'started_at' => now(),
        ]);

        $job = new ProcessOllamaAssistantRun($run->id);
        $job->failed(new RuntimeException('worker timeout'));

        $run->refresh();
        $this->assertSame(AssistantRunStatus::Failed, $run->status);
        $this->assertSame('queue_job_failed', $run->error_code);
        $this->assertStringNotContainsString('worker timeout', (string) $run->error_message);
    }

    public function test_failed_does_not_overwrite_completed_run(): void
    {
        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'Done');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Completed,
            'original_message' => 'Done',
            'request_hash' => hash('sha256', 'Done'),
            'result_payload' => ['success' => true, 'httpStatus' => 200, 'errorCode' => 'ok', 'message' => '', 'data' => ['message' => 'Answer']],
            'completed_at' => now(),
        ]);

        $job = new ProcessOllamaAssistantRun($run->id);
        $job->failed(new RuntimeException('late failure'));

        $run->refresh();
        $this->assertSame(AssistantRunStatus::Completed, $run->status);
    }

    public function test_failed_is_idempotent_on_already_failed_run(): void
    {
        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'Failed');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Failed,
            'original_message' => 'Failed',
            'request_hash' => hash('sha256', 'Failed'),
            'error_code' => 'assistant_execution_failed',
            'error_message' => 'First failure.',
            'completed_at' => now(),
        ]);

        $job = new ProcessOllamaAssistantRun($run->id);
        $job->failed(new RuntimeException('again'));
        $job->failed(new RuntimeException('again'));

        $run->refresh();
        $this->assertSame(AssistantRunStatus::Failed, $run->status);
        $this->assertSame('assistant_execution_failed', $run->error_code);
        $this->assertSame('First failure.', $run->error_message);
    }

    public function test_mark_failed_if_active_does_not_reset_completed_to_processing(): void
    {
        $user = $this->createSalesApiUser();
        $conversationService = app(ConversationService::class);
        $conv = $conversationService->createForUser($user);
        $msg = $conversationService->appendUserMessage($user, $conv, 'x');

        $run = AssistantRun::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conv->id,
            'conversation_message_id' => $msg->id,
            'status' => AssistantRunStatus::Completed,
            'original_message' => 'x',
            'request_hash' => hash('sha256', 'x'),
            'completed_at' => now(),
        ]);

        app(AssistantRunCoordinator::class)->markFailedIfActive($run, 'queue_job_failed', 'Should not apply');

        $this->assertSame(AssistantRunStatus::Completed, $run->fresh()->status);
    }
}
