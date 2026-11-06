<?php

namespace Tests\Feature\AI\Tools;

use App\DTOs\AI\AiToolCall;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ToolLayerTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_registry_lists_five_tools_and_filters_by_role(): void
    {
        $registry = app(ToolRegistry::class);
        $names = $registry->registeredNames();
        sort($names);

        $this->assertSame([
            'checkStock',
            'findOverduePurchaseOrders',
            'getLandedCost',
            'proposeDraftPurchaseOrder',
            'searchPurchaseOrders',
        ], $names);

        $sales = $this->createSalesApiUser();
        $salesDefs = $registry->definitionsFor($sales);
        $this->assertCount(3, $salesDefs);
        $this->assertSame(
            ['checkStock', 'findOverduePurchaseOrders', 'searchPurchaseOrders'],
            collect($salesDefs)->pluck('name')->sort()->values()->all()
        );

        $purchasing = $this->createPurchasingApiUser();
        $purchaseDefs = $registry->definitionsFor($purchasing);
        $this->assertCount(5, $purchaseDefs);
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

    public function test_unknown_extra_argument_keys_are_rejected_with_validation_error(): void
    {
        $executor = app(ToolExecutor::class);
        $user = $this->createSalesApiUser();

        $result = $executor->execute(
            new ToolExecutionContext($user),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'warehouse_name' => 'Houston',
                'unexpected_key' => 'x',
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('validation_error', $result->errorCode);
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
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '20.00'],
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
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '25.00'],
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
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '20.00'],
                ],
            ])
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
    }
}
