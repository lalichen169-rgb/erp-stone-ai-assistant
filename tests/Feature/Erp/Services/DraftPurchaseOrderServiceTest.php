<?php

namespace Tests\Feature\Erp\Services;

use App\Enums\PurchaseOrderProposalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\User;
use App\Services\Erp\DraftPurchaseOrderService;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\UsesErpDatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\TestCase;

class DraftPurchaseOrderServiceTest extends TestCase
{
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    private DraftPurchaseOrderService $service;

    private User $purchasingUser;

    private User $otherPurchasingUser;

    private User $salesUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DraftPurchaseOrderService;
        $this->purchasingUser = User::factory()->create();
        $this->purchasingUser->assignRole(UserRole::Purchasing);
        $this->otherPurchasingUser = User::factory()->create();
        $this->otherPurchasingUser->assignRole(UserRole::Purchasing);
        $this->salesUser = User::factory()->create();

        Config::set('erp.purchase_order_proposal_ttl_minutes', 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(Product $product): array
    {
        $supplier = $this->createSupplierWithName('Proposal Supplier');

        return [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-11-06',
            'eta' => '2026-12-15',
            'freight_cost' => '125.50',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 4,
                    'unit_cost' => '210.00',
                ],
            ],
        ];
    }

    public function test_purchasing_can_create_proposal_without_creating_po_or_lines(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $input = $this->validInput($product);

        $result = $this->service->createProposal(
            $this->purchasingUser,
            'conv-001',
            'idem-001',
            $input
        );

        $this->assertSame('pending', $result['status']);
        $this->assertSame('Proposal Supplier', $result['supplier_name']);
        $this->assertSame('840.00', $result['subtotal']);
        $this->assertCount(1, PurchaseOrderProposal::all());
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderLine::count());
    }

    public function test_sales_cannot_create_retrieve_or_confirm_proposals(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $input = $this->validInput($product);

        $this->expectException(AuthorizationException::class);
        $this->service->createProposal($this->salesUser, 'conv-001', 'idem-sales', $input);
    }

    public function test_sales_cannot_retrieve_or_confirm_existing_proposal(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-001',
            'idem-002',
            $this->validInput($product)
        );

        try {
            $this->service->getProposal($this->salesUser, $created['proposal_id'], 'conv-001');
            $this->fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->expectException(AuthorizationException::class);
        $this->service->confirmProposal($this->salesUser, $created['proposal_id'], 'conv-001');
    }

    public function test_other_purchasing_user_cannot_access_proposal(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-001',
            'idem-003',
            $this->validInput($product)
        );

        $this->expectException(PurchaseOrderProposalException::class);
        $this->service->getProposal($this->otherPurchasingUser, $created['proposal_id'], 'conv-001');
    }

    public function test_creation_validation_rejects_invalid_input(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $base = $this->validInput($product);

        $cases = [
            array_replace($base, ['supplier_id' => 99999]),
            array_replace($base, ['lines' => [['product_id' => 99999, 'quantity' => 1, 'unit_cost' => '1.00']]]),
            array_replace($base, ['lines' => []]),
            array_replace($base, ['lines' => [['product_id' => $product->id, 'quantity' => 0, 'unit_cost' => '1.00']]]),
            array_replace($base, ['lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '-1.00']]]),
            array_replace($base, ['freight_cost' => '-5.00']),
            array_replace($base, ['order_date' => 'not-a-date']),
            array_replace($base, ['eta' => '2026-01-01']),
        ];

        foreach ($cases as $index => $input) {
            try {
                $this->service->createProposal($this->purchasingUser, 'conv-val', 'idem-val-'.$index, $input);
                $this->fail('Expected validation exception for case '.$index);
            } catch (PurchaseOrderProposalException) {
                // expected
            }
        }

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_owner_and_conversation_key_can_retrieve_proposal(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-scope',
            'idem-scope',
            $this->validInput($product)
        );

        $fetched = $this->service->getProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-scope'
        );

        $this->assertSame($created['proposal_id'], $fetched['proposal_id']);
        $this->assertSame('TEST-STQ-CAL-30', $fetched['lines'][0]['product_sku']);
    }

    public function test_wrong_conversation_key_cannot_retrieve_or_confirm(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-a',
            'idem-wrong-conv',
            $this->validInput($product)
        );

        $this->expectException(PurchaseOrderProposalException::class);
        $this->service->getProposal($this->purchasingUser, $created['proposal_id'], 'conv-b');
    }

    public function test_creation_idempotency_same_key_and_payload(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $input = $this->validInput($product);

        $first = $this->service->createProposal($this->purchasingUser, 'conv-idem', 'idem-same', $input);
        $second = $this->service->createProposal($this->purchasingUser, 'conv-idem', 'idem-same', $input);

        $this->assertSame($first['proposal_id'], $second['proposal_id']);
        $this->assertSame(1, PurchaseOrderProposal::count());
    }

    public function test_creation_idempotency_rejects_same_key_with_different_payload(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $input = $this->validInput($product);

        $this->service->createProposal($this->purchasingUser, 'conv-idem2', 'idem-diff', $input);

        $changed = $input;
        $changed['lines'][0]['quantity'] = 99;

        $this->expectException(PurchaseOrderProposalException::class);
        $this->service->createProposal($this->purchasingUser, 'conv-idem2', 'idem-diff', $changed);
    }

    public function test_confirm_creates_draft_po_and_lines(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-confirm',
            'idem-confirm',
            $this->validInput($product)
        );

        $confirmed = $this->service->confirmProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-confirm'
        );

        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame('draft', $confirmed['purchase_order_status']);
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrderLine::count());

        $order = PurchaseOrder::firstOrFail();
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('125.50', (string) $order->freight_cost);
        $this->assertSame(4, $order->lines->first()->quantity);
    }

    public function test_confirm_twice_creates_only_one_po(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-twice',
            'idem-twice',
            $this->validInput($product)
        );

        $first = $this->service->confirmProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-twice'
        );
        $second = $this->service->confirmProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-twice'
        );

        $this->assertSame($first['purchase_order_id'], $second['purchase_order_id']);
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrderLine::count());
    }

    public function test_expired_proposal_cannot_be_confirmed(): void
    {
        Carbon::setTestNow('2026-11-06 10:00:00');

        $product = $this->createCalacattaThreeCmDemoProduct();
        Config::set('erp.purchase_order_proposal_ttl_minutes', 5);

        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-exp',
            'idem-exp',
            $this->validInput($product)
        );

        Carbon::setTestNow('2026-11-06 10:10:00');

        $this->expectException(PurchaseOrderProposalException::class);
        $this->service->confirmProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-exp'
        );

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(
            PurchaseOrderProposalStatus::Expired,
            PurchaseOrderProposal::findOrFail($created['proposal_id'])->status
        );

        Carbon::setTestNow();
    }

    public function test_cancelled_proposal_cannot_be_confirmed(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-cancel',
            'idem-cancel',
            $this->validInput($product)
        );

        $this->service->cancelProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-cancel'
        );

        $this->expectException(PurchaseOrderProposalException::class);
        $this->service->confirmProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-cancel'
        );
    }

    public function test_confirmed_proposal_cannot_be_cancelled(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-no-cancel',
            'idem-no-cancel',
            $this->validInput($product)
        );

        $this->service->confirmProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-no-cancel'
        );

        $this->expectException(PurchaseOrderProposalException::class);
        $this->service->cancelProposal(
            $this->purchasingUser,
            $created['proposal_id'],
            'conv-no-cancel'
        );
    }

    public function test_failed_confirmation_rolls_back_po_writes(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $created = $this->service->createProposal(
            $this->purchasingUser,
            'conv-rollback',
            'idem-rollback',
            $this->validInput($product)
        );

        $product->delete();

        try {
            $this->service->confirmProposal(
                $this->purchasingUser,
                $created['proposal_id'],
                'conv-rollback'
            );
            $this->fail('Expected confirmation failure.');
        } catch (\Throwable) {
            // FK or domain failure during line insert
        }

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderLine::count());
        $this->assertSame(
            PurchaseOrderProposalStatus::Pending,
            PurchaseOrderProposal::findOrFail($created['proposal_id'])->status
        );
    }

    public function test_response_does_not_include_supplier_bank_details(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Secure Snapshot Supplier');
        $supplier->bank_details = 'Routing DEMO-123';
        $supplier->save();

        $input = $this->validInput($product);
        $input['supplier_id'] = $supplier->id;

        $result = $this->service->createProposal(
            $this->purchasingUser,
            'conv-bank',
            'idem-bank',
            $input
        );

        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('bank_details', $encoded);
        $this->assertStringNotContainsString('Routing DEMO', $encoded);
    }
}
