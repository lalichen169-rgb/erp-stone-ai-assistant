<?php

namespace Tests\Feature\Erp\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Erp\StockLookupService;
use App\Support\ProductColorLexicon;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ProductAttributeResolutionTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    private StockLookupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);
        $this->service = app(StockLookupService::class);
        ProductColorLexicon::forgetCachedColors();
    }

    public function test_invalid_material_removed_from_coerced_filters_after_relaxation(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            ['color' => 'Calacatta', 'material' => 'Granite', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $this->assertTrue($result['multi_product'] ?? false);
        $this->assertSame('material_filter_relaxed', $result['filter_resolution'] ?? null);
        $effective = $result['effective_filters'] ?? [];
        $this->assertArrayNotHasKey('material', $effective);
        $this->assertSame('Calacatta', $effective['color'] ?? null);
        $this->assertSame('3.00', $effective['thickness_cm'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($result['stock_levels'] ?? []));
    }

    public function test_rejected_material_does_not_trigger_single_sku_result_for_calacatta_austin(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            ['color' => 'Calacatta', 'material' => 'Granite', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $skus = collect($result['stock_levels'] ?? [])->pluck('product.sku')->filter()->values()->all();
        $this->assertContains('DEMO-STQ-CAL-30', $skus);
        $this->assertContains('DEMO-MAR-CAL-30', $skus);
    }

    public function test_valid_user_material_still_narrows_to_quartz(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            ['color' => 'Calacatta', 'material' => 'Quartz', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $this->assertTrue($result['found']);
        $this->assertFalse($result['multi_product'] ?? false);
        $this->assertSame('DEMO-STQ-CAL-30', $result['product']['sku'] ?? null);
    }

    public function test_calacatta_and_carrara_colors_return_both_varieties_in_houston(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            ['colors' => ['Calacatta', 'Carrara'], 'thickness_cm' => '3.00'],
            ['name' => 'Houston'],
        );

        $this->assertTrue($result['multi_product'] ?? false);
        $colors = collect($result['stock_levels'] ?? [])->pluck('product.color')->unique()->values()->all();
        $this->assertContains('Calacatta', $colors);
        $this->assertContains('Carrara', $colors);
    }

    public function test_quartz_material_at_houston_returns_multiple_quartz_skus_when_thickness_omitted(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            ['material' => 'Quartz'],
            ['name' => 'Houston'],
        );

        $this->assertTrue($result['multi_product'] ?? false);
        $materials = collect($result['stock_levels'] ?? [])->pluck('product.material')->unique()->all();
        $this->assertSame(['Quartz'], $materials);
        $this->assertGreaterThan(1, count($result['stock_levels'] ?? []));
    }

    public function test_unknown_statuario_preserves_color_filter_and_returns_not_found(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            ['color' => 'Statuario'],
            ['name' => 'Houston'],
        );

        $this->assertFalse($result['found']);
        $this->assertSame('product_not_found', $result['reason'] ?? null);
        $this->assertSame('Statuario', $result['effective_filters']['color'] ?? null);
    }

    public function test_new_catalog_color_recognized_after_product_insert_without_code_change(): void
    {
        Product::query()->create([
            'sku' => 'TEST-LEX-STAT-30',
            'name' => 'Statuario Quartz',
            'material' => 'Quartz',
            'color' => 'Statuario',
            'thickness_cm' => '3.00',
            'slab_size' => '320x160',
        ]);
        ProductColorLexicon::forgetCachedColors();

        $lexicon = app(ProductColorLexicon::class);
        $this->assertSame('Statuario', $lexicon->matchInText('How many 3cm Statuario slabs in Houston?'));
    }

    public function test_all_varieties_at_houston_returns_multi_product_without_color_filter(): void
    {
        $user = $this->createSalesApiUser();

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $user,
            [],
            ['name' => 'Houston'],
        );

        $this->assertTrue($result['multi_product'] ?? false);
        $this->assertGreaterThan(2, count($result['stock_levels'] ?? []));
    }
}
