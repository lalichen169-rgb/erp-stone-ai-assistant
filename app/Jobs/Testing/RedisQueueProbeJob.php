<?php

namespace App\Jobs\Testing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * Harmless job for integration tests proving Redis queue + separate worker consumption.
 */
final class RedisQueueProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE_NAME = 'integration-probe';

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Redis::connection()->setex(
            'redis_queue_probe:'.$this->token,
            120,
            json_encode([
                'pid' => getmypid(),
                'sapi' => PHP_SAPI,
                'at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        );
    }
}
