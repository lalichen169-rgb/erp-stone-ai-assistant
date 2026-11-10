<?php

namespace Database\Seeders;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PurchaseOrderSeeder extends Seeder
{
    /**
     * Demo POs keyed by stable (supplier_name, order_date).
     *
     * @return list<array{
     *     supplier: string,
     *     order_date: string,
     *     status: PurchaseOrderStatus,
     *     eta: ?string,
     *     freight_cost: string,
     *     lines: list<array{sku: string, quantity: int, unit_cost: string}>
     * }>
     */
    public static function demoPurchaseOrders(): array
    {
        return [
            [
                'supplier' => 'Demo Supplier — Gulf Stone Imports',
                'order_date' => '2026-07-10',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-08-20',
                'freight_cost' => '1250.00',
                'lines' => [
                    ['sku' => 'DEMO-STQ-CAL-30', 'quantity' => 20, 'unit_cost' => '410.00'],
                    ['sku' => 'DEMO-MAR-CAL-30', 'quantity' => 8, 'unit_cost' => '520.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Lone Star Slabs',
                'order_date' => '2026-08-01',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-09-15',
                'freight_cost' => '980.50',
                'lines' => [
                    ['sku' => 'DEMO-STQ-CAR-30', 'quantity' => 15, 'unit_cost' => '385.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Hill Country Quartz Co',
                'order_date' => '2026-09-05',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-12-01',
                'freight_cost' => '750.00',
                'lines' => [
                    ['sku' => 'DEMO-STQ-PAR-30', 'quantity' => 12, 'unit_cost' => '365.00'],
                    ['sku' => 'DEMO-STQ-IVR-30', 'quantity' => 10, 'unit_cost' => '340.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Brazos Marble LLC',
                'order_date' => '2026-06-01',
                'status' => PurchaseOrderStatus::Draft,
                'eta' => '2026-08-01',
                'freight_cost' => '0.00',
                'lines' => [
                    ['sku' => 'DEMO-MAR-STA-30', 'quantity' => 6, 'unit_cost' => '610.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Central Texas Granite',
                'order_date' => '2026-05-15',
                'status' => PurchaseOrderStatus::Received,
                'eta' => '2026-06-30',
                'freight_cost' => '1100.00',
                'lines' => [
                    ['sku' => 'DEMO-GRN-BLK-30', 'quantity' => 18, 'unit_cost' => '295.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Gulf Stone Imports',
                'order_date' => '2026-04-20',
                'status' => PurchaseOrderStatus::Cancelled,
                'eta' => null,
                'freight_cost' => '0.00',
                'lines' => [
                    ['sku' => 'DEMO-MAR-NER-30', 'quantity' => 4, 'unit_cost' => '480.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Lone Star Slabs',
                'order_date' => '2026-10-01',
                'status' => PurchaseOrderStatus::Draft,
                'eta' => '2026-11-15',
                'freight_cost' => '0.00',
                'lines' => [
                    ['sku' => 'DEMO-STQ-MID-30', 'quantity' => 14, 'unit_cost' => '355.00'],
                    ['sku' => 'DEMO-STQ-CAL-20', 'quantity' => 10, 'unit_cost' => '320.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Hill Country Quartz Co',
                'order_date' => '2026-03-12',
                'status' => PurchaseOrderStatus::Received,
                'eta' => '2026-04-25',
                'freight_cost' => '890.25',
                'lines' => [
                    ['sku' => 'DEMO-STQ-CAL-30', 'quantity' => 30, 'unit_cost' => '405.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Brazos Marble LLC',
                'order_date' => '2026-10-20',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-11-28',
                'freight_cost' => '640.00',
                'lines' => [
                    ['sku' => 'DEMO-MAR-TRA-30', 'quantity' => 9, 'unit_cost' => '275.00'],
                    ['sku' => 'DEMO-MAR-CRE-20', 'quantity' => 7, 'unit_cost' => '240.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Central Texas Granite',
                'order_date' => '2026-02-08',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-03-01',
                'freight_cost' => '1320.75',
                'lines' => [
                    ['sku' => 'DEMO-GRN-Uba-30', 'quantity' => 11, 'unit_cost' => '310.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Gulf Stone Imports',
                'order_date' => '2026-11-01',
                'status' => PurchaseOrderStatus::Draft,
                'eta' => '2026-12-15',
                'freight_cost' => '0.00',
                'lines' => [
                    ['sku' => 'DEMO-MAR-CAR-30', 'quantity' => 5, 'unit_cost' => '450.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Lone Star Slabs',
                'order_date' => '2026-01-22',
                'status' => PurchaseOrderStatus::Received,
                'eta' => '2026-02-28',
                'freight_cost' => '715.00',
                'lines' => [
                    ['sku' => 'DEMO-STQ-PAR-20', 'quantity' => 16, 'unit_cost' => '330.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Hill Country Quartz Co',
                'order_date' => '2026-09-18',
                'status' => PurchaseOrderStatus::Cancelled,
                'eta' => null,
                'freight_cost' => '0.00',
                'lines' => [
                    ['sku' => 'DEMO-STQ-CAR-20', 'quantity' => 8, 'unit_cost' => '300.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Brazos Marble LLC',
                'order_date' => '2026-08-30',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-10-05',
                'freight_cost' => '540.00',
                'lines' => [
                    ['sku' => 'DEMO-MAR-EMP-30', 'quantity' => 6, 'unit_cost' => '390.00'],
                    ['sku' => 'DEMO-MAR-STA-20', 'quantity' => 4, 'unit_cost' => '560.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Central Texas Granite',
                'order_date' => '2026-07-25',
                'status' => PurchaseOrderStatus::Draft,
                'eta' => '2026-09-30',
                'freight_cost' => '0.00',
                'lines' => [
                    ['sku' => 'DEMO-GRN-BLK-20', 'quantity' => 12, 'unit_cost' => '265.00'],
                ],
            ],
            [
                'supplier' => 'Demo Supplier — Gulf Stone Imports',
                'order_date' => '2026-10-12',
                'status' => PurchaseOrderStatus::Ordered,
                'eta' => '2026-11-05',
                'freight_cost' => '825.00',
                'lines' => [
                    ['sku' => 'DEMO-STQ-CAL-30', 'quantity' => 22, 'unit_cost' => '415.00'],
                    ['sku' => 'DEMO-STQ-IVR-30', 'quantity' => 6, 'unit_cost' => '345.00'],
                ],
            ],
        ];
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::demoPurchaseOrders() as $definition) {
                $supplier = Supplier::query()->where('name', $definition['supplier'])->firstOrFail();

                $order = PurchaseOrder::query()->updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'order_date' => $definition['order_date'],
                    ],
                    [
                        'status' => $definition['status'],
                        'eta' => $definition['eta'],
                        'freight_cost' => $definition['freight_cost'],
                    ]
                );

                $order->lines()->delete();

                foreach ($definition['lines'] as $line) {
                    $product = Product::query()->where('sku', $line['sku'])->firstOrFail();

                    PurchaseOrderLine::query()->create([
                        'purchase_order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                    ]);
                }
            }
        });
    }
}
