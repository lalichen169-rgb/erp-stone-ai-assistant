<?php

namespace Tests\Feature\Operations;

use Tests\TestCase;

class DockerComposeQueueWorkerTest extends TestCase
{
    public function test_queue_service_uses_redis_and_environment_driven_timeout(): void
    {
        $yaml = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('queue:', $yaml);
        $this->assertStringContainsString('queue:work', $yaml);
        $this->assertStringContainsString('AI_ASSISTANT_QUEUE_CONNECTION', $yaml);
        $this->assertStringContainsString('AI_ASSISTANT_QUEUE', $yaml);
        $this->assertStringContainsString('AI_ASSISTANT_JOB_TIMEOUT', $yaml);
        $this->assertStringContainsString('--tries=1', $yaml);
        $this->assertStringContainsString('--max-time=3600', $yaml);
        $this->assertStringContainsString('max_execution_time=0', $yaml);
        $this->assertStringContainsString('docker/compose.env', $yaml);
    }

    public function test_redis_retry_after_exceeds_assistant_job_timeout_default(): void
    {
        $jobTimeout = (int) config('ai.assistant.async.job_timeout_seconds', 900);
        $retryAfter = (int) config('queue.connections.redis.retry_after', 960);

        $this->assertGreaterThan($jobTimeout, $retryAfter);
    }

    public function test_stale_processing_exceeds_job_timeout_default(): void
    {
        $jobTimeout = (int) config('ai.assistant.async.job_timeout_seconds', 900);
        $stale = (int) config('ai.assistant.async.stale_processing_seconds', 1080);

        $this->assertGreaterThan($jobTimeout, $stale);
    }
}
