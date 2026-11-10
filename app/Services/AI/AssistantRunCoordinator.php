<?php

namespace App\Services\AI;

use App\Enums\AssistantRunStatus;
use App\Jobs\ProcessOllamaAssistantRun;
use App\Models\AssistantRun;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssistantRunCoordinator
{
    public function requestHash(string $message): string
    {
        return hash('sha256', $message);
    }

    public function findForUserMessage(ConversationMessage $userMessage): ?AssistantRun
    {
        return AssistantRun::query()
            ->where('conversation_message_id', $userMessage->id)
            ->first();
    }

    /**
     * Create or reuse a queued run and dispatch the worker job exactly once for new rows.
     */
    public function enqueueAfterUserMessage(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $originalMessage,
        ?string $clientIdempotencyKey,
    ): AssistantRun {
        /** @var array{run: AssistantRun, dispatch: bool} $out */
        $out = DB::transaction(function () use ($user, $conversation, $userMessage, $originalMessage, $clientIdempotencyKey): array {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            if ($clientIdempotencyKey !== null && trim($clientIdempotencyKey) !== '') {
                $byKey = AssistantRun::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('client_idempotency_key', $clientIdempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($byKey !== null) {
                    return ['run' => $byKey, 'dispatch' => false];
                }
            }

            $existing = AssistantRun::query()
                ->where('conversation_message_id', $userMessage->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return ['run' => $existing, 'dispatch' => false];
            }

            $run = AssistantRun::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'conversation_message_id' => $userMessage->id,
                'status' => AssistantRunStatus::Queued,
                'original_message' => $originalMessage,
                'client_idempotency_key' => $clientIdempotencyKey,
                'request_hash' => $this->requestHash($originalMessage),
            ]);

            return ['run' => $run, 'dispatch' => true];
        });

        if ($out['dispatch'] && $out['run']->status === AssistantRunStatus::Queued) {
            $this->dispatchRunToRedisQueue($out['run']);
        } elseif (! $out['dispatch'] && $out['run']->status === AssistantRunStatus::Queued) {
            $this->reconcileOrphanedQueuedRun($out['run']->fresh());
        }

        return $out['run']->fresh();
    }

    /**
     * Bounded recovery for queued runs whose Redis payload may be missing.
     * Does not inspect Redis payloads; uses DB lease and attempt limits only.
     */
    public function reconcileOrphanedQueuedRun(AssistantRun $run): void
    {
        $run = $run->fresh();
        if ($run === null || $run->status !== AssistantRunStatus::Queued) {
            return;
        }

        $maxAttempts = max(1, (int) config('ai.assistant.async.max_queue_dispatch_attempts', 5));

        if ($run->queue_dispatch_attempts >= $maxAttempts) {
            $this->markFailedIfActive(
                $run,
                'queue_recovery_exhausted',
                'The assistant request could not be queued. Try again.',
            );

            return;
        }

        $graceSeconds = max(5, (int) config('ai.assistant.async.queued_run_grace_seconds', 15));
        $created = $run->created_at;
        if ($created !== null && $created->diffInSeconds(now()) < $graceSeconds) {
            return;
        }

        $leaseSeconds = max(30, (int) config('ai.assistant.async.queue_dispatch_lease_seconds', 120));
        $lastDispatch = $run->last_queue_dispatch_at;
        if ($lastDispatch !== null && $lastDispatch->diffInSeconds(now()) < $leaseSeconds) {
            return;
        }

        $this->dispatchRunToRedisQueue($run, redispatch: true);
    }

    private function dispatchRunToRedisQueue(AssistantRun $run, bool $redispatch = false): void
    {
        $dedupeKey = 'assistant:run:dispatching:'.$run->id;
        if (! Cache::add($dedupeKey, 1, 30)) {
            return;
        }

        $connection = (string) config('ai.assistant.async.queue_connection', 'redis');
        $queue = (string) config('ai.assistant.async.queue', 'default');

        try {
            $shouldDispatch = DB::transaction(function () use ($run, $redispatch): bool {
                /** @var AssistantRun|null $locked */
                $locked = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if ($locked === null || $locked->isTerminal() || $locked->status !== AssistantRunStatus::Queued) {
                    return false;
                }

                $maxAttempts = max(1, (int) config('ai.assistant.async.max_queue_dispatch_attempts', 5));
                if ($locked->queue_dispatch_attempts >= $maxAttempts) {
                    return false;
                }

                if ($redispatch) {
                    $leaseSeconds = max(30, (int) config('ai.assistant.async.queue_dispatch_lease_seconds', 120));
                    $lastDispatch = $locked->last_queue_dispatch_at;
                    if ($lastDispatch !== null && $lastDispatch->diffInSeconds(now()) < $leaseSeconds) {
                        return false;
                    }
                }

                return true;
            });

            if (! $shouldDispatch) {
                return;
            }

            try {
                $pending = ProcessOllamaAssistantRun::dispatch($run->id)
                    ->onConnection($connection)
                    ->onQueue($queue);

                if (DB::transactionLevel() > 0) {
                    $pending->afterCommit();
                }
            } catch (Throwable) {
                $this->markFailedIfActive(
                    $run->fresh(),
                    'queue_dispatch_failed',
                    'The assistant request could not be queued. Try again.',
                );

                return;
            }

            try {
                DB::transaction(function () use ($run): void {
                    /** @var AssistantRun|null $locked */
                    $locked = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== AssistantRunStatus::Queued) {
                        return;
                    }

                    $locked->forceFill([
                        'last_queue_dispatch_at' => now(),
                        'queue_dispatch_attempts' => $locked->queue_dispatch_attempts + 1,
                    ])->save();
                });
            } catch (Throwable) {
                // Dispatch succeeded; bookkeeping failure must not mark the run failed (e.g. pending migration).
            }
        } finally {
            Cache::forget($dedupeKey);
        }
    }

    public function acceptanceResult(AssistantRun $run, Conversation $conversation): AssistantRequestResult
    {
        $this->reconcileOrphanedQueuedRun($run);
        $this->reconcileStaleProcessing($run);
        $run->refresh();

        if ($run->status === AssistantRunStatus::Completed && is_array($run->result_payload)) {
            return AssistantRequestResult::fromStorageArray($run->result_payload);
        }

        if ($run->status === AssistantRunStatus::Failed) {
            return new AssistantRequestResult(
                success: false,
                httpStatus: 202,
                errorCode: $run->error_code ?? 'assistant_execution_failed',
                message: $run->error_message ?? 'The assistant could not complete this request.',
                data: $this->asyncDataPayload($run, $conversation),
            );
        }

        return new AssistantRequestResult(
            success: true,
            httpStatus: 202,
            errorCode: 'accepted',
            message: '',
            data: $this->asyncDataPayload($run, $conversation),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(AssistantRun $run): array
    {
        $this->reconcileOrphanedQueuedRun($run);
        $this->reconcileStaleProcessing($run);
        $run->refresh();
        $conversation = $run->conversation;

        $payload = [
            'run_id' => $run->public_id,
            'conversation_id' => $conversation?->public_id,
            'status' => $run->status->value,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];

        if ($run->status === AssistantRunStatus::Completed && is_array($run->result_payload)) {
            $stored = AssistantRequestResult::fromStorageArray($run->result_payload);
            $payload['answer'] = $stored->data['message'] ?? '';
            $payload['data'] = $stored->data;
        }

        if ($run->status === AssistantRunStatus::Failed) {
            $payload['error'] = [
                'code' => $run->error_code ?? 'assistant_execution_failed',
                'message' => $run->error_message ?? 'The assistant could not complete this request.',
            ];
        }

        return $payload;
    }

    public function persistTerminalResult(AssistantRun $run, AssistantRequestResult $result): void
    {
        DB::transaction(function () use ($run, $result): void {
            $locked = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'status' => $result->success ? AssistantRunStatus::Completed : AssistantRunStatus::Failed,
                'result_payload' => $result->toStorageArray(),
                'error_code' => $result->success ? null : $result->errorCode,
                'error_message' => $result->success ? null : $result->message,
                'completed_at' => now(),
            ])->save();
        });
    }

    public function markFailed(AssistantRun $run, string $code, string $message): void
    {
        $this->markFailedIfActive($run, $code, $message);
    }

    /**
     * Mark failed only when the run is still queued or processing (idempotent).
     */
    public function markFailedIfActive(AssistantRun $run, string $code, string $message): void
    {
        $result = new AssistantRequestResult(
            success: false,
            httpStatus: 503,
            errorCode: $code,
            message: $message,
            data: [
                'conversation_id' => $run->conversation?->public_id,
                'message' => '',
                'completed' => false,
                'error_code' => $code,
            ],
        );

        $this->persistTerminalResult($run, $result);
    }

    public function reconcileStaleProcessing(AssistantRun $run): void
    {
        if ($run->status !== AssistantRunStatus::Processing) {
            return;
        }

        $staleSeconds = max(60, (int) config('ai.assistant.async.stale_processing_seconds', 1080));
        $started = $run->started_at;
        if ($started === null || $started->diffInSeconds(now()) < $staleSeconds) {
            return;
        }

        $this->markFailedIfActive($run, 'ai_timeout', 'The assistant request timed out while processing. Try again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function asyncDataPayload(AssistantRun $run, Conversation $conversation): array
    {
        return [
            'async' => true,
            'run_id' => $run->public_id,
            'status' => $run->status->value,
            'conversation_id' => $conversation->public_id,
            'status_url' => '/api/assistant/runs/'.$run->public_id,
        ];
    }
}
