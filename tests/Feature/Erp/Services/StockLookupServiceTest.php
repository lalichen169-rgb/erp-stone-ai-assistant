<?php

namespace Tests\Feature\Erp\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\StockLookupService;
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
        $warehouse = Warehouse::query()->create(['name' => 'Houston', 'city' => 'Houston']);

        $noRow = $this->service->stockForProductAtWarehouse($this->salesUser, $product->id, $warehouse->id);

        $this->assertTrue($noRow['found']);
        $this->assertSame(0, $noRow['slabs_on_hand']);
        $this->assertFalse($noRow['has_stock_record']);
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
