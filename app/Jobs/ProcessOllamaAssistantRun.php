<?php

namespace App\Jobs;

use App\Models\AssistantRun;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\AssistantRunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessOllamaAssistantRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $assistantRunId) {}

    public function timeout(): int
    {
        return max(60, (int) config('ai.assistant.async.job_timeout_seconds', 900));
    }

    public function handle(AssistantRequestService $assistantRequestService): void
    {
        // Windows has no pcntl; set_time_limit is how a single job is bounded. It applies to the
        // whole PHP process (the long-lived worker), so we must restore unlimited after each job
        // or the worker dies in Worker::sleep() when the limit elapses between jobs.
        if (function_exists('set_time_limit')) {
            set_time_limit($this->timeout());
        }

        try {
            $run = AssistantRun::query()->find($this->assistantRunId);
            if ($run === null) {
                return;
            }

            $assistantRequestService->processQueuedAssistantRun($run);
        } finally {
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }
        }
    }

    /**
     * Invoked when Laravel marks the job failed (exception, timeout, etc.).
     * Abrupt process kill (SIGKILL) may occur before this runs.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $run = AssistantRun::query()->find($this->assistantRunId);
            if ($run === null) {
                return;
            }

            app(AssistantRunCoordinator::class)->markFailedIfActive(
                $run,
                'queue_job_failed',
                'The assistant request could not be completed. Try again.',
            );
        } catch (Throwable) {
            // Prevent failure-handler loops from bubbling to the queue layer.
        }
    }
}
