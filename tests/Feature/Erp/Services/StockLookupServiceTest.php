<?php

namespace Tests\Feature\Erp\Services;

use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\StockLookupService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\TestCase;

class StockLookupServiceTest extends TestCase
{
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private StockLookupService $service;

    private User $salesUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StockLookupService;
        $this->salesUser = User::factory()->create();
    }

    public function test_finds_calacatta_three_cm_product_and_houston_dallas_stock(): void
    {
        ['product' => $product, 'warehouses' => $warehouses] = $this->seedCalacattaStockScenario();

        $matches = $this->service->searchProducts($this->salesUser, [
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => 3,
        ]);

        $this->assertCount(1, $matches);
        $this->assertSame($product->id, $matches[0]['id']);

        $houston = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['color' => 'Calacatta', 'thickness_cm' => 3, 'material' => 'Quartz'],
            ['name' => 'Houston']
        );

        $this->assertTrue($houston['found']);
        $this->assertSame(42, $houston['slabs_on_hand']);

        $dallas = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['sku' => 'TEST-STQ-CAL-30'],
            ['name' => 'Dallas']
        );

        $this->assertTrue($dallas['found']);
        $this->assertSame(18, $dallas['slabs_on_hand']);
        $this->assertSame($warehouses['dallas']->id, $dallas['warehouse']['id']);
    }

    public function test_missing_product_or_warehouse_returns_clear_zero_result(): void
    {
        $result = $this->service->stockForProductAtWarehouse($this->salesUser, 99999, 88888);

        $this->assertFalse($result['found']);
        $this->assertSame(0, $result['slabs_on_hand']);
        $this->assertFalse($result['has_stock_record']);

        $product = $this->createCalacattaThreeCmDemoProduct();
        $warehouse = $this->createHoustonDallasAustinWarehouses()['houston'];

        $noRow = $this->service->stockForProductAtWarehouse($this->salesUser, $product->id, $warehouse->id);

        $this->assertTrue($noRow['found']);
        $this->assertSame(0, $noRow['slabs_on_hand']);
        $this->assertFalse($noRow['has_stock_record']);
    }

    public function test_wrong_material_granite_resolves_quartz_calacatta_houston_stock(): void
    {
        $this->seedCalacattaStockScenario();

        $houston = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['color' => 'Calacatta', 'thickness_cm' => '3cm', 'material' => 'Granite'],
            ['name' => 'Houston'],
        );

        $this->assertTrue($houston['found']);
        $this->assertSame('material_filter_relaxed', $houston['filter_resolution'] ?? null);
        if (($houston['multi_product'] ?? false) === true) {
            $this->assertArrayNotHasKey('material', $houston['effective_filters'] ?? []);
            $this->assertSame(42, collect($houston['stock_levels'] ?? [])->max('slabs_on_hand'));
        } else {
            $this->assertSame(42, $houston['slabs_on_hand']);
            $this->assertSame('Quartz', $houston['product']['material'] ?? null);
        }
    }

    public function test_calacatta_quartz_compound_material_and_quartz_only_resolve_austin_stock(): void
    {
        $this->seedCalacattaStockScenario();

        $compound = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['material' => 'Calacatta Quartz', 'thickness_cm' => 3],
            ['name' => 'Austin'],
        );

        $this->assertTrue($compound['found']);
        $this->assertSame(9, $compound['slabs_on_hand']);

        $quartzOnly = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['material' => 'Quartz', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $this->assertTrue($quartzOnly['found']);
        $this->assertSame(9, $quartzOnly['slabs_on_hand']);
    }

    public function test_color_and_thickness_without_material_returns_houston_stock(): void
    {
        $this->seedCalacattaStockScenario();

        $houston = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['color' => 'Calacatta', 'thickness_cm' => 3],
            ['name' => 'Houston'],
        );

        $this->assertTrue($houston['found']);
        $this->assertSame(42, $houston['slabs_on_hand']);
    }

    public function test_three_cm_calacatta_at_austin_returns_quartz_and_marble(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['color' => 'Calacatta', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $this->assertTrue($result['found']);
        $this->assertTrue($result['multi_product'] ?? false);
        $levels = $result['stock_levels'] ?? [];
        $this->assertCount(2, $levels);
        $this->assertSame(9, collect($levels)->firstWhere('product.sku', 'DEMO-STQ-CAL-30')['slabs_on_hand'] ?? null);
        $this->assertSame(7, collect($levels)->firstWhere('product.sku', 'DEMO-MAR-CAL-30')['slabs_on_hand'] ?? null);
    }

    public function test_three_cm_calacatta_quartz_at_austin_returns_single_quartz_product(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['color' => 'Calacatta', 'material' => 'Quartz', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $this->assertTrue($result['found']);
        $this->assertSame(9, $result['slabs_on_hand']);
        $this->assertSame('DEMO-STQ-CAL-30', $result['product']['sku'] ?? null);
    }

    public function test_color_without_thickness_returns_all_matching_products_at_one_warehouse(): void
    {
        $this->seedCalacattaStockScenario();

        $twoCm = Product::query()->create([
            'sku' => 'TEST-STQ-CAL-20',
            'name' => 'Calacatta Quartz Slab',
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '2.00',
            'slab_size' => '320x160 cm',
        ]);

        $houston = Warehouse::query()->where('name', 'Houston')->firstOrFail();
        Stock::query()->create([
            'product_id' => $twoCm->id,
            'warehouse_id' => $houston->id,
            'slabs_on_hand' => 25,
        ]);

        $result = $this->service->stockForProductAtWarehouseByAttributes(
            $this->salesUser,
            ['color' => 'Calacatta'],
            ['name' => 'Houston'],
        );

        $this->assertTrue($result['found']);
        $this->assertTrue($result['multi_product'] ?? false);
        $levels = $result['stock_levels'] ?? [];
        $this->assertCount(2, $levels);
        $thicknesses = collect($levels)->pluck('product.thickness_cm')->sort()->values()->all();
        $this->assertSame(['2.00', '3.00'], $thicknesses);
        $this->assertSame(42, collect($levels)->firstWhere('product.thickness_cm', '3.00')['slabs_on_hand'] ?? null);
        $this->assertSame(25, collect($levels)->firstWhere('product.thickness_cm', '2.00')['slabs_on_hand'] ?? null);
    }

    public function test_attribute_search_does_not_return_duplicate_products(): void
    {
        Product::query()->create([
            'sku' => 'TEST-DUP-1',
            'name' => 'Calacatta A',
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '3.00',
            'slab_size' => '300x150',
        ]);

        Product::query()->create([
            'sku' => 'TEST-DUP-2',
            'name' => 'Calacatta B',
            'material' => 'Quartz',
            'color' => 'Calacatta',
            'thickness_cm' => '2.00',
            'slab_size' => '300x150',
        ]);

        $results = $this->service->searchProducts($this->salesUser, ['color' => 'Calacatta']);

        $this->assertCount(2, $results);
        $this->assertSame(
            ['TEST-DUP-1', 'TEST-DUP-2'],
            collect($results)->pluck('sku')->sort()->values()->all()
        );

        $stockRows = $this->service->searchStockLevels(
            $this->salesUser,
            ['color' => 'Calacatta'],
            [],
            50
        );

        $productIds = collect($stockRows)->pluck('product.id');
        $this->assertSame($productIds->count(), $productIds->unique()->count());
    }
}
