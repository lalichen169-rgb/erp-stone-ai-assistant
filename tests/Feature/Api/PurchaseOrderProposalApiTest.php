<?php

namespace Tests\Feature\Api;

use App\Enums\PurchaseOrderProposalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class PurchaseOrderProposalApiTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    /**
     * @return array<string, mixed>
     */
    private function validProposalBody(Product $product): array
    {
        $supplier = $this->createSupplierWithName('API Proposal Supplier');

        return [
            'conversation_key' => 'conv-api-001',
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

    private function createProposalViaApi(User $user, array $body, string $idempotencyKey = 'idem-api-001'): array
    {
        $this->actingAsSanctum($user);

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/purchase-order-proposals', $body);

        return [
            'response' => $response,
            'json' => $response->json('data') ?? $response->json(),
        ];
    }

    public function test_unauthenticated_users_cannot_access_proposal_endpoints(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';

        $this->postJson('/api/purchase-order-proposals', [])->assertUnauthorized();
        $this->getJson('/api/purchase-order-proposals/'.$uuid.'?conversation_key=x')->assertUnauthorized();
        $this->postJson('/api/purchase-order-proposals/'.$uuid.'/confirm?conversation_key=x')->assertUnauthorized();
        $this->deleteJson('/api/purchase-order-proposals/'.$uuid.'?conversation_key=x')->assertUnauthorized();
    }

    public function test_sales_receives_403_on_all_proposal_endpoints(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $body = $this->validProposalBody($product);
        $sales = $this->createSalesApiUser();
        $this->actingAsSanctum($sales);

        $this->withHeader('Idempotency-Key', 'idem-sales')
            ->postJson('/api/purchase-order-proposals', $body)
            ->assertForbidden();

        $uuid = '00000000-0000-4000-8000-000000000002';

        $this->getJson('/api/purchase-order-proposals/'.$uuid.'?conversation_key=conv-api-001')
            ->assertForbidden();

        $this->postJson('/api/purchase-order-proposals/'.$uuid.'/confirm?conversation_key=conv-api-001')
            ->assertForbidden();

        $this->deleteJson('/api/purchase-order-proposals/'.$uuid.'?conversation_key=conv-api-001')
            ->assertForbidden();
    }

    public function test_valid_create_does_not_create_po_or_lines(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();

        ['response' => $response] = $this->createProposalViaApi($purchasing, $this->validProposalBody($product));

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.subtotal', '840.00');

        $this->assertSame(1, PurchaseOrderProposal::count());
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderLine::count());
    }

    public function test_create_validation_requires_idempotency_header_and_conversation_key(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $body = $this->validProposalBody($product);
        unset($body['conversation_key']);
        $this->actingAsSanctum($this->createPurchasingApiUser());

        $this->postJson('/api/purchase-order-proposals', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conversation_key', 'idempotency_key']);

        $this->withHeader('Idempotency-Key', '')
            ->postJson('/api/purchase-order-proposals', $this->validProposalBody($product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_create_validation_rejects_invalid_supplier_product_and_lines(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $this->actingAsSanctum($this->createPurchasingApiUser());
        $base = $this->validProposalBody($product);

        $this->withHeader('Idempotency-Key', 'idem-bad-supplier')
            ->postJson('/api/purchase-order-proposals', array_replace($base, ['supplier_id' => 99999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);

        $badLines = $base;
        $badLines['lines'] = [['product_id' => 99999, 'quantity' => 1, 'unit_cost' => '1.00']];

        $this->withHeader('Idempotency-Key', 'idem-bad-product')
            ->postJson('/api/purchase-order-proposals', $badLines)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.product_id']);

        $this->withHeader('Idempotency-Key', 'idem-empty-lines')
            ->postJson('/api/purchase-order-proposals', array_replace($base, ['lines' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->withHeader('Idempotency-Key', 'idem-bad-qty')
            ->postJson('/api/purchase-order-proposals', array_replace($base, [
                'lines' => [['product_id' => $product->id, 'quantity' => 0, 'unit_cost' => '1.00']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.quantity']);

        $this->withHeader('Idempotency-Key', 'idem-bad-cost')
            ->postJson('/api/purchase-order-proposals', array_replace($base, [
                'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '-1.00']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.unit_cost']);

        $tooManyLines = $base;
        $tooManyLines['lines'] = array_fill(0, 51, [
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => '1.00',
        ]);

        $this->withHeader('Idempotency-Key', 'idem-too-many')
            ->postJson('/api/purchase-order-proposals', $tooManyLines)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_owner_can_retrieve_and_snapshot_is_immutable(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();
        $body = $this->validProposalBody($product);

        ['json' => $created] = $this->createProposalViaApi($purchasing, $body, 'idem-show');

        $product->name = 'Changed After Proposal';
        $product->save();

        $this->actingAsSanctum($purchasing);

        $show = $this->getJson('/api/purchase-order-proposals/'.$created['proposal_id'].'?conversation_key=conv-api-001')
            ->assertOk();

        $show->assertJsonPath('data.lines.0.product_sku', 'TEST-STQ-CAL-30');
        $show->assertJsonPath('data.lines.0.product_name', 'Calacatta Quartz Slab');
    }

    public function test_wrong_conversation_key_and_other_user_cannot_access_proposal(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $owner = $this->createPurchasingApiUser();
        $other = $this->createPurchasingApiUser();

        ['json' => $created] = $this->createProposalViaApi($owner, $this->validProposalBody($product), 'idem-scope');

        $this->actingAsSanctum($owner);
        $this->getJson('/api/purchase-order-proposals/'.$created['proposal_id'].'?conversation_key=wrong-key')
            ->assertNotFound();

        $this->actingAsSanctum($other);
        $this->getJson('/api/purchase-order-proposals/'.$created['proposal_id'].'?conversation_key=conv-api-001')
            ->assertNotFound();

        $this->actingAsSanctum($this->createSalesApiUser());
        $this->getJson('/api/purchase-order-proposals/'.$created['proposal_id'].'?conversation_key=conv-api-001')
            ->assertForbidden();
    }

    public function test_idempotency_same_key_returns_existing_without_duplicate(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();
        $body = $this->validProposalBody($product);

        ['json' => $first, 'response' => $firstResponse] = $this->createProposalViaApi($purchasing, $body, 'idem-repeat');
        $firstResponse->assertCreated();

        ['json' => $second, 'response' => $secondResponse] = $this->createProposalViaApi($purchasing, $body, 'idem-repeat');
        $secondResponse->assertOk();

        $this->assertSame($first['proposal_id'], $second['proposal_id']);
        $this->assertSame(1, PurchaseOrderProposal::count());
    }

    public function test_idempotency_rejects_same_key_with_different_payload(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $this->actingAsSanctum($this->createPurchasingApiUser());
        $body = $this->validProposalBody($product);

        $this->withHeader('Idempotency-Key', 'idem-conflict')
            ->postJson('/api/purchase-order-proposals', $body)
            ->assertCreated();

        $changed = $body;
        $changed['lines'][0]['quantity'] = 99;

        $this->withHeader('Idempotency-Key', 'idem-conflict')
            ->postJson('/api/purchase-order-proposals', $changed)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Idempotency key already used with different proposal data.');
    }

    public function test_confirm_creates_single_draft_po_and_repeat_confirm_is_idempotent(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();

        ['json' => $created] = $this->createProposalViaApi($purchasing, $this->validProposalBody($product), 'idem-confirm-api');

        $this->actingAsSanctum($purchasing);

        $first = $this->postJson('/api/purchase-order-proposals/'.$created['proposal_id'].'/confirm?conversation_key=conv-api-001')
            ->assertOk();

        $first->assertJsonPath('data.status', 'confirmed');
        $first->assertJsonPath('data.purchase_order_status', 'draft');

        $poId = $first->json('data.purchase_order_id');

        $second = $this->postJson('/api/purchase-order-proposals/'.$created['proposal_id'].'/confirm?conversation_key=conv-api-001')
            ->assertOk();

        $this->assertSame($poId, $second->json('data.purchase_order_id'));
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrderLine::count());
        $this->assertSame(PurchaseOrderStatus::Draft, PurchaseOrder::firstOrFail()->status);
    }

    public function test_lifecycle_expired_and_cancelled_proposals_cannot_be_confirmed(): void
    {
        Carbon::setTestNow('2026-11-06 10:00:00');
        Config::set('erp.purchase_order_proposal_ttl_minutes', 5);

        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();

        ['json' => $created] = $this->createProposalViaApi($purchasing, $this->validProposalBody($product), 'idem-exp-api');

        Carbon::setTestNow('2026-11-06 10:10:00');

        $this->actingAsSanctum($purchasing);
        $this->postJson('/api/purchase-order-proposals/'.$created['proposal_id'].'/confirm?conversation_key=conv-api-001')
            ->assertConflict();

        $this->assertSame(0, PurchaseOrder::count());

        Carbon::setTestNow();

        ['json' => $cancelled] = $this->createProposalViaApi(
            $purchasing,
            array_replace($this->validProposalBody($product), ['conversation_key' => 'conv-cancel-api']),
            'idem-cancel-api'
        );

        $this->actingAsSanctum($purchasing);
        $this->deleteJson('/api/purchase-order-proposals/'.$cancelled['proposal_id'].'?conversation_key=conv-cancel-api')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson('/api/purchase-order-proposals/'.$cancelled['proposal_id'].'/confirm?conversation_key=conv-cancel-api')
            ->assertConflict();

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_confirmed_proposal_cannot_be_cancelled(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();

        ['json' => $created] = $this->createProposalViaApi($purchasing, $this->validProposalBody($product), 'idem-no-cancel-api');

        $this->actingAsSanctum($purchasing);
        $this->postJson('/api/purchase-order-proposals/'.$created['proposal_id'].'/confirm?conversation_key=conv-api-001')
            ->assertOk();

        $this->deleteJson('/api/purchase-order-proposals/'.$created['proposal_id'].'?conversation_key=conv-api-001')
            ->assertConflict();
    }

    public function test_failed_confirmation_rolls_back_po_writes(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $purchasing = $this->createPurchasingApiUser();

        ['json' => $created] = $this->createProposalViaApi($purchasing, $this->validProposalBody($product), 'idem-rollback-api');

        $product->delete();

        $this->actingAsSanctum($purchasing);
        $response = $this->postJson('/api/purchase-order-proposals/'.$created['proposal_id'].'/confirm?conversation_key=conv-api-001');
        $this->assertTrue(in_array($response->status(), [422, 500], true));
        $response->assertJsonMissing(['trace']);

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderLine::count());
        $this->assertSame(
            PurchaseOrderProposalStatus::Pending,
            PurchaseOrderProposal::findOrFail($created['proposal_id'])->status
        );
    }

    public function test_responses_do_not_expose_sensitive_fields(): void
    {
        $product = $this->createCalacattaThreeCmDemoProduct();
        $supplier = $this->createSupplierWithName('Secure API Supplier');
        $supplier->bank_details = 'Routing SECRET-999';
        $supplier->save();

        $body = $this->validProposalBody($product);
        $body['supplier_id'] = $supplier->id;

        $purchasing = $this->createPurchasingApiUser();

        ['json' => $created] = $this->createProposalViaApi($purchasing, $body, 'idem-secure');

        $encoded = json_encode($created);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('bank_details', $encoded);
        $this->assertStringNotContainsString('Routing SECRET', $encoded);
        $this->assertStringNotContainsString('payload_hash', $encoded);
        $this->assertStringNotContainsString('$2y$', $encoded);
    }
}
