<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * @return list<array{name: string, contact: string, payment_terms: string, bank_details: string}>
     */
    public static function demoSuppliers(): array
    {
        return [
            [
                'name' => 'Demo Supplier — Gulf Stone Imports',
                'contact' => 'orders@gulf-stone-demo.example',
                'payment_terms' => 'Net 30',
                'bank_details' => 'Demo Bank — Account DEMO-GSI-001 — Routing DEMO-111000',
            ],
            [
                'name' => 'Demo Supplier — Lone Star Slabs',
                'contact' => 'purchasing@lonestar-slabs-demo.example',
                'payment_terms' => 'Net 45',
                'bank_details' => 'Demo Bank — Account DEMO-LSS-002 — Routing DEMO-222000',
            ],
            [
                'name' => 'Demo Supplier — Hill Country Quartz Co',
                'contact' => 'sales@hc-quartz-demo.example',
                'payment_terms' => 'Net 30',
                'bank_details' => 'Demo Bank — Account DEMO-HCQ-003 — Routing DEMO-333000',
            ],
            [
                'name' => 'Demo Supplier — Brazos Marble LLC',
                'contact' => 'accounts@brazos-marble-demo.example',
                'payment_terms' => '2/10 Net 30',
                'bank_details' => 'Demo Bank — Account DEMO-BML-004 — Routing DEMO-444000',
            ],
            [
                'name' => 'Demo Supplier — Central Texas Granite',
                'contact' => 'logistics@ct-granite-demo.example',
                'payment_terms' => 'Net 60',
                'bank_details' => 'Demo Bank — Account DEMO-CTG-005 — Routing DEMO-555000',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::demoSuppliers() as $supplier) {
            Supplier::query()->updateOrCreate(
                ['name' => $supplier['name']],
                $supplier
            );
        }
    }
}
