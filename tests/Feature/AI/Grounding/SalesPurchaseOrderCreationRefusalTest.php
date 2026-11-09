<?php

namespace Tests\Feature\AI\Grounding;

use App\Models\PurchaseOrderProposal;
use App\Models\Supplier;
use App\Services\AI\Grounding\PurchaseOrderCreationIntentDetector;
use App\Services\AI\Grounding\SalesPurchaseOrderCreationRefusal;
use App\Services\AI\Tools\ToolExecutor;
use App\DTOs\AI\AiToolCall;
use App\Services\AI\Tools\ToolExecutionContext;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 16.13 — Sales soft denial for PO creation; read-only PO behaviour unchanged.
 */
class SalesPurchaseOrderCreationRefusalTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_intent_detector_matches_creation_not_read_search(): void
    {
        $detector = app(PurchaseOrderCreationIntentDetector::class);

        $this->assertTrue($detector->isPurchaseOrderCreationRequest(
            'Create a draft PO for 20 slabs of 2cm Carrara from Supplier Y.',
        ));
        $this->assertTrue($detector->isPurchaseOrderCreationRequest(
            'Create a purchase order for 10 slabs of Carrara.',
        ));
        $this->assertFalse($detector->isPurchaseOrderCreationRequest(
            'Show me the draft purchase orders',
        ));
        $this->assertFalse($detector->isPurchaseOrderCreationRequest(
            'What is the status of purchase order PO-507?',
        ));
        $this->assertFalse($detector->isPurchaseOrderCreationRequest(
            'Show me all the purchase orders for Brazos Marble LLC',
        ));
    }

    public function test_sales_po_refusal_precedes_ambiguous_calacatta_clarification(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(SupplierSeeder::class);

        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_sales_po_creation_refusal', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Create a draft PO for 5 slabs of Calacatta from Demo Supplier — Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertSame(SalesPurchaseOrderCreationRefusal::REFUSAL_MESSAGE, $json['data']['message'] ?? null);
        $this->assertFalse($json['data']['clarification_required'] ?? true);
    }

    public function test_sales_api_receives_soft_denial_without_proposal_or_provider(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_sales_po_creation_refusal', true);
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Create a draft PO for 20 slabs of 2cm Carrara from Supplier Y.',
        ])->assertOk()->json();

        $this->assertSame(SalesPurchaseOrderCreationRefusal::REFUSAL_MESSAGE, $json['data']['message'] ?? null);
        $this->assertSame(0, $json['data']['tool_calls'] ?? -1);
        $this->assertArrayHasKey('provider', $json['data']);
        $this->assertTrue($json['data']['completed'] ?? false);
        $this->assertSame(0, PurchaseOrderProposal::count());
    }

    public function test_sales_still_lists_purchase_orders_after_creation_refusal_exists(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(PurchaseOrderSeeder::class);

        Config::set('ai.assistant.enforce_sales_po_creation_refusal', true);
        Config::set('ai.assistant.enforce_purchase_order_search_disclosure', true);
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-key');

        OpenAI::fake([]);

        $supplier = Supplier::query()->where('name', 'like', '%Brazos Marble%')->firstOrFail();

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Show me all the purchase orders for '.$supplier->name,
        ])->assertOk()->json();

        $message = (string) ($json['data']['message'] ?? '');
        $this->assertStringContainsString('Purchase orders', $message);
        $this->assertStringContainsString('Brazos Marble', $message);
    }

    public function test_sales_cannot_execute_propose_tool_directly(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Direct Tool Supplier');

        $result = app(ToolExecutor::class)->execute(
            new ToolExecutionContext(
                $this->createSalesApiUser(),
                conversationKey: 'conv-sales-block',
                idempotencyKey: 'idem-sales-block',
            ),
            new AiToolCall('1', 'proposeDraftPurchaseOrder', [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-11-06',
                'freight_cost' => '0.00',
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '1.00'],
                ],
            ]),
        );

        $this->assertFalse($result->success);
        $this->assertSame('permission_denied', $result->errorCode);
        $this->assertSame(0, PurchaseOrderProposal::count());
    }
}
