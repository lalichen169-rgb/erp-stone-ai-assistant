<?php

/**
 * Phase 16.21 — Issue Sanctum tokens for browser UI testing (no stdout secrets).
 * Writes role-scoped session blobs to storage/logs/phase1621-browser-session.json (delete after use).
 *
 * Run: scripts\dev.cmd php tools/phase1621_browser_bootstrap.php
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$limiter = $app->make(\Illuminate\Cache\RateLimiter::class);
$throttleIp = '127.0.0.1';
foreach (['sales@example.test', 'purchasing@example.test'] as $email) {
    $limiter->clear(md5('api-login'.$email.'|'.$throttleIp));
}

$password = config('erp.demo_user_password');
if (! is_string($password) || $password === '') {
    fwrite(STDERR, "Blocked: DEMO_USER_PASSWORD not set.\n");
    exit(2);
}

$dispatch = static function (string $method, string $uri, array $json = []) use ($kernel): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    $body = $json === [] ? null : json_encode($json, JSON_THROW_ON_ERROR);
    $request = Request::create($uri, $method, [], [], [], $server, $body);
    $response = $kernel->handle($request);

    return new TestResponse($response, $request);
};

$sessions = [];

foreach (['sales@example.test' => 'sales', 'purchasing@example.test' => 'purchasing'] as $email => $roleKey) {
    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        fwrite(STDERR, "Missing user: {$email}\n");
        exit(2);
    }

    $login = $dispatch('POST', '/api/auth/token', [
        'email' => $email,
        'password' => $password,
        'device_name' => 'phase1621-browser',
    ]);

    if ($login->status() !== 200) {
        fwrite(STDERR, "Login failed for {$email}: {$login->status()}\n");
        exit(2);
    }

    $token = (string) $login->json('token');
    $sessions[$roleKey] = [
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value ?? (string) $user->role,
        ],
    ];
}

$path = storage_path('logs/phase1621-browser-session.json');
file_put_contents($path, json_encode([
    'generated_at' => gmdate('c'),
    'sessions' => $sessions,
], JSON_PRETTY_PRINT));

fwrite(STDOUT, "Wrote {$path}\n");
exit(0);
