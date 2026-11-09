<?php

/**
 * Phase 16.23 — Supplier-specific delayed PO acceptance via in-process HTTP (development stone_erp_ai).
 * Uses deterministic assistant preflight paths where applicable; does not call live Ollama.
 *
 * Prerequisites: demo users + DEMO_USER_PASSWORD; run phase1621_browser_bootstrap.php first.
 *
 * Run: scripts\dev.cmd php tools/phase1623_supplier_overdue_acceptance.php
 */

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AI\Grounding\SalesSupplierOverduePurchaseOrderAnalysisRefusal;
use App\Services\Erp\OverduePurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$dbName = (string) config('database.connections.mysql.database');
if ($dbName !== 'stone_erp_ai') {
    fwrite(STDERR, "Blocked: expected development DB stone_erp_ai, got {$dbName}\n");
    exit(2);
}

$sessionPath = storage_path('logs/phase1621-browser-session.json');
if (! is_readable($sessionPath)) {
    fwrite(STDERR, "Run tools/phase1621_browser_bootstrap.php first.\n");
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

$dispatch = static function (string $method, string $uri, array $json = [], array $headers = []) use ($kernel, $app): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    foreach ($headers as $name => $value) {
        $lower = strtolower($name);
        if ($lower === 'authorization') {
            $server['HTTP_AUTHORIZATION'] = $value;
        }
    }
    $body = $json === [] ? null : json_encode($json, JSON_THROW_ON_ERROR);
    $request = Request::create($uri, $method, [], [], [], $server, $body);
    $response = $kernel->handle($request);
    if (method_exists($app['auth'], 'forgetGuards')) {
        $app['auth']->forgetGuards();
    }

    return new TestResponse($response, $request);
};

$assistant = static function (string $message, string $token, ?string $conversationId = null) use ($dispatch): TestResponse {
    $payload = ['message' => $message];
    if ($conversationId !== null && $conversationId !== '') {
        $payload['conversation_id'] = $conversationId;
    }

    return $dispatch('POST', '/api/assistant', $payload, ['Authorization' => 'Bearer '.$token]);
};

$results = [];
$notes = [];
$set = static function (string $id, bool $pass, int $http, string $detail = '') use (&$results): void {
    $results[$id] = [
        'status' => $pass ? 'Passed' : 'Failed',
        'http' => $http,
        'detail' => $detail,
    ];
};

$purchUser = User::query()->where('email', 'purchasing@example.test')->firstOrFail();
$salesUser = User::query()->where('email', 'sales@example.test')->firstOrFail();
$overdueSvc = $app->make(OverduePurchaseOrderService::class);
$today = Carbon::today();

$brazos = Supplier::query()->where('name', 'like', '%Brazos Marble LLC%')->first();
$gulf = Supplier::query()->where('name', 'like', '%Gulf Stone Imports%')->first();
$loneStar = Supplier::query()->where('name', 'like', '%Lone Star Slabs%')->first();

$expectedBrazosOverdue = $brazos
    ? $overdueSvc->listOverdue($purchUser, $today, 100, $brazos->id)
    : [];
$expectedGulfOverdue = $gulf
    ? $overdueSvc->listOverdue($purchUser, $today, 100, $gulf->id)
    : [];
$expectedLoneStarOverdue = $loneStar
    ? $overdueSvc->listOverdue($purchUser, $today, 100, $loneStar->id)
    : [];
$expectedAllOverdue = $overdueSvc->listOverdue($purchUser, $today);

$po507 = PurchaseOrder::query()->find(507);
$po511 = PurchaseOrder::query()->find(511);

$refusal = SalesSupplierOverduePurchaseOrderAnalysisRefusal::REFUSAL_MESSAGE;

// --- Purchasing matrix ---
$r = $assistant('Which purchase orders from Brazos Marble LLC are delayed?', $pTok);
$msg = (string) data_get($r->json(), 'data.message');
$p1 = $r->status() === 200
    && count($expectedBrazosOverdue) > 0
    ? (str_contains($msg, 'Overdue') && str_contains($msg, 'Brazos'))
    : (str_contains($msg, 'No overdue') || str_contains($msg, 'overdue'));
foreach ($expectedBrazosOverdue as $row) {
    $p1 = $p1 && str_contains($msg, 'PO #'.(int) $row['purchase_order_id']);
}
$set('PURCH-01-brazos-delayed', $p1, $r->status(), mb_substr($msg, 0, 240));

$r = $assistant('Show me all overdue POs from Gulf Stone Imports.', $pTok);
$msg = (string) data_get($r->json(), 'data.message');
$p2 = $r->status() === 200 && str_contains($msg, 'Gulf Stone');
foreach ($expectedGulfOverdue as $row) {
    $p2 = $p2 && str_contains($msg, 'PO #'.(int) $row['purchase_order_id']);
}
$set('PURCH-02-gulf-overdue', $p2, $r->status(), mb_substr($msg, 0, 240));

$emptySupplier = collect([$brazos, $gulf, $loneStar])
    ->filter()
    ->first(fn (Supplier $s) => count($overdueSvc->listOverdue($purchUser, $today, 100, $s->id)) === 0);
$emptyName = $emptySupplier?->name ?? 'Hill Country Quartz Co';
$r = $assistant('Are there any delayed purchase orders from '.$emptyName.'?', $pTok);
$msg = (string) data_get($r->json(), 'data.message');
$p3 = $r->status() === 200 && (str_contains($msg, 'No overdue') || str_contains($msg, 'no overdue'));
$set('PURCH-03-supplier-empty-overdue', $p3, $r->status(), 'supplier='.$emptyName.'; '.mb_substr($msg, 0, 160));

$r = $assistant('What is the status of PO-507?', $pTok);
$msg = (string) data_get($r->json(), 'data.message');
$p4 = $r->status() === 200 && str_contains($msg, '507');
if ($po507 !== null) {
    $p4 = $p4 && stripos($msg, $po507->status->value) !== false;
}
$set('PURCH-04-po-507-status', $p4, $r->status(), mb_substr($msg, 0, 200));

$r = $assistant('Show all purchase orders.', $pTok);
$msg = (string) data_get($r->json(), 'data.message');
$p5 = $r->status() === 200 && (stripos($msg, 'purchase order') !== false || stripos($msg, 'PO') !== false);
$set('PURCH-05-list-all', $p5, $r->status(), mb_substr($msg, 0, 160));

$r = $assistant('Show all overdue purchase orders.', $pTok);
$msg = (string) data_get($r->json(), 'data.message');
$p6 = $r->status() === 200 && str_contains($msg, 'Overdue');
foreach (array_slice($expectedAllOverdue, 0, 3) as $row) {
    $p6 = $p6 && str_contains($msg, 'PO #'.(int) $row['purchase_order_id']);
}
$set('PURCH-06-general-overdue', $p6, $r->status(), 'count_expected='.count($expectedAllOverdue));

// --- Sales matrix ---
$r = $assistant('Which purchase orders from Brazos Marble LLC are delayed?', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s1 = $r->status() === 200 && $msg === $refusal;
$set('SALES-01-brazos-delayed-denied', $s1, $r->status());

$r = $assistant('Show overdue POs from Gulf Stone Imports.', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s2 = $r->status() === 200 && $msg === $refusal;
$set('SALES-02-gulf-overdue-denied', $s2, $r->status());

$r = $assistant('How many delayed POs does Brazos Marble LLC have?', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s3 = $r->status() === 200 && $msg === $refusal;
$set('SALES-03-count-denied', $s3, $r->status());

$r = $assistant('What is the status of PO-507?', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s4 = $r->status() === 200 && str_contains($msg, '507') && $msg !== $refusal;
$set('SALES-04-po-507-status', $s4, $r->status(), mb_substr($msg, 0, 160));

$r = $assistant('Is PO-511 received?', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s5 = $r->status() === 200 && str_contains($msg, '511');
if ($po511 !== null) {
    $s5 = $s5 && (stripos($msg, 'received') !== false || stripos($msg, $po511->status->value) !== false);
}
$set('SALES-05-po-511-received', $s5, $r->status(), mb_substr($msg, 0, 160));

$r = $assistant('Show me all purchase orders.', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s6 = $r->status() === 200 && $msg !== $refusal && (stripos($msg, 'PO') !== false || stripos($msg, 'purchase') !== false);
$set('SALES-06-list-all', $s6, $r->status());

$r = $assistant('Show me all overdue purchase orders.', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s7 = $r->status() === 200 && $msg !== $refusal;
$set(
    'SALES-07-general-overdue',
    $s7,
    $r->status(),
    str_contains($msg, 'Overdue')
        ? 'deterministic'
        : 'non_deterministic_live_path='.mb_substr($msg, 0, 120),
);

$r = $assistant('What is the status of the purchase order from Brazos Marble LLC?', $sTok);
$msg = (string) data_get($r->json(), 'data.message');
$s8 = $r->status() === 200 && $msg !== $refusal && (stripos($msg, 'Brazos') !== false || stripos($msg, 'PO') !== false);
$set('SALES-08-supplier-po-status-allowed', $s8, $r->status(), mb_substr($msg, 0, 200));

// --- Conversation / filter isolation (Purchasing) ---
$rA = $assistant('Which purchase orders from Brazos Marble LLC are delayed?', $pTok);
$conv = (string) data_get($rA->json(), 'data.conversation_id');
$rB = $assistant('What about Gulf Stone Imports?', $pTok, $conv);
$msgB = (string) data_get($rB->json(), 'data.message');
$iso1 = $rB->status() === 200
    && str_contains($msgB, 'Gulf Stone');
if (count($expectedGulfOverdue) > 0) {
    foreach ($expectedGulfOverdue as $row) {
        $iso1 = $iso1 && str_contains($msgB, 'PO #'.(int) $row['purchase_order_id']);
    }
}
$set('CTX-01-followup-gulf', $iso1, $rB->status(), mb_substr($msgB, 0, 220));

$rC = $assistant('What is the status of PO-507?', $pTok, $conv);
$msgC = (string) data_get($rC->json(), 'data.message');
$iso2 = $rC->status() === 200
    && str_contains($msgC, '507')
    && stripos($msgC, 'status') !== false
    && stripos($msgC, 'No supplier in the ERP matched') === false;
$set('CTX-02-followup-po507', $iso2, $rC->status(), mb_substr($msgC, 0, 160));

$rD = $assistant('Show all purchase orders.', $pTok, $conv);
$msgD = (string) data_get($rD->json(), 'data.message');
$iso3 = $rD->status() === 200
    && stripos($msgD, 'Overdue purchase orders (') === false
    && stripos($msgD, 'No overdue purchase orders were found for') === false;
$set('CTX-03-clear-overdue-filter', $iso3, $rD->status(), mb_substr($msgD, 0, 160));

// Sales: general overdue after supplier denial thread
$rE = $assistant('Which purchase orders from Brazos Marble LLC are delayed?', $sTok);
$convS = (string) data_get($rE->json(), 'data.conversation_id');
$rF = $assistant('What is the status of PO-507?', $sTok, $convS);
$msgF = (string) data_get($rF->json(), 'data.message');
$sCtx = $rF->status() === 200 && str_contains($msgF, '507') && $msgF !== $refusal;
$set('CTX-04-sales-po-after-denial', $sCtx, $rF->status());

// Regression snippets (deterministic / REST, no live AI)
$rStock = $assistant('How many 3cm Calacatta slabs do we have in Houston?', $sTok);
$set(
    'REG-SALES-stock',
    $rStock->status() === 200 && str_contains((string) data_get($rStock->json(), 'data.message'), '42'),
    $rStock->status(),
);

$rLanded = $dispatch('GET', '/api/purchase-orders/507/landed-cost', [], ['Authorization' => 'Bearer '.$sTok]);
$set('REG-SALES-landed-403', $rLanded->status() === 403, $rLanded->status());

$rLandedP = $dispatch('GET', '/api/purchase-orders/507/landed-cost', [], ['Authorization' => 'Bearer '.$pTok]);
$set('REG-PURCH-landed-200', $rLandedP->status() === 200, $rLandedP->status());

$snapshot = [
    'database' => $dbName,
    'reference_date' => $today->toDateString(),
    'suppliers' => [
        'brazos' => $brazos?->only(['id', 'name']),
        'gulf' => $gulf?->only(['id', 'name']),
        'lone_star' => $loneStar?->only(['id', 'name']),
    ],
    'overdue_counts' => [
        'brazos' => count($expectedBrazosOverdue),
        'gulf' => count($expectedGulfOverdue),
        'lone_star' => count($expectedLoneStarOverdue),
        'all' => count($expectedAllOverdue),
    ],
    'po_507_status' => $po507?->status->value,
    'po_511_status' => $po511?->status->value,
];

$out = [
    'generated_at' => gmdate('c'),
    'ai_provider' => (string) config('ai.provider'),
    'erp_snapshot' => $snapshot,
    'results' => $results,
];

file_put_contents(__DIR__.'/phase1623_supplier_overdue_acceptance.json', json_encode($out, JSON_PRETTY_PRINT)."\n");
fwrite(STDOUT, "Wrote tools/phase1623_supplier_overdue_acceptance.json\n");

$failed = collect($results)->where('status', 'Failed')->count();
exit($failed > 0 ? 1 : 0);
