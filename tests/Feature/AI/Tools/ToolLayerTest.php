<?php

namespace Tests\Feature\AI\Tools;

use App\DTOs\AI\AiToolCall;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ToolLayerTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_registry_lists_eight_tools_and_filters_by_role(): void
    {
        $registry = app(ToolRegistry::class);
        $names = $registry->registeredNames();
        sort($names);

        $this->assertSame([
            'calculateReplenishmentQuantity',
            'checkStock',
            'findOverduePurchaseOrders',
            'getLandedCost',
            'getSupplierBankDetails',
            'proposeDraftPurchaseOrder',
            'resolveProductsForPurchase',
            'resolveWarehouseForPurchase',
            'searchProducts',
            'searchPurchaseOrders',
            'searchSuppliers',
            'searchWarehouses',
            'validateSupplierProductOffering',
        ], $names);

        $sales = $this->createSalesApiUser();
        $legacySales = $registry->definitionsFor($sales, 'openai');
        $this->assertCount(3, $legacySales);
        $this->assertSame(
            ['checkStock', 'findOverduePurchaseOrders', 'searchPurchaseOrders'],
            collect($legacySales)->pluck('name')->sort()->values()->all()
        );

        $ollamaSales = $registry->definitionsFor($sales, 'ollama');
        $this->assertCount(4, $ollamaSales);
        $this->assertContains('searchProducts', collect($ollamaSales)->pluck('name')->all());

        $purchasing = $this->createPurchasingApiUser();
        $this->assertCount(12, $registry->definitionsFor($purchasing, 'openai'));
        $this->assertCount(12, $registry->definitionsFor($purchasing, 'claude'));
        $this->assertCount(13, $registry->definitionsFor($purchasing, 'ollama'));

        $legacyPurchasingOpenAi = collect($registry->definitionsFor($purchasing, 'openai'))->pluck('name')->sort()->values()->all();
        $legacyPurchasingClaude = collect($registry->definitionsFor($purchasing, 'claude'))->pluck('name')->sort()->values()->all();
        $this->assertSame($legacyPurchasingOpenAi, $legacyPurchasingClaude);
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'nonexistent', [])
        );

        $this->assertFalse($result->success);
        $this->assertSame('unknown_tool', $result->errorCode);
    }

    public function test_forbidden_argument_keys_are_rejected(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'warehouse_name' => 'Houston',
                'user_id' => 999,
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('forbidden_arguments', $result->errorCode);
    }

    public function test_unknown_extra_argument_keys_are_stripped_before_execution(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
                'unexpected_key' => 'x',
            ])
        );

        if (! $result->success) {
            $this->assertNotSame(
                'Tool arguments contained unknown or disallowed keys.',
                $result->errorMessage,
            );

            return;
        }

        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_role_override_in_tool_arguments_is_forbidden(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'warehouse_name' => 'Houston',
                'role' => 'purchasing',
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('forbidden_arguments', $result->errorCode);
    }

    public function test_conversation_key_override_in_tool_arguments_is_forbidden(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createPurchasingApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'warehouse_name' => 'Houston',
                'conversation_key' => 'evil',
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('forbidden_arguments', $result->errorCode);
    }

    public function test_openai_style_raw_arguments_are_rejected_as_invalid_json(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', ['raw' => 'not-json-{'])
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_arguments', $result->errorCode);
    }

    public function test_tool_arguments_cannot_inject_invalid_status_strings(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'searchPurchaseOrders', [
                'status' => 'ordered; DROP TABLE suppliers--',
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('validation_error', $result->errorCode);
    }

    public function test_check_stock_all_warehouses_lists_every_thickness_and_site(): void
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

        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'all_warehouses' => true,
                'color' => 'Calacatta',
                'material' => 'Quartz',
            ])
        );

        $this->assertTrue($result->success);
        $levels = $result->data['stock_levels'] ?? [];
        $this->assertGreaterThanOrEqual(4, count($levels));
        $thicknesses = collect($levels)->pluck('product.thickness_cm')->unique()->sort()->values()->all();
        $this->assertContains('2.00', $thicknesses);
        $this->assertContains('3.00', $thicknesses);
        $warehouses = collect($levels)->pluck('warehouse.name')->unique()->sort()->values()->all();
        $this->assertSame(['Austin', 'Dallas', 'Houston'], $warehouses);
    }

    public function test_check_stock_calls_service_and_returns_no_costs(): void
    {
        $this->seedCalacattaStockScenario();

        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
            ])
        );

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);

        $encoded = json_encode($result->data);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight', $encoded);
    }

    public function test_check_stock_requires_product_and_warehouse_filters(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('validation_error', $result->errorCode);
    }

    public function test_search_purchase_orders_returns_no_unit_costs(): void
    {
        $supplier = $this->createSupplierWithName('Tool PO Supplier');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-10-01',
            '2026-11-15',
            '100.00',
            [['product' => $product, 'quantity' => 2, 'unit_cost' => '50.00']]
        );

        $executor = app(ToolExecutor::class);
        $sales = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($sales),
            new AiToolCall('1', 'searchPurchaseOrders', [
                'supplier_name' => 'Tool PO',
                'limit' => 10,
            ])
        );

        $this->assertTrue($result->success);
        $encoded = json_encode($result->data);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
        $this->assertStringNotContainsString('freight_cost', $encoded);
    }

    public function test_search_purchase_orders_rejects_invalid_date_range(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'searchPurchaseOrders', [
                'date_from' => '2026-12-01',
                'date_to' => '2026-01-01',
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('validation_error', $result->errorCode);
    }

    public function test_search_purchase_orders_by_id_returns_status(): void
    {
        $supplier = $this->createSupplierWithName('Tool Id PO Supplier');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-15',
            '2026-06-30',
            '500.00',
            [['product' => $product, 'quantity' => 3, 'unit_cost' => '100.00']]
        );

        $executor = app(ToolExecutor::class);
        $sales = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($sales),
            new AiToolCall('1', 'searchPurchaseOrders', [
                'purchase_order_id' => $order->id,
            ])
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['meta']['total'] ?? null);
        $this->assertSame($order->id, $result->data['data'][0]['purchase_order_id'] ?? null);
        $this->assertSame('received', $result->data['data'][0]['status'] ?? null);

        $encoded = json_encode($result->data);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('unit_cost', $encoded);
    }

    public function test_search_purchase_orders_by_missing_id_returns_not_found(): void
    {
        $executor = app(ToolExecutor::class);
        $purchasing = $this->createPurchasingApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($purchasing),
            new AiToolCall('1', 'searchPurchaseOrders', [
                'purchase_order_id' => 9_999_999,
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);
    }

    public function test_find_overdue_preserves_service_rules(): void
    {
        $supplier = $this->createSupplierWithName('Overdue Supplier');
        $product = $this->createCalacattaThreeCmDemoProduct();

        $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-01-01',
            '2026-01-05',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Draft,
            '2026-01-01',
            '2026-01-05',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']]
        );

        $executor = app(ToolExecutor::class);
        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'findOverduePurchaseOrders', [
                'as_of' => '2026-02-01',
            ])
        );

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['orders'] ?? []);
        $this->assertSame('ordered', $result->data['orders'][0]['status']);
    }

    public function test_get_landed_cost_denies_sales(): void
    {
        $supplier = $this->createSupplierWithName('LC Supplier');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '100.00',
            [['product' => $product, 'quantity' => 2, 'unit_cost' => '50.00']]
        );

        $executor = app(ToolExecutor::class);
        $sales = User::factory()->create();
        $sales->assignRole(UserRole::Sales);

        $result = $executor->execute(
            new ToolExecutionContext($sales),
            new AiToolCall('1', 'getLandedCost', ['purchase_order_id' => $order->id])
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
    }

    public function test_get_landed_cost_allows_purchasing(): void
    {
        $supplier = $this->createSupplierWithName('LC Supplier 2');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '100.00',
            [['product' => $product, 'quantity' => 2, 'unit_cost' => '50.00']]
        );

        $executor = app(ToolExecutor::class);
        $purchasing = $this->createPurchasingApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($purchasing),
            new AiToolCall('1', 'getLandedCost', ['purchase_order_id' => $order->id])
        );

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('lines', $result->data ?? []);
    }

    public function test_get_landed_cost_missing_po_returns_safe_error(): void
    {
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'getLandedCost', ['purchase_order_id' => 999999])
        );

        $this->assertFalse($result->success);
        $this->assertSame('not_found_or_invalid', $result->errorCode);
    }

    public function test_propose_draft_requires_trusted_context(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Proposal Supplier Tool');

        $executor = app(ToolExecutor::class);
        $purchasing = $this->createPurchasingApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($purchasing),
            new AiToolCall('1', 'proposeDraftPurchaseOrder', [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '10.00',
                'lines' => [
                    $this->proposalLine($product, 1, '20.00'),
                ],
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('missing_trusted_context', $result->errorCode);
    }

    public function test_propose_draft_creates_proposal_only_for_purchasing(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Proposal Supplier Tool 2');

        $executor = app(ToolExecutor::class);
        $purchasing = $this->createPurchasingApiUser();

        $result = $executor->execute(
            new ToolExecutionContext(
                $purchasing,
                conversationKey: 'trusted-conv-001',
                idempotencyKey: 'trusted-idem-001',
            ),
            new AiToolCall('1', 'proposeDraftPurchaseOrder', [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '10.00',
                'lines' => [
                    $this->proposalLine($product, 2, '25.00'),
                ],
            ])
        );

        $this->assertTrue($result->success);
        $this->assertSame('pending', $result->data['status'] ?? null);

        $this->assertSame(1, PurchaseOrderProposal::count());
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderLine::count());

        $encoded = json_encode($result->data);
        $this->assertStringNotContainsString('bank_details', $encoded);
    }

    public function test_propose_draft_denies_sales_even_with_context(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Proposal Supplier Tool 3');
        $sales = User::factory()->create();
        $sales->assignRole(UserRole::Sales);

        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext(
                $sales,
                conversationKey: 'trusted-conv',
                idempotencyKey: 'trusted-idem',
            ),
            new AiToolCall('1', 'proposeDraftPurchaseOrder', [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '10.00',
                'lines' => [
                    $this->proposalLine($product, 1, '20.00'),
                ],
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
    }

    public function test_check_stock_houston_calacatta_returns_42_with_canonical_args(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
            ])
        );

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_check_stock_calacatta_quartz_material_openai_legacy_returns_austin_9(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('cq', 'checkStock', [
                'material' => 'Calacatta Quartz',
                'thickness_cm' => '3',
                'warehouse_name' => 'Austin',
            ]),
            'openai',
        );

        $this->assertTrue($result->success);
        $this->assertSame(9, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_check_stock_unmatched_product_returns_failure_not_success(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('x', 'checkStock', [
                'material' => 'Granite',
                'color' => 'NonexistentColor',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Dallas',
            ]),
            'openai',
        );

        $this->assertFalse($result->success);
        $this->assertSame('product_not_found', $result->errorCode);
    }

    public function test_check_stock_austin_calacatta_without_material_returns_stock_levels(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);

        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('austin', 'checkStock', [
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Austin',
            ]),
            'openai',
        );

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['multi_product'] ?? false);
        $levels = $result->data['stock_levels'] ?? [];
        $this->assertCount(2, $levels);
        $this->assertSame(9, collect($levels)->firstWhere('product.sku', 'DEMO-STQ-CAL-30')['slabs_on_hand'] ?? null);
        $this->assertSame(7, collect($levels)->firstWhere('product.sku', 'DEMO-MAR-CAL-30')['slabs_on_hand'] ?? null);
    }

    public function test_check_stock_dallas_follow_up_args_return_18(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('2', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => 3,
                'warehouse_name' => 'Dallas',
            ])
        );

        $this->assertTrue($result->success);
        $this->assertSame(18, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_check_stock_accepts_thickness_with_cm_suffix(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'color' => 'Calacatta',
                'thickness_cm' => '3cm',
                'warehouse_name' => 'Houston',
            ]),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_check_stock_wrong_material_granite_still_returns_houston_42(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Granite',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
            ]),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['found'] ?? false);
        if (($result->data['multi_product'] ?? false) === true) {
            $this->assertSame(42, collect($result->data['stock_levels'] ?? [])->max('slabs_on_hand'));
        } else {
            $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
        }
    }

    public function test_search_products_ignores_unknown_warehouse_key(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'searchProducts', [
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
            ]),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(1, $result->data['count'] ?? 0);
    }

    public function test_check_stock_material_misplaced_calacatta_coerces_to_color(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Calacatta',
                'warehouse_name' => 'Houston',
            ]),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['found'] ?? false);
        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_check_stock_conflicting_warehouse_filters_rejected(): void
    {
        $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
                'warehouse_city' => 'Dallas',
            ]),
            'ollama',
        );

        $this->assertFalse($result->success);
        $this->assertSame('validation_error', $result->errorCode);
    }

    public function test_search_products_returns_real_ids_for_calacatta(): void
    {
        $scenario = $this->seedCalacattaStockScenario();
        $executor = app(ToolExecutor::class);

        $result = $executor->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'searchProducts', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
            ]),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertSame($scenario['product']->id, $result->data['products'][0]['id'] ?? null);
    }

    public function test_search_suppliers_denies_sales(): void
    {
        $this->createSupplierWithName('Gulf Stone');

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'searchSuppliers', ['name' => 'Gulf']),
            'ollama',
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
    }

    public function test_search_suppliers_finds_id_without_bank_details(): void
    {
        $supplier = $this->createSupplierWithName('Gulf Stone Suppliers');

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'searchSuppliers', ['name' => 'Gulf Stone']),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertSame($supplier->id, $result->data['suppliers'][0]['id'] ?? null);
        $encoded = json_encode($result->data);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('bank_details', $encoded);
    }

    public function test_get_supplier_bank_details_returns_decrypted_details_for_purchasing(): void
    {
        $supplier = $this->createSupplierWithName('Gulf Stone Imports Wire');
        $supplier->bank_details = 'Demo Bank — Account DEMO-WIRE-001';
        $supplier->save();

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'getSupplierBankDetails', ['name' => 'Gulf Stone Imports']),
            'openai',
        );

        $this->assertTrue($result->success);
        $this->assertSame('Demo Bank — Account DEMO-WIRE-001', $result->data['supplier']['bank_details'] ?? null);
    }

    public function test_get_supplier_bank_details_denied_for_sales(): void
    {
        $this->createSupplierWithName('Sales Blocked Bank Supplier');

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'getSupplierBankDetails', ['name' => 'Sales Blocked']),
            'openai',
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
    }

    public function test_propose_draft_rejects_hallucinated_product_id(): void
    {
        $supplier = $this->createSupplierWithName('Hallucination Supplier');
        $warehouse = Warehouse::query()->create(['name' => 'Hallucination WH', 'city' => 'Hallucination WH']);

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext(
                $this->createPurchasingApiUser(),
                conversationKey: 'conv-hall',
                idempotencyKey: 'idem-hall',
            ),
            new AiToolCall('1', 'proposeDraftPurchaseOrder', [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '10.00',
                'lines' => [
                    [
                        'product_id' => 999999,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => 1,
                        'unit_cost' => '20.00',
                    ],
                ],
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame(0, PurchaseOrder::count());
    }
}
