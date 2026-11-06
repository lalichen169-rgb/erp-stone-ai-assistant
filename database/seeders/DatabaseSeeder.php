<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed demo ERP reference data (idempotent).
     *
     * Demo users are opt-in — run DemoUserSeeder separately after setting DEMO_USER_PASSWORD.
     */
    public function run(): void
    {
        $this->call([
            ProductSeeder::class,
            WarehouseSeeder::class,
            SupplierSeeder::class,
            StockSeeder::class,
            PurchaseOrderSeeder::class,
        ]);
    }
}
