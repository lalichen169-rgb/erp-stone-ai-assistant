<?php

namespace Tests\Feature\Erp;

use App\Models\Product;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\AI\Tools\Definitions\CalculateReplenishmentQuantityTool;
use App\Services\AI\Tools\Definitions\ResolveProductsForPurchaseTool;
use App\Services\AI\Tools\Definitions\ValidateSupplierProductOfferingTool;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\Erp\DraftPurchaseOrderService;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use App\Services\Erp\ProductCatalogResolutionService;
use App\Services\Erp\ReplenishmentQuantityService;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ProcurementBusinessRulesTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_unique_product_resolution_by_sku(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $resolution = app(ProductCatalogResolutionService::class)->resolve(['sku' => $product->sku]);

        $this->assertSame('unique_match', $resolution['status']);
        $this->assertSame($product->id, $resolution['product_id']);
    }

    public function test_ambiguous_product_requires_clarification(): void
    {
        Product::query()->create([
            'sku' => 'TEST-MAR-CAR-20',
            'name' => 'Carrara Marble Slab',
            'material' => 'Marble',
            'color' => 'Carrara',
            'thickness_cm' => '2.00',
            'slab_size' => '320x160 cm',
        ]);
        Product::query()->create([
            'sku' => 'TEST-STQ-CAR-30',
            'name' => 'Carrara Quartz Slab',
            'material' => 'Quartz',
            'color' => 'Carrara',
            'thickness_cm' => '3.00',
            'slab_size' => '320x160 cm',
        ]);

        $resolution = app(ProductCatalogResolutionService::class)->resolve([
            'color' => 'Carrara',
        ]);

        $this->assertSame('ambiguous_product', $resolution['status']);
        $this->assertTrue($resolution['clarification_required']);
        $this->assertNull($resolution['product_id']);
        $this->assertGreaterThanOrEqual(2, count($resolution['alternatives']));
    }

    public function test_missing_thickness_when_multiple_thicknesses_match(): void
    {
        Product::query()->create([
            'sku' => 'SKU-A',
            'name' => 'Variety A',
            'material' => 'Marble',
            'color' => 'VarietyA',
            'thickness_cm' => '2.00',
            'slab_size' => 'x',
        ]);
        Product::query()->create([
            'sku' => 'SKU-B',
            'name' => 'Variety A Thick',
            'material' => 'Marble',
            'color' => 'VarietyA',
            'thickness_cm' => '3.00',
            'slab_size' => 'x',
        ]);

        $resolution = app(ProductCatalogResolutionService::class)->resolve([
            'color' => 'VarietyA',
            'material' => 'Marble',
        ]);

        $this->assertSame('ambiguous_product', $resolution['status']);
        $this->assertSame('missing_thickness', $resolution['clarification_reason']);
    }

    public function test_material_not_satisfied_does_not_fallback_silently(): void
    {
        $product = Product::query()->create([
            'sku' => 'SKU-ONLY-MARBLE',
            'name' => 'Only Marble',
            'material' => 'Marble',
            'color' => 'TestColor',
            'thickness_cm' => '2.00',
            'slab_size' => 'x',
        ]);

        $resolution = app(ProductCatalogResolutionService::class)->resolve([
            'color' => 'TestColor',
            'material' => 'Quartz',
        ]);

        $this->assertSame('unmatched_attribute', $resolution['status']);
        $this->assertNull($resolution['product_id']);
        $this->assertNotSame($product->id, $resolution['product_id']);
    }

    public function test_direct_po_quantity_preserved_in_proposal(): void
    {
        ['product' => $product, 'warehouses' => $warehouses] = $this->seedCalacattaStockScenario();
        $supplier = $this->createSupplierWithName('Qty Supplier');

        $service = app(DraftPurchaseOrderService::class);
        $user = $this->createPurchasingApiUser();

        $result = $service->createProposal($user, 'conv-qty', 'idem-qty', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($product, 10, '50.00', $warehouses['houston']),
            ],
        ]);

        $this->assertSame(10, $result['lines'][0]['quantity']);
    }

    public function test_replenishment_shortage_does_not_change_direct_request_semantics(): void
    {
        ['product' => $product] = $this->seedCalacattaStockScenario();
        $user = $this->createPurchasingApiUser();
        $calc = app(ReplenishmentQuantityService::class)->calculateShortage($user, $product->id, 20);

        $this->assertTrue($calc['success']);
        $this->assertSame(69, $calc['current_stock_on_hand']);
        $this->assertSame(0, $calc['shortage_quantity']);
    }

    public function test_replenishment_calculates_shortage_from_on_hand_stock(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $warehouses = $this->createHoustonDallasAustinWarehouses();
        \App\Models\Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouses['houston']->id,
            'slabs_on_hand' => 5,
        ]);

        $user = $this->createPurchasingApiUser();
        $calc = app(ReplenishmentQuantityService::class)->calculateShortageAtWarehouse(
            $user,
            $product->id,
            $warehouses['houston']->id,
            12,
        );

        $this->assertSame(5, $calc['current_stock_on_hand']);
        $this->assertSame(7, $calc['shortage_quantity']);
    }

    public function test_enforced_supplier_product_mapping_blocks_incompatible_proposal(): void
    {
        Config::set('erp.procurement.enforce_supplier_product_catalog', true);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('No Mapping Supplier');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $this->expectException(PurchaseOrderProposalException::class);
        $service->createProposal($user, 'conv-map', 'idem-map', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '10',
            'lines' => [
                $this->proposalLine($product, 2, '99.00'),
            ],
        ]);
    }

    public function test_catalog_unit_cost_replaces_mismatched_manual_price_when_configured(): void
    {
        Config::set('erp.procurement.enforce_supplier_product_catalog', true);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Catalog Supplier');
        $this->createActiveSupplierProductMapping($supplier, $product, '210.00');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $this->expectException(PurchaseOrderProposalException::class);
        $service->createProposal($user, 'conv-price', 'idem-price', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($product, 1, '50.00'),
            ],
        ]);
    }

    public function test_catalog_unit_cost_applied_when_matching_active_mapping(): void
    {
        Config::set('erp.procurement.enforce_supplier_product_catalog', true);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Catalog Supplier OK');
        $mapping = $this->createActiveSupplierProductMapping($supplier, $product, '210.00');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $result = $service->createProposal($user, 'conv-price-ok', 'idem-price-ok', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($product, 2, '210.00'),
            ],
        ]);

        $this->assertSame('210.00', $result['lines'][0]['unit_cost']);
        $this->assertSame('supplier_product_catalog', $result['lines'][0]['unit_cost_source']);
        $this->assertSame($mapping->id, $result['lines'][0]['supplier_product_id']);
    }

    public function test_inactive_supplier_product_rejected_when_enforced(): void
    {
        Config::set('erp.procurement.enforce_supplier_product_catalog', true);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Inactive Map Supplier');
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'unit_cost' => '100.00',
            'is_active' => false,
        ]);
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $this->expectException(PurchaseOrderProposalException::class);
        $service->createProposal($user, 'conv-inact', 'idem-inact', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($product, 1, '100.00'),
            ],
        ]);
    }

    public function test_resolve_products_tool_returns_ambiguous_status(): void
    {
        Product::query()->create([
            'sku' => 'T1', 'name' => 'N1', 'material' => 'Marble', 'color' => 'X', 'thickness_cm' => '2.00', 'slab_size' => 'a',
        ]);
        Product::query()->create([
            'sku' => 'T2', 'name' => 'N2', 'material' => 'Marble', 'color' => 'X', 'thickness_cm' => '3.00', 'slab_size' => 'a',
        ]);

        $tool = app(ResolveProductsForPurchaseTool::class);
        $user = $this->createPurchasingApiUser();
        $result = $tool->execute(new ToolExecutionContext($user), ['color' => 'X', 'material' => 'Marble']);

        $this->assertTrue($result->success);
        $this->assertSame('ambiguous_product', $result->data['status']);
    }

    public function test_validate_supplier_product_tool_reports_compatibility(): void
    {
        Config::set('erp.procurement.enforce_supplier_product_catalog', true);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Validate Supplier');
        $this->createActiveSupplierProductMapping($supplier, $product, '15.00');

        $tool = app(ValidateSupplierProductOfferingTool::class);
        $user = $this->createPurchasingApiUser();
        $result = $tool->execute(new ToolExecutionContext($user), [
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
        ]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['compatible_for_proposal']);
        $this->assertSame('15.00', $result->data['catalog_unit_cost']);
    }

    public function test_replenishment_tool_requires_explicit_target(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $tool = app(CalculateReplenishmentQuantityTool::class);
        $user = $this->createPurchasingApiUser();

        $warehouse = $this->createHoustonDallasAustinWarehouses()['houston'];
        $bad = $tool->execute(new ToolExecutionContext($user), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'target_quantity' => 0,
        ]);
        $this->assertFalse($bad->success);
    }

    public function test_sales_cannot_execute_procurement_tools(): void
    {
        $user = $this->createSalesApiUser();
        $resolve = app(ResolveProductsForPurchaseTool::class);
        $this->assertFalse($resolve->allowedFor($user));
    }
}
