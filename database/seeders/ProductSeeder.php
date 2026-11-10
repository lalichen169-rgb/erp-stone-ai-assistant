<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * @return list<array{sku: string, name: string, material: string, color: string, thickness_cm: string, slab_size: string}>
     */
    public static function demoProducts(): array
    {
        return [
            ['sku' => 'DEMO-STQ-CAL-30', 'name' => 'Calacatta Quartz', 'material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => '3.00', 'slab_size' => '320x160 cm'],
            ['sku' => 'DEMO-STQ-CAL-20', 'name' => 'Calacatta Quartz', 'material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => '2.00', 'slab_size' => '320x160 cm'],
            ['sku' => 'DEMO-STQ-CAR-30', 'name' => 'Carrara Quartz', 'material' => 'Quartz', 'color' => 'Carrara', 'thickness_cm' => '3.00', 'slab_size' => '300x150 cm'],
            ['sku' => 'DEMO-STQ-CAR-20', 'name' => 'Carrara Quartz', 'material' => 'Quartz', 'color' => 'Carrara', 'thickness_cm' => '2.00', 'slab_size' => '300x150 cm'],
            ['sku' => 'DEMO-MAR-CAL-30', 'name' => 'Calacatta Marble', 'material' => 'Marble', 'color' => 'Calacatta', 'thickness_cm' => '3.00', 'slab_size' => '310x155 cm'],
            ['sku' => 'DEMO-MAR-CAL-20', 'name' => 'Calacatta Marble', 'material' => 'Marble', 'color' => 'Calacatta', 'thickness_cm' => '2.00', 'slab_size' => '310x155 cm'],
            ['sku' => 'DEMO-MAR-CAR-30', 'name' => 'Carrara Marble', 'material' => 'Marble', 'color' => 'Carrara', 'thickness_cm' => '3.00', 'slab_size' => '290x145 cm'],
            ['sku' => 'DEMO-MAR-CAR-20', 'name' => 'Carrara Marble', 'material' => 'Marble', 'color' => 'Carrara', 'thickness_cm' => '2.00', 'slab_size' => '290x145 cm'],
            ['sku' => 'DEMO-MAR-STA-30', 'name' => 'Statuario Marble', 'material' => 'Marble', 'color' => 'White', 'thickness_cm' => '3.00', 'slab_size' => '305x152 cm'],
            ['sku' => 'DEMO-MAR-STA-20', 'name' => 'Statuario Marble', 'material' => 'Marble', 'color' => 'White', 'thickness_cm' => '2.00', 'slab_size' => '305x152 cm'],
            ['sku' => 'DEMO-MAR-EMP-30', 'name' => 'Emperador Dark', 'material' => 'Marble', 'color' => 'Brown', 'thickness_cm' => '3.00', 'slab_size' => '280x140 cm'],
            ['sku' => 'DEMO-MAR-TRA-30', 'name' => 'Travertine Classic', 'material' => 'Travertine', 'color' => 'Beige', 'thickness_cm' => '3.00', 'slab_size' => '300x150 cm'],
            ['sku' => 'DEMO-MAR-TRA-20', 'name' => 'Travertine Classic', 'material' => 'Travertine', 'color' => 'Beige', 'thickness_cm' => '2.00', 'slab_size' => '300x150 cm'],
            ['sku' => 'DEMO-GRN-BLK-30', 'name' => 'Black Galaxy', 'material' => 'Granite', 'color' => 'Black', 'thickness_cm' => '3.00', 'slab_size' => '300x180 cm'],
            ['sku' => 'DEMO-GRN-BLK-20', 'name' => 'Black Galaxy', 'material' => 'Granite', 'color' => 'Black', 'thickness_cm' => '2.00', 'slab_size' => '300x180 cm'],
            ['sku' => 'DEMO-GRN-Uba-30', 'name' => 'Uba Tuba', 'material' => 'Granite', 'color' => 'Green', 'thickness_cm' => '3.00', 'slab_size' => '295x175 cm'],
            ['sku' => 'DEMO-STQ-PAR-30', 'name' => 'Paris Grey Quartz', 'material' => 'Quartz', 'color' => 'Grey', 'thickness_cm' => '3.00', 'slab_size' => '320x160 cm'],
            ['sku' => 'DEMO-STQ-PAR-20', 'name' => 'Paris Grey Quartz', 'material' => 'Quartz', 'color' => 'Grey', 'thickness_cm' => '2.00', 'slab_size' => '320x160 cm'],
            ['sku' => 'DEMO-STQ-IVR-30', 'name' => 'Ivory Quartz', 'material' => 'Quartz', 'color' => 'Ivory', 'thickness_cm' => '3.00', 'slab_size' => '310x155 cm'],
            ['sku' => 'DEMO-MAR-NER-30', 'name' => 'Nero Marquina', 'material' => 'Marble', 'color' => 'Black', 'thickness_cm' => '3.00', 'slab_size' => '280x140 cm'],
            ['sku' => 'DEMO-MAR-CRE-20', 'name' => 'Crema Marfil', 'material' => 'Marble', 'color' => 'Cream', 'thickness_cm' => '2.00', 'slab_size' => '300x150 cm'],
            ['sku' => 'DEMO-STQ-MID-30', 'name' => 'Midnight Quartz', 'material' => 'Quartz', 'color' => 'Black', 'thickness_cm' => '3.00', 'slab_size' => '320x160 cm'],
        ];
    }

    public function run(): void
    {
        foreach (self::demoProducts() as $product) {
            Product::query()->updateOrCreate(
                ['sku' => $product['sku']],
                $product
            );
        }
    }
}
