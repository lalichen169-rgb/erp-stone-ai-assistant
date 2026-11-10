<?php

namespace Tests\Feature\AI\Grounding;

use App\DTOs\AI\AiToolCall;
use App\Enums\PurchaseOrderStatus;
use App\Services\AI\Grounding\OverduePurchaseOrderSearchReply;
use App\Services\AI\Grounding\SalesPurchaseOrderCreationRefusal;
use App\Services\AI\Grounding\SalesSupplierOverduePurchaseOrderAnalysisRefusal;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\Erp\OverduePurchaseOrderService;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class SupplierOverduePurchaseOrderPhase16_22Test extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_purchasing_supplier_overdue_service_filter(): void
    {
        $this->seed(SupplierSeeder::class);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $gulf = $this->createSupplierWithName('Demo Supplier — Gulf Stone Imports');
        $brazos = $this->createSupplierWithName('Brazos Marble LLC');
        $today = Carbon::parse('2026-11-06');

        $overdueGulf = $this->createPurchaseOrderWithLines(
            $gulf,
            PurchaseOrderStatus::Ordered,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']],
        );

        $this->createPurchaseOrderWithLines(
            $brazos,
            PurchaseOrderStatus::Ordered,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']],
        );

        $this->createPurchaseOrderWithLines(
            $gulf,
            PurchaseOrderStatus::Received,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']],
        );

        $service = app(OverduePurchaseOrderService::class);
        $user = $this->createPurchasingApiUser();

        $scoped = $service->listOverdue($user, $today, 100, $gulf->id);
        $this->assertCount(1, $scoped);
        $this->assertSame($overdueGulf->id, $scoped[0]['purchase_order_id']);

        $all = $service->listOverdue($user, $today);
        $this->assertCount(2, $all);
    }

    public function test_purchasing_deterministic_reply_for_supplier_delayed_pos(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $gulf = $this->createSupplierWithName('Acme Gulf Stone Imports 1622');
        Carbon::setTestNow('2026-11-06');

        $this->createPurchaseOrderWithLines(
            $gulf,
            PurchaseOrderStatus::Ordered,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']],
        );

        Config::set('ai.assistant.enforce_overdue_purchase_order_disclosure', true);

        $reply = app(OverduePurchaseOrderSearchReply::class)->tryBuildReply(
            $this->createPurchasingApiUser(),
            'Which purchase orders from Acme Gulf Stone Imports 1622 are delayed?',
            'Which purchase orders from Acme Gulf Stone Imports 1622 are delayed?',
        );

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Acme Gulf Stone Imports 1622', $reply);
        $this->assertStringContainsString('overdue', strtolower($reply));
        $this->assertStringContainsString('PO #', $reply);
    }

    public function test_sales_supplier_delayed_question_receives_soft_denial(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-key');
        Config::set('ai.assistant.enforce_sales_supplier_overdue_po_analysis_refusal', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Which purchase orders from Brazos Marble LLC are delayed?',
        ])->assertOk()->json();

        $this->assertSame(
            SalesSupplierOverduePurchaseOrderAnalysisRefusal::REFUSAL_MESSAGE,
            $json['data']['message'] ?? null,
        );
        $this->assertSame(0, $json['data']['tool_calls'] ?? -1);
    }

    public function test_sales_tool_supplier_overdue_filter_denied(): void
    {
        $executor = app(ToolExecutor::class);
        $result = $executor->execute(
            new ToolExecutionContext($this->createSalesApiUser()),
            new AiToolCall('1', 'findOverduePurchaseOrders', [
                'supplier_name' => 'Gulf Stone Imports',
            ]),
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
    }

    public function test_sales_individual_po_status_still_works(): void
    {
        $this->seed(SupplierSeeder::class);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Status Supplier');
        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Ordered,
            '2026-11-01',
            '2026-12-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']],
        );

        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-key');
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);
        Config::set('ai.assistant.enforce_sales_supplier_overdue_po_analysis_refusal', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'What is the status of purchase order PO-'.$order->id.'?',
        ])->assertOk()->json();

        $message = (string) ($json['data']['message'] ?? '');
        $this->assertStringContainsString((string) $order->id, $message);
        $this->assertStringContainsString('ordered', strtolower($message));
    }

    public function test_sales_po_creation_refusal_still_works(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-key');
        Config::set('ai.assistant.enforce_sales_po_creation_refusal', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Create a draft PO for 20 slabs of 2cm Carrara from Supplier Y.',
        ])->assertOk()->json();

        $this->assertSame(SalesPurchaseOrderCreationRefusal::REFUSAL_MESSAGE, $json['data']['message'] ?? null);
    }

    public function test_purchasing_tool_accepts_supplier_name_filter(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $gulf = $this->createSupplierWithName('Acme Gulf Stone Imports 1622B');
        $this->createPurchaseOrderWithLines(
            $gulf,
            PurchaseOrderStatus::Ordered,
            '2026-09-01',
            '2026-10-01',
            '0.00',
            [['product' => $product, 'quantity' => 1, 'unit_cost' => '10.00']],
        );

        $executor = app(ToolExecutor::class);
        $result = $executor->execute(
            new ToolExecutionContext($this->createPurchasingApiUser()),
            new AiToolCall('1', 'findOverduePurchaseOrders', [
                'supplier_name' => 'Acme Gulf Stone Imports 1622B',
                'as_of' => '2026-11-06',
            ]),
        );

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['orders'] ?? []);
        $this->assertStringContainsString('1622B', (string) ($result->data['orders'][0]['supplier_name'] ?? ''));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
