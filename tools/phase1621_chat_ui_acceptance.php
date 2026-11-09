<?php

/**
 * Phase 16.21 — Chat/procurement UI acceptance via HTTP (mirrors browser API calls).
 * Does not call live AI when deterministic paths apply. No secrets in output JSON.
 *
 * Prerequisites: demo users seeded, ERP demo data, DEMO_USER_PASSWORD, session file optional.
 * Run: scripts\dev.cmd php tools/phase1621_browser_bootstrap.php
 *      scripts\dev.cmd php tools/phase1621_chat_ui_acceptance.php
 */

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$sessionPath = storage_path('logs/phase1621-browser-session.json');
if (! is_readable($sessionPath)) {
    fwrite(STDERR, "Run phase1621_browser_bootstrap.php first.\n");
    exit(2);
}

/** @var array<string, mixed> $sessionData */
$sessionData = json_decode((string) file_get_contents($sessionPath), true, 512, JSON_THROW_ON_ERROR);
$pTok = (string) data_get($sessionData, 'sessions.purchasing.token');
$sTok = (string) data_get($sessionData, 'sessions.sales.token');
if ($pTok === '' || $sTok === '') {
    fwrite(STDERR, "Missing tokens in session file.\n");
    exit(2);
}

$dispatch = static function (string $method, string $uri, array $json = [], array $headers = []) use ($kernel): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    foreach ($headers as $name => $value) {
        $lower = strtolower($name);
        if ($lower === 'authorization') {
            $server['HTTP_AUTHORIZATION'] = $value;
        } elseif ($lower === 'idempotency-key') {
            $server['HTTP_IDEMPOTENCY_KEY'] = $value;
        }
    }
    $body = $json === [] ? null : json_encode($json, JSON_THROW_ON_ERROR);
    $request = Request::create($uri, $method, [], [], [], $server, $body);

    return new TestResponse($kernel->handle($request), $request);
};

$results = [];
$set = static function (string $id, bool $pass, int $http, string $notes = '') use (&$results): void {
    $results[$id] = [
        'status' => $pass ? 'Passed' : 'Failed',
        'http' => $http,
        'notes' => $notes,
    ];
};

$poBefore = PurchaseOrder::query()->count();
$stockBefore = (int) Stock::query()->sum('slabs_on_hand');

// Scenario A — missing warehouse (deterministic clarification)
$poMsg = 'Create a draft PO for 10 slabs of 3cm Calacatta Quartz from Demo Supplier — Gulf Stone Imports';
$rA = $dispatch('POST', '/api/assistant', ['message' => $poMsg], ['Authorization' => 'Bearer '.$pTok]);
$bodyA = $rA->json();
$convId = (string) data_get($bodyA, 'data.conversation_id');
$clarA = (bool) data_get($bodyA, 'data.clarification_required');
$msgA = (string) data_get($bodyA, 'data.message');
$propA = data_get($bodyA, 'data.purchase_order_proposal');
$set(
    'UI-A-clarification',
    $rA->status() === 200 && $clarA && $propA === null && stripos($msgA, 'warehouse') !== false,
    $rA->status(),
    $clarA ? '' : 'expected clarification_required',
);
$set(
    'UI-A-no-po',
    PurchaseOrder::query()->count() === $poBefore,
    200,
    PurchaseOrder::query()->count() !== $poBefore ? 'PO created prematurely' : '',
);

// Scenario B — Houston reply
$rB = $dispatch('POST', '/api/assistant', [
    'conversation_id' => $convId,
    'message' => 'Houston',
], ['Authorization' => 'Bearer '.$pTok]);
$bodyB = $rB->json();
$prop = data_get($bodyB, 'data.purchase_order_proposal');
$proposalId = is_array($prop) ? (string) ($prop['proposal_id'] ?? '') : '';
$whIdB = is_array($prop) ? (int) data_get($prop, 'lines.0.warehouse_id') : 0;
$qtyB = is_array($prop) ? (int) data_get($prop, 'lines.0.quantity') : 0;
$set(
    'UI-B-proposal',
    $rB->status() === 200 && $proposalId !== '' && $qtyB === 10 && $whIdB > 0,
    $rB->status(),
    $proposalId === '' ? 'no proposal in response' : '',
);

// Scenario C — quantity + warehouse change on resume
$convC = (string) data_get(
    $dispatch('POST', '/api/assistant', ['message' => $poMsg], ['Authorization' => 'Bearer '.$pTok])->json(),
    'data.conversation_id',
);
$rC = $dispatch('POST', '/api/assistant', [
    'conversation_id' => $convC,
    'message' => 'Actually make it 15 slabs for Dallas',
], ['Authorization' => 'Bearer '.$pTok]);
$propC = data_get($rC->json(), 'data.purchase_order_proposal');
$set(
    'UI-C-qty-warehouse',
    is_array($propC)
        && (int) data_get($propC, 'lines.0.quantity') === 15
        && (int) data_get($propC, 'lines.0.warehouse_id') > 0,
    $rC->status(),
);

// Scenario E — invalid warehouse
$convE = (string) data_get(
    $dispatch('POST', '/api/assistant', ['message' => $poMsg], ['Authorization' => 'Bearer '.$pTok])->json(),
    'data.conversation_id',
);
$rE = $dispatch('POST', '/api/assistant', [
    'conversation_id' => $convE,
    'message' => 'NotARealWarehouseCity',
], ['Authorization' => 'Bearer '.$pTok]);
$msgE = (string) data_get($rE->json(), 'data.message');
$set(
    'UI-E-invalid-wh',
    $rE->status() === 200
        && data_get($rE->json(), 'data.purchase_order_proposal') === null
        && (stripos($msgE, 'not found') !== false || stripos($msgE, 'choose') !== false),
    $rE->status(),
);

// Scenario G — cancel (use proposal from B)
if ($proposalId !== '' && $convId !== '') {
    $cancel = $dispatch('DELETE', '/api/purchase-order-proposals/'.$proposalId.'?'.http_build_query(['conversation_key' => $convId]), [], [
        'Authorization' => 'Bearer '.$pTok,
    ]);
    $set('UI-G-cancel', $cancel->status() >= 200 && $cancel->status() < 300, $cancel->status());
} else {
    $set('UI-G-cancel', false, 0, 'skipped — no proposal from B');
}

// Scenario F — confirm (new proposal)
$convF = (string) data_get(
    $dispatch('POST', '/api/assistant', ['message' => $poMsg], ['Authorization' => 'Bearer '.$pTok])->json(),
    'data.conversation_id',
);
$dispatch('POST', '/api/assistant', [
    'conversation_id' => $convF,
    'message' => 'Houston',
], ['Authorization' => 'Bearer '.$pTok]);
$propF = data_get(
    $dispatch('POST', '/api/assistant', [
        'conversation_id' => $convF,
        'message' => 'Houston',
    ], ['Authorization' => 'Bearer '.$pTok])->json(),
    'data.purchase_order_proposal',
);
// Re-fetch: single Houston reply
$rF1 = $dispatch('POST', '/api/assistant', ['message' => $poMsg], ['Authorization' => 'Bearer '.$pTok]);
$convF = (string) data_get($rF1->json(), 'data.conversation_id');
$rF2 = $dispatch('POST', '/api/assistant', ['conversation_id' => $convF, 'message' => 'Houston'], ['Authorization' => 'Bearer '.$pTok]);
$propF = data_get($rF2->json(), 'data.purchase_order_proposal');
$propIdF = is_array($propF) ? (string) ($propF['proposal_id'] ?? '') : '';
$idem = 'phase1621-confirm-'.bin2hex(random_bytes(6));
$poCountBeforeConfirm = PurchaseOrder::query()->count();
$confirm = $dispatch('POST', '/api/purchase-order-proposals/'.$propIdF.'/confirm?'.http_build_query(['conversation_key' => $convF]), [], [
    'Authorization' => 'Bearer '.$pTok,
    'Idempotency-Key' => $idem,
]);
$poAfter = PurchaseOrder::query()->count();
$lineWh = 0;
if ($poAfter > $poCountBeforeConfirm) {
    $lineWh = (int) PurchaseOrderLine::query()->orderByDesc('id')->value('warehouse_id');
}
$stockAfter = (int) Stock::query()->sum('slabs_on_hand');
$set(
    'UI-F-confirm',
    $confirm->status() === 200 && $poAfter === $poCountBeforeConfirm + 1 && $lineWh > 0,
    $confirm->status(),
);
$set(
    'UI-F-stock-unchanged',
    $stockAfter === $stockBefore,
    200,
    $stockAfter !== $stockBefore ? 'stock changed on PO confirm' : '',
);

// Fresh PHP process avoids in-memory kernel pollution after long procurement batch.
$salesSmokeExit = 1;
$devPs1 = dirname(__DIR__).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'dev.ps1';
$smoke = __DIR__.DIRECTORY_SEPARATOR.'phase1621_sales_po_smoke.php';
passthru(
    'powershell -NoProfile -ExecutionPolicy Bypass -File '
    .escapeshellarg($devPs1)
    .' php '
    .escapeshellarg($smoke),
    $salesSmokeExit,
);
$set('UI-SALES-po-refusal', $salesSmokeExit === 0, $salesSmokeExit === 0 ? 200 : 500, '');

$rStock = $dispatch('POST', '/api/assistant', [
    'message' => 'How many 3cm Calacatta slabs do we have in Houston?',
], ['Authorization' => 'Bearer '.$sTok]);
$set(
    'UI-SALES-stock',
    $rStock->status() === 200 && str_contains((string) data_get($rStock->json(), 'data.message'), '42'),
    $rStock->status(),
);

$out = [
    'generated_at' => gmdate('c'),
    'ai_provider' => (string) config('ai.provider'),
    'results' => $results,
];

file_put_contents(__DIR__.'/phase1621_chat_ui_acceptance.json', json_encode($out, JSON_PRETTY_PRINT)."\n");
fwrite(STDOUT, "Wrote tools/phase1621_chat_ui_acceptance.json\n");

$failed = collect($results)->where('status', 'Failed')->count();
exit($failed > 0 ? 1 : 0);
