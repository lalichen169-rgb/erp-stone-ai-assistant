<?php

namespace Tests\Feature\Erp;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Stock;
use App\Services\AI\Tools\Definitions\ResolveWarehouseForPurchaseTool;
use App\Services\AI\Tools\Definitions\SearchWarehousesTool;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\Erp\DraftPurchaseOrderService;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use App\Services\Erp\ReplenishmentQuantityService;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ProcurementWarehouseDestinationTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_missing_destination_rejects_proposal_when_required(): void
    {
        Config::set('erp.procurement.require_line_destination_warehouse', true);
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('WH Supplier');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $this->expectException(PurchaseOrderProposalException::class);
        $service->createProposal($user, 'conv-wh-miss', 'idem-wh-miss', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'unit_cost' => '50.00',
                ],
            ],
        ]);
    }

    public function test_houston_destination_stored_on_proposal_and_confirmed_po_line(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $warehouses = $this->createHoustonDallasAustinWarehouses();
        $supplier = $this->createSupplierWithName('Houston Supplier');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $stockBefore = Stock::query()->sum('slabs_on_hand');

        $proposal = $service->createProposal($user, 'conv-hou', 'idem-hou', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($product, 10, '50.00', $warehouses['houston']),
            ],
        ]);

        $this->assertSame($warehouses['houston']->id, $proposal['lines'][0]['warehouse_id']);
        $this->assertSame('Houston', $proposal['lines'][0]['warehouse_name']);

        $confirmed = $service->confirmProposal($user, $proposal['proposal_id'], 'conv-hou');
        $line = PurchaseOrderLine::query()->where('purchase_order_id', $confirmed['purchase_order_id'])->first();

        $this->assertNotNull($line);
        $this->assertSame($warehouses['houston']->id, $line->warehouse_id);
        $this->assertSame(10, $line->quantity);
        $this->assertSame($stockBefore, Stock::query()->sum('slabs_on_hand'));
    }

    public function test_multi_warehouse_lines_in_single_proposal(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $warehouses = $this->createHoustonDallasAustinWarehouses();
        $supplier = $this->createSupplierWithName('Multi WH Supplier');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $proposal = $service->createProposal($user, 'conv-multi', 'idem-multi', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                $this->proposalLine($product, 10, '50.00', $warehouses['houston']),
                $this->proposalLine($product, 15, '50.00', $warehouses['dallas']),
            ],
        ]);

        $this->assertCount(2, $proposal['lines']);
        $this->assertSame(10, $proposal['lines'][0]['quantity']);
        $this->assertSame(15, $proposal['lines'][1]['quantity']);
        $this->assertSame($warehouses['houston']->id, $proposal['lines'][0]['warehouse_id']);
        $this->assertSame($warehouses['dallas']->id, $proposal['lines'][1]['warehouse_id']);

        $confirmed = $service->confirmProposal($user, $proposal['proposal_id'], 'conv-multi');
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $confirmed['purchase_order_id'])->get();
        $this->assertCount(2, $lines);
    }

    public function test_invalid_warehouse_id_rejected(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Bad WH Supplier');
        $user = $this->createPurchasingApiUser();
        $service = app(DraftPurchaseOrderService::class);

        $this->expectException(PurchaseOrderProposalException::class);
        $service->createProposal($user, 'conv-bad-wh', 'idem-bad-wh', [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-09',
            'freight_cost' => '0',
            'lines' => [
                array_replace($this->proposalLine($product), ['warehouse_id' => 999999]),
            ],
        ]);
    }

    public function test_houston_replenishment_ignores_dallas_stock(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $warehouses = $this->createHoustonDallasAustinWarehouses();
        Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouses['houston']->id,
            'slabs_on_hand' => 5,
        ]);
        Stock::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouses['dallas']->id,
            'slabs_on_hand' => 40,
        ]);

        $user = $this->createPurchasingApiUser();
        $calc = app(ReplenishmentQuantityService::class)->calculateShortageAtWarehouse(
            $user,
            $product->id,
            $warehouses['houston']->id,
            50,
        );

        $this->assertSame(5, $calc['current_stock_on_hand']);
        $this->assertSame(45, $calc['shortage_quantity']);
    }

    public function test_resolve_warehouse_tool_unique_match(): void
    {
        $warehouses = $this->createHoustonDallasAustinWarehouses();
        $tool = app(ResolveWarehouseForPurchaseTool::class);
        $user = $this->createPurchasingApiUser();

        $result = $tool->execute(new ToolExecutionContext($user), ['name' => 'Houston']);
        $this->assertTrue($result->success);
        $this->assertSame('unique_match', $result->data['status']);
        $this->assertSame($warehouses['houston']->id, $result->data['warehouse_id']);
    }

    public function test_search_warehouses_lists_database_records(): void
    {
        $this->createHoustonDallasAustinWarehouses();
        $tool = app(SearchWarehousesTool::class);
        $user = $this->createPurchasingApiUser();

        $result = $tool->execute(new ToolExecutionContext($user), []);
        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(3, $result->data['count']);
    }

    public function test_sales_cannot_use_warehouse_procurement_tools(): void
    {
        $sales = $this->createSalesApiUser();
        $this->assertFalse(app(SearchWarehousesTool::class)->allowedFor($sales));
        $this->assertFalse(app(ResolveWarehouseForPurchaseTool::class)->allowedFor($sales));
    }

    public function test_historical_po_line_without_warehouse_remains_valid(): void
    {
        $product = Product::query()->create([
            'sku' => 'HIST-1',
            'name' => 'Historical',
            'material' => 'Marble',
            'color' => 'Hist',
            'thickness_cm' => '2.00',
            'slab_size' => 'x',
        ]);
        $supplier = $this->createSupplierWithName('Hist Supplier');
        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'status' => \App\Enums\PurchaseOrderStatus::Draft,
            'order_date' => '2026-01-01',
            'eta' => null,
            'freight_cost' => '0',
        ]);

        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => '1.00',
        ]);

        $this->assertNull($line->warehouse_id);
        $this->assertNull($line->fresh()->warehouse_id);
    }
}
