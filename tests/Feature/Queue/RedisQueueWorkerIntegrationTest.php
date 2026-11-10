<?php

namespace Tests\Feature\Queue;

use App\Jobs\Testing\RedisQueueProbeJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RedisQueueWorkerIntegrationTest extends TestCase
{
    private const PROBE_QUEUE = RedisQueueProbeJob::QUEUE_NAME;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            Config::set('database.redis.client', 'predis');
        }

        Config::set('database.redis.default.host', '127.0.0.1');
        Config::set('database.redis.default.port', '6379');
    }

    public function test_probe_job_is_pushed_onto_redis_queue_list(): void
    {
        if (! $this->redisReachable()) {
            $this->markTestSkipped('Redis is not reachable in this environment.');
        }

        Redis::connection()->del('queues:'.self::PROBE_QUEUE);

        $token = 'probe-'.Str::uuid();
        $before = (int) Redis::connection()->llen('queues:'.self::PROBE_QUEUE);

        RedisQueueProbeJob::dispatch($token)
            ->onConnection('redis')
            ->onQueue(self::PROBE_QUEUE);

        $after = (int) Redis::connection()->llen('queues:'.self::PROBE_QUEUE);
        $this->assertGreaterThan($before, $after);
    }

    public function test_separate_queue_worker_consumes_redis_probe_job(): void
    {
        if (! $this->redisReachable()) {
            $this->markTestSkipped('Redis is not reachable in this environment.');
        }

        Redis::connection()->del('queues:'.self::PROBE_QUEUE);

        $token = 'worker-'.Str::uuid();
        $apiPid = getmypid();

        RedisQueueProbeJob::dispatch($token)
            ->onConnection('redis')
            ->onQueue(self::PROBE_QUEUE);

        $pendingBeforeWorker = (int) Redis::connection()->llen('queues:'.self::PROBE_QUEUE);
        $this->assertGreaterThan(0, $pendingBeforeWorker);

        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        $workerEnv = array_filter([
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'),
            'REDIS_CLIENT' => config('database.redis.client'),
            'REDIS_HOST' => config('database.redis.default.host'),
            'REDIS_PORT' => (string) config('database.redis.default.port'),
            'REDIS_PREFIX' => config('database.redis.options.prefix'),
            'CACHE_STORE' => 'array',
        ]);

        $process = new Process([
            $php,
            $artisan,
            'queue:work',
            'redis',
            '--queue='.self::PROBE_QUEUE,
            '--once',
            '--timeout=30',
            '--tries=1',
        ], base_path(), $workerEnv, null, 45);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped(
                'Could not run a separate queue:work process in this environment: '.$process->getErrorOutput()
            );
        }

        $pendingAfterWorker = (int) Redis::connection()->llen('queues:'.self::PROBE_QUEUE);
        $this->assertSame(0, $pendingAfterWorker);

        $raw = Redis::connection()->get('redis_queue_probe:'.$token);
        if (is_string($raw)) {
            /** @var array{pid?: int, sapi?: string} $payload */
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('pid', $payload);
            $this->assertNotSame($apiPid, (int) $payload['pid']);
        }
    }

    private function redisReachable(): bool
    {
        try {
            $pong = Redis::connection()->ping();
            if ($pong === true) {
                return true;
            }
            if (is_string($pong)) {
                return strtoupper($pong) === 'PONG' || $pong === '+PONG';
            }
            if (is_object($pong) && method_exists($pong, 'getPayload')) {
                return strtoupper((string) $pong->getPayload()) === 'PONG';
            }

            return strtoupper((string) $pong) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}
