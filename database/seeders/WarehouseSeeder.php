<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * @return list<array{name: string, city: string}>
     */
    public static function demoWarehouses(): array
    {
        return [
            ['name' => 'Houston', 'city' => 'Houston'],
            ['name' => 'Dallas', 'city' => 'Dallas'],
            ['name' => 'Austin', 'city' => 'Austin'],
        ];
    }

    public function run(): void
    {
        foreach (self::demoWarehouses() as $warehouse) {
            Warehouse::query()->updateOrCreate(
                ['name' => $warehouse['name'], 'city' => $warehouse['city']],
                $warehouse
            );
        }
    }
}
