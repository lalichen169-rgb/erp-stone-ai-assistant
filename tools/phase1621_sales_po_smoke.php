<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sessionPath = storage_path('logs/phase1621-browser-session.json');
if (! is_readable($sessionPath)) {
    fwrite(STDERR, "Run phase1621_browser_bootstrap.php first.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sessionPath), true, 512, JSON_THROW_ON_ERROR);
$token = (string) data_get($data, 'sessions.sales.token');
if ($token === '') {
    fwrite(STDERR, "Missing sales token.\n");
    exit(2);
}

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create(
    '/api/assistant',
    'POST',
    ['message' => 'Create a draft PO for 5 slabs of Calacatta from Demo Supplier — Gulf Stone Imports'],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'REMOTE_ADDR' => '127.0.0.1',
    ],
    json_encode(['message' => 'Create a draft PO for 5 slabs of Calacatta from Demo Supplier — Gulf Stone Imports'], JSON_THROW_ON_ERROR),
);

$response = new Illuminate\Testing\TestResponse($kernel->handle($request), $request);
$json = $response->json();
$msg = (string) data_get($json, 'data.message', data_get($json, 'message', ''));

echo json_encode([
    'http' => $response->status(),
    'message_preview' => substr($msg, 0, 120),
    'has_purchasing_refusal' => stripos($msg, 'Purchasing role') !== false,
], JSON_PRETTY_PRINT).PHP_EOL;

exit($response->status() === 200 && stripos($msg, 'Purchasing role') !== false ? 0 : 1);
