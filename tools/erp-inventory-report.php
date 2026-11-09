<?php

/**
 * Console inventory matrix: products × warehouses (slabs on hand) + supplier × PO line history.
 * Not sent to the AI — uses the same DB as the ERP.
 *
 * Usage (from project root):
 *   tools\php\php.exe tools\erp-inventory-report.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

$warehouses = Warehouse::query()->orderBy('name')->get();
$products = Product::query()->orderBy('sku')->get();

$stockByKey = Stock::query()
    ->get()
    ->keyBy(fn (Stock $s) => $s->product_id.'|'.$s->warehouse_id);

echo PHP_EOL;
echo '=== StoneERP — Inventory by product and warehouse (slabs on hand) ==='.PHP_EOL;
echo 'Warehouses: '.($warehouses->pluck('name')->join(', ') ?: '(none)').PHP_EOL;
echo str_repeat('-', 120).PHP_EOL;

$header = sprintf(
    '%-22s %-28s %-8s %-8s',
    'SKU',
    'Product',
    'Thk cm',
    'Material',
);
foreach ($warehouses as $wh) {
    $header .= sprintf(' | %8s', $wh->name);
}
$header .= ' | TOTAL';
echo $header.PHP_EOL;
echo str_repeat('-', 120).PHP_EOL;

$grandByWarehouse = array_fill_keys($warehouses->pluck('id')->all(), 0);
$grandTotal = 0;

foreach ($products as $product) {
    $rowTotal = 0;
    $line = sprintf(
        '%-22s %-28s %-8s %-8s',
        mb_substr($product->sku, 0, 22),
        mb_substr($product->name, 0, 28),
        (string) $product->thickness_cm,
        mb_substr((string) $product->material, 0, 8),
    );

    foreach ($warehouses as $wh) {
        $key = $product->id.'|'.$wh->id;
        $qty = isset($stockByKey[$key]) ? (int) $stockByKey[$key]->slabs_on_hand : 0;
        $rowTotal += $qty;
        $grandByWarehouse[$wh->id] += $qty;
        $line .= sprintf(' | %8d', $qty);
    }

    $grandTotal += $rowTotal;
    $line .= sprintf(' | %8d', $rowTotal);
    echo $line.PHP_EOL;
}

echo str_repeat('-', 120).PHP_EOL;
$totalsLine = sprintf('%-68s', 'WAREHOUSE TOTALS');
foreach ($warehouses as $wh) {
    $totalsLine .= sprintf(' | %8d', $grandByWarehouse[$wh->id]);
}
$totalsLine .= sprintf(' | %8d', $grandTotal);
echo $totalsLine.PHP_EOL;

echo PHP_EOL;
echo 'Note: Inventory is stored per product + warehouse. Suppliers are linked via purchase orders, not warehouse stock rows.'.PHP_EOL;
echo PHP_EOL;

echo '=== Suppliers (master data) ==='.PHP_EOL;
echo str_repeat('-', 100).PHP_EOL;
foreach (Supplier::query()->orderBy('name')->get() as $supplier) {
    echo sprintf(
        "ID %-4d  %-45s  %s  %s".PHP_EOL,
        $supplier->id,
        mb_substr($supplier->name, 0, 45),
        $supplier->contact,
        $supplier->payment_terms,
    );
}

echo PHP_EOL;
echo '=== Purchase order lines: supplier → product (ordered qty; not warehouse on-hand) ==='.PHP_EOL;
echo str_repeat('-', 100).PHP_EOL;

$poLines = DB::table('purchase_order_lines as pol')
    ->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
    ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
    ->join('products as p', 'p.id', '=', 'pol.product_id')
    ->select([
        's.name as supplier_name',
        'po.id as purchase_order_id',
        'po.status',
        'p.sku',
        'p.name as product_name',
        'pol.quantity',
    ])
    ->orderBy('s.name')
    ->orderBy('po.id')
    ->orderBy('p.sku')
    ->get();

if ($poLines->isEmpty()) {
    echo '(No purchase order lines in database.)'.PHP_EOL;
} else {
    foreach ($poLines as $row) {
        echo sprintf(
            '%-40s  PO#%-5s  %-10s  %-22s  qty %d'.PHP_EOL,
            mb_substr((string) $row->supplier_name, 0, 40),
            (string) $row->purchase_order_id,
            is_object($row->status) ? (string) $row->status->value : (string) $row->status,
            (string) $row->sku,
            (int) $row->quantity,
        );
    }
}

echo PHP_EOL;
echo '=== Stock rows only (locations with a stock record) ==='.PHP_EOL;
echo str_repeat('-', 80).PHP_EOL;

Stock::query()
    ->with(['product', 'warehouse'])
    ->get()
    ->sortBy(fn (Stock $s) => ($s->product?->sku ?? '').'|'.($s->warehouse?->name ?? ''))
    ->values()
    ->each(function (Stock $stock): void {
        $p = $stock->product;
        $w = $stock->warehouse;
        echo sprintf(
            '%s | %s | %s, %s | %d slabs'.PHP_EOL,
            $p->sku,
            $p->name,
            $w->name,
            $w->city,
            (int) $stock->slabs_on_hand,
        );
    });

echo PHP_EOL;
echo 'Done.'.PHP_EOL;
