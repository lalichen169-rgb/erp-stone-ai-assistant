<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class StockSeeder extends Seeder
{
    public function run(): void
    {
        $calacatta3 = Product::query()->where('sku', 'DEMO-STQ-CAL-30')->firstOrFail();
        $houston = Warehouse::query()->where('name', 'Houston')->firstOrFail();
        $dallas = Warehouse::query()->where('name', 'Dallas')->firstOrFail();
        $austin = Warehouse::query()->where('name', 'Austin')->firstOrFail();

        $levels = [
            ['product_sku' => 'DEMO-STQ-CAL-30', 'warehouse' => 'Houston', 'slabs' => 42],
            ['product_sku' => 'DEMO-STQ-CAL-30', 'warehouse' => 'Dallas', 'slabs' => 18],
            ['product_sku' => 'DEMO-STQ-CAL-30', 'warehouse' => 'Austin', 'slabs' => 9],
            ['product_sku' => 'DEMO-STQ-CAL-20', 'warehouse' => 'Houston', 'slabs' => 25],
            ['product_sku' => 'DEMO-STQ-CAR-30', 'warehouse' => 'Houston', 'slabs' => 14],
            ['product_sku' => 'DEMO-STQ-CAR-30', 'warehouse' => 'Dallas', 'slabs' => 11],
            ['product_sku' => 'DEMO-MAR-CAL-30', 'warehouse' => 'Austin', 'slabs' => 7],
            ['product_sku' => 'DEMO-MAR-CAR-20', 'warehouse' => 'Dallas', 'slabs' => 16],
            ['product_sku' => 'DEMO-GRN-BLK-30', 'warehouse' => 'Houston', 'slabs' => 20],
            ['product_sku' => 'DEMO-GRN-BLK-30', 'warehouse' => 'Austin', 'slabs' => 6],
            ['product_sku' => 'DEMO-STQ-PAR-30', 'warehouse' => 'Dallas', 'slabs' => 22],
            ['product_sku' => 'DEMO-MAR-TRA-30', 'warehouse' => 'Houston', 'slabs' => 10],
        ];

        foreach ($levels as $level) {
            $product = Product::query()->where('sku', $level['product_sku'])->firstOrFail();
            $warehouse = Warehouse::query()->where('name', $level['warehouse'])->firstOrFail();

            Stock::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                ],
                ['slabs_on_hand' => $level['slabs']]
            );
        }
    }
}
