<?php

namespace Tests\Feature\Operations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class QueueWorkerScriptTest extends TestCase
{
    public function test_queue_worker_ps1_references_project_php_and_redis_config(): void
    {
        $path = base_path('scripts/queue-worker.ps1');
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('tools\php', $contents);
        $this->assertStringContainsString('queue:work', $contents);
        $this->assertStringContainsString('ai.assistant.async.queue_connection', $contents);
        $this->assertStringContainsString('ai.assistant.async.queue', $contents);
        $this->assertStringContainsString('ai.assistant.async.job_timeout_seconds', $contents);
        $this->assertStringContainsString('--tries=1', $contents);
        $this->assertStringContainsString('max_execution_time=0', $contents);
    }

    public function test_queue_worker_dry_run_reports_redis_connection_and_queue(): void
    {
        $phpRoot = base_path('tools/php/php.exe');
        if (! is_file($phpRoot)) {
            $this->markTestSkipped('Project-local PHP is not installed.');
        }

        $process = new Process([
            'powershell',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            base_path('scripts/queue-worker.ps1'),
            '-DryRun',
        ], base_path(), null, null, 60);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'DryRun failed: '.$process->getErrorOutput().$process->getOutput(),
        );

        $output = $process->getOutput();
        $connection = (string) config('ai.assistant.async.queue_connection', 'redis');
        $queue = (string) config('ai.assistant.async.queue', 'default');
        $timeout = (int) config('ai.assistant.async.job_timeout_seconds', 900);

        $this->assertStringContainsString($connection, $output);
        $this->assertStringContainsString($queue, $output);
        $this->assertStringContainsString((string) $timeout, $output);
        $this->assertStringContainsString('queue:work', $output);
        $this->assertStringContainsString((string) realpath(base_path('artisan')), $output);
    }

    public function test_queue_worker_dry_run_works_from_outside_project_directory(): void
    {
        $phpRoot = base_path('tools/php/php.exe');
        if (! is_file($phpRoot)) {
            $this->markTestSkipped('Project-local PHP is not installed.');
        }

        $outside = sys_get_temp_dir();
        $process = new Process([
            'powershell',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            base_path('scripts/queue-worker.ps1'),
            '-DryRun',
        ], $outside, null, null, 60);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'DryRun from outside cwd failed: '.$process->getErrorOutput().$process->getOutput(),
        );

        $this->assertStringContainsString('queue:work', $process->getOutput());
        $this->assertStringContainsString((string) config('ai.assistant.async.queue', 'default'), $process->getOutput());
    }
}
