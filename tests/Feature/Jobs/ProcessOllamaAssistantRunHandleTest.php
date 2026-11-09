<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessOllamaAssistantRun;
use App\Services\AI\AssistantRequestService;
use Tests\TestCase;

class ProcessOllamaAssistantRunHandleTest extends TestCase
{
    public function test_handle_restores_unlimited_php_time_limit_after_job(): void
    {
        if (! function_exists('set_time_limit')) {
            $this->markTestSkipped('set_time_limit is not available.');
        }

        set_time_limit(120);

        $job = new ProcessOllamaAssistantRun(9_999_999_999);
        $job->handle(app(AssistantRequestService::class));

        $this->assertSame('0', (string) ini_get('max_execution_time'));
    }
}
