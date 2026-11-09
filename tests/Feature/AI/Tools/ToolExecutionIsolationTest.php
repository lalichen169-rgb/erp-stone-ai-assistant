<?php

namespace Tests\Feature\AI\Tools;

use App\DTOs\AI\AiToolCall;
use App\Enums\PurchaseOrderStatus;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutor;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ToolExecutionIsolationTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_openai_rejects_search_products_by_name(): void
    {
        $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'searchProducts', ['material' => 'Quartz']),
            'openai',
        );

        $this->assertFalse($result->success);
        $this->assertSame('unknown_tool', $result->errorCode);
    }

    public function test_claude_executes_search_suppliers_for_purchasing(): void
    {
        $this->createSupplierWithName('Gulf Stone');
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'searchSuppliers', ['name' => 'Gulf']),
            'claude',
        );

        $this->assertTrue($result->success);
        $this->assertStringNotContainsString('bank', json_encode($result->data));
    }

    public function test_ollama_executes_search_products_for_sales(): void
    {
        $scenario = $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
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

    public function test_openai_check_stock_legacy_does_not_reject_thickness_cm_suffix(): void
    {
        $this->seedCalacattaStockScenario();
        $legacy = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3cm',
                'warehouse_name' => 'Houston',
            ]),
            'openai',
        );

        $this->assertNotSame('validation_error', $legacy->errorCode);
    }

    public function test_ollama_check_stock_accepts_thickness_cm_suffix(): void
    {
        $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3cm',
                'warehouse_name' => 'Houston',
            ]),
            'ollama',
        );

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_openai_legacy_houston_stock_unchanged(): void
    {
        $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
            ]),
            'openai',
        );

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->data['slabs_on_hand'] ?? null);
    }

    public function test_ollama_conflicting_warehouse_filters_rejected(): void
    {
        $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
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

    public function test_openai_legacy_ignores_conflicting_warehouse_pair(): void
    {
        $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'thickness_cm' => '3.00',
                'warehouse_name' => 'Houston',
                'warehouse_city' => 'Dallas',
            ]),
            'openai',
        );

        $this->assertNotSame(
            'warehouse_name and warehouse_city do not match any single warehouse',
            $result->errorMessage ?? '',
        );
    }

    public function test_sales_cannot_execute_get_landed_cost_on_any_provider(): void
    {
        foreach (['openai', 'claude', 'ollama'] as $provider) {
            $result = app(ToolExecutor::class)->execute(
                new ToolExecutionContext($this->createSalesApiUser()),
                new AiToolCall('1', 'getLandedCost', ['purchase_order_id' => 1]),
                $provider,
            );

            $this->assertFalse($result->success, "Expected denial for provider {$provider}");
            $this->assertSame('permission_denied', $result->errorCode);
        }
    }

    public function test_sales_cannot_execute_search_suppliers_even_on_ollama(): void
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

    public function test_purchasing_can_execute_get_landed_cost_on_ollama(): void
    {
        $supplier = $this->createSupplierWithName('Isolation LC Supplier');
        $product = $this->createCalacattaThreeCmDemoProduct();
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            null,
            '100.00',
            [['product' => $product, 'quantity' => 2, 'unit_cost' => '50.00']],
        );

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'getLandedCost', ['purchase_order_id' => $order->id]),
            'ollama',
        );

        $this->assertTrue($result->success);
    }

    public function test_openai_rejects_hidden_search_products_even_for_purchasing(): void
    {
        $this->seedCalacattaStockScenario();
        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'searchProducts', ['material' => 'Quartz']),
            'openai',
        );

        $this->assertFalse($result->success);
        $this->assertSame('unknown_tool', $result->errorCode);
    }
}
