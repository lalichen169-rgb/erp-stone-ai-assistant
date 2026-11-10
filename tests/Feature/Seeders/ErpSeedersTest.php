<?php

namespace Tests\Feature\Seeders;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ErpSeedersTest extends TestCase
{
    use UsesErpDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('erp.demo_user_password', 'local-demo-password-for-tests-only');
    }

    private function runErpSeeders(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeds_at_least_twenty_demo_products(): void
    {
        $this->runErpSeeders();

        $this->assertGreaterThanOrEqual(20, Product::query()->where('sku', 'like', 'DEMO-%')->count());
    }

    public function test_seeds_three_demo_warehouses(): void
    {
        $this->runErpSeeders();

        $this->assertSame(3, Warehouse::query()->whereIn('name', ['Houston', 'Dallas', 'Austin'])->count());
    }

    public function test_seeds_five_demo_suppliers(): void
    {
        $this->runErpSeeders();

        $this->assertGreaterThanOrEqual(5, Supplier::query()->where('name', 'like', 'Demo Supplier —%')->count());
    }

    public function test_seeds_at_least_fifteen_demo_purchase_orders(): void
    {
        $this->runErpSeeders();

        $supplierIds = Supplier::query()->where('name', 'like', 'Demo Supplier —%')->pluck('id');

        $this->assertGreaterThanOrEqual(
            15,
            PurchaseOrder::query()->whereIn('supplier_id', $supplierIds)->count()
        );
    }

    public function test_includes_ordered_purchase_orders_with_past_etas(): void
    {
        $this->runErpSeeders();

        $count = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::Ordered)
            ->whereNotNull('eta')
            ->whereDate('eta', '<', '2026-11-06')
            ->count();

        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function test_calacatta_three_cm_houston_and_dallas_stock_levels(): void
    {
        $this->runErpSeeders();

        $product = Product::query()->where('sku', 'DEMO-STQ-CAL-30')->firstOrFail();
        $houston = Warehouse::query()->where('name', 'Houston')->firstOrFail();
        $dallas = Warehouse::query()->where('name', 'Dallas')->firstOrFail();

        $houstonStock = Stock::query()->where('product_id', $product->id)->where('warehouse_id', $houston->id)->value('slabs_on_hand');
        $dallasStock = Stock::query()->where('product_id', $product->id)->where('warehouse_id', $dallas->id)->value('slabs_on_hand');

        $this->assertSame(42, (int) $houstonStock);
        $this->assertSame(18, (int) $dallasStock);
    }

    public function test_supplier_bank_details_remain_encrypted_at_rest_after_seeding(): void
    {
        $this->runErpSeeders();

        $supplier = Supplier::query()->where('name', 'like', 'Demo Supplier —%')->firstOrFail();
        $stored = DB::table('suppliers')->where('id', $supplier->id)->value('bank_details');

        $plain = $supplier->fresh()->bank_details;

        $this->assertIsString($stored);
        $this->assertIsString($plain);
        $this->assertNotSame($plain, $stored);
        $this->assertStringContainsString('DEMO-', $plain);
    }

    public function test_rerunning_seeders_does_not_duplicate_demo_records(): void
    {
        $this->runErpSeeders();
        $productsAfterFirst = Product::query()->where('sku', 'like', 'DEMO-%')->count();
        $suppliersAfterFirst = Supplier::query()->where('name', 'like', 'Demo Supplier —%')->count();

        $this->runErpSeeders();

        $this->assertSame($productsAfterFirst, Product::query()->where('sku', 'like', 'DEMO-%')->count());
        $this->assertSame($suppliersAfterFirst, Supplier::query()->where('name', 'like', 'Demo Supplier —%')->count());
    }

    public function test_demo_users_have_correct_roles_and_hashed_passwords(): void
    {
        $this->runErpSeeders();
        $this->seed(DemoUserSeeder::class);

        $sales = User::query()->where('email', DemoUserSeeder::SALES_EMAIL)->firstOrFail();
        $purchasing = User::query()->where('email', DemoUserSeeder::PURCHASING_EMAIL)->firstOrFail();

        $this->assertTrue($sales->isSales());
        $this->assertTrue($purchasing->isPurchasing());
        $this->assertTrue(Hash::check('local-demo-password-for-tests-only', $sales->password));
        $this->assertTrue(Hash::check('local-demo-password-for-tests-only', $purchasing->password));
    }

    public function test_demo_user_seeder_fails_without_password_env(): void
    {
        Config::set('erp.demo_user_password', null);

        $this->expectException(\RuntimeException::class);

        $this->seed(DemoUserSeeder::class);
    }
}
