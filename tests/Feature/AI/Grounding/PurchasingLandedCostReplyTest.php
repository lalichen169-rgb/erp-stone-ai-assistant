<?php

namespace Tests\Feature\AI\Grounding;

use App\Enums\PurchaseOrderStatus;
use App\Services\AI\Grounding\PurchasingLandedCostReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SupplierSeeder;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class PurchasingLandedCostReplyTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_formatted_reply_includes_freight_line_total_and_po_total(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);

        $supplier = \App\Models\Supplier::query()->where('name', 'like', '%Central Texas%')->firstOrFail();
        $product = \App\Models\Product::query()->where('sku', 'DEMO-GRN-BLK-30')->firstOrFail();

        $order = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-15',
            '2026-06-30',
            '1100.00',
            [
                ['product' => $product, 'quantity' => 18, 'unit_cost' => '295.00'],
            ],
        );

        $user = $this->createPurchasingApiUser();
        $reply = app(PurchasingLandedCostReply::class);

        $text = $reply->tryBuildReply(
            $user,
            'What is the landed cost for purchase order '.$order->id.'?',
            'What is the landed cost per slab on PO-'.$order->id.'?',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('Landed cost for PO '.$order->id, $text);
        $this->assertStringContainsString('Freight: 1,100.00', $text);
        $this->assertStringContainsString('Landed unit cost: 356.11', $text);
        $this->assertStringContainsString('Line landed total: 6,409.98', $text);
        $this->assertStringContainsString('DEMO-GRN-BLK-30 (Black Galaxy) × 18:', $text);
        $this->assertStringContainsString('Total PO landed cost: 6,409.98', $text);
        $this->assertStringNotContainsString('freight 1100.00:', $text);
    }
}
