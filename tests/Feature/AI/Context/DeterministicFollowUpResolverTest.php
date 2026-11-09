<?php

namespace Tests\Feature\AI\Context;

use App\Services\AI\Context\DeterministicFollowUpResolver;
use App\Services\AI\Context\FollowUpThreadType;
use Tests\TestCase;

class DeterministicFollowUpResolverTest extends TestCase
{
    public function test_landed_cost_po_id_follow_up(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);

        $result = $resolver->tryExpand(
            '510',
            'What is the landed cost for purchase order 503?',
            'Landed cost for PO 503...',
            FollowUpThreadType::LandedCost,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('510', $result->resolvedStandaloneQuestion ?? '');
        $this->assertStringContainsStringIgnoringCase('landed cost', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_landed_cost_follow_up_for_po_hyphen_number(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);

        $result = $resolver->tryExpand(
            'for PO-510',
            'What is the landed cost per slab on PO-507',
            'Landed cost for PO 507 (supplier). Total PO landed cost: 6,409.98',
            FollowUpThreadType::LandedCost,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('510', $result->resolvedStandaloneQuestion ?? '');
        $this->assertStringContainsStringIgnoringCase('landed cost', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_bank_follow_up_uses_older_anchor_after_failed_turn(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);

        $result = $resolver->tryExpand(
            'for Hill Country Quartz Co',
            'Supplier bank details for Gulf Stone Imports',
            'Banking details for Demo Supplier — Gulf Stone Imports:',
            FollowUpThreadType::BankDetails,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('Hill Country', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_po_search_supplier_follow_up_preserves_draft_status(): void
    {
        $this->seed(\Database\Seeders\ProductSeeder::class);

        $resolver = app(DeterministicFollowUpResolver::class);
        $result = $resolver->tryExpand(
            'Central Texas Granite',
            'Show me the draft purchase orders',
            'Please provide a supplier name.',
            FollowUpThreadType::PurchaseOrderSearch,
        );

        $this->assertNotNull($result);
        $resolved = (string) $result->resolvedStandaloneQuestion;
        $this->assertStringContainsStringIgnoringCase('draft', $resolved);
        $this->assertStringContainsString('Central Texas Granite', $resolved);
    }

    public function test_stock_variety_swap_carrara_after_calacatta_houston(): void
    {
        $this->seed(\Database\Seeders\WarehouseSeeder::class);
        $this->seed(\Database\Seeders\ProductSeeder::class);

        $resolver = app(DeterministicFollowUpResolver::class);
        $result = $resolver->tryExpand(
            'What about Carrara?',
            'Show me Calacatta stock in Houston.',
            'Calacatta stock listed.',
            FollowUpThreadType::Stock,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('Carrara', (string) $result->resolvedStandaloneQuestion);
        $this->assertStringContainsString('Houston', (string) $result->resolvedStandaloneQuestion);
        $this->assertStringNotContainsString('Calacatta', (string) $result->resolvedStandaloneQuestion);
    }

    public function test_overdue_supplier_short_follow_up(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);

        $result = $resolver->tryExpand(
            'Gulf Stone Imports',
            'Which purchase orders are overdue from Gulf Stone Imports?',
            'Listed 2 overdue orders.',
            FollowUpThreadType::OverduePurchaseOrders,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('Gulf Stone Imports', $result->resolvedStandaloneQuestion ?? '');
        $this->assertStringContainsStringIgnoringCase('overdue', $result->resolvedStandaloneQuestion ?? '');
    }

    public function test_overdue_thread_leaves_po_status_follow_up_unexpanded(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);

        $result = $resolver->tryExpand(
            'What is the status of PO-507?',
            'Which purchase orders from Brazos Marble LLC are delayed?',
            'Overdue purchase orders for Brazos Marble LLC (1): PO #516...',
            FollowUpThreadType::OverduePurchaseOrders,
        );

        $this->assertNull($result);
    }
}
