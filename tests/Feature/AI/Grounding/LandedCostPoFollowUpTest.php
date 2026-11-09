<?php

namespace Tests\Feature\AI\Grounding;

use App\Enums\PurchaseOrderStatus;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Grounding\LandedCostQuestionParser;
use App\Services\AI\Grounding\PurchasingBankDetailsReply;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\CreatesErpServiceFixtures;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class LandedCostPoFollowUpTest extends TestCase
{
    use ActsAsApiUser;
    use CreatesErpServiceFixtures;
    use UsesErpDatabaseTransactions;

    public function test_parser_extracts_id_from_for_po_hyphen_form(): void
    {
        $parser = app(LandedCostQuestionParser::class);

        $this->assertSame(510, $parser->extractPurchaseOrderId('for PO-510'));
        $this->assertSame(507, $parser->extractPurchaseOrderId('What is the landed cost per slab on PO-507'));
    }

    public function test_bank_reply_ignores_for_po_hyphen_follow_up(): void
    {
        $user = $this->createPurchasingApiUser();
        $reply = app(PurchasingBankDetailsReply::class);

        $this->assertNull($reply->tryBuildReply($user, 'for PO-510', 'for PO-510'));
    }

    public function test_api_landed_cost_follow_up_for_po_hyphen(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);

        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchasing_landed_cost_disclosure', true);

        OpenAI::fake([]);

        $supplier = \App\Models\Supplier::query()->where('name', 'like', '%Central Texas%')->firstOrFail();
        $product = \App\Models\Product::query()->where('sku', 'DEMO-GRN-BLK-30')->firstOrFail();
        $line = ['product' => $product, 'quantity' => 18, 'unit_cost' => '295.00'];

        $firstOrder = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-15',
            '2026-06-30',
            '1100.00',
            [$line],
        );

        $secondOrder = $this->createPurchaseOrderWithLines(
            $supplier,
            PurchaseOrderStatus::Received,
            '2026-05-20',
            '2026-07-01',
            '900.00',
            [$line],
        );

        $user = $this->createPurchasingApiUser();
        $this->actingAsSanctum($user);

        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $first = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'What is the landed cost per slab on PO-'.$firstOrder->id,
        ])->assertOk()->json();

        $this->assertStringContainsString((string) $firstOrder->id, (string) ($first['data']['message'] ?? ''));

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'for PO-'.$secondOrder->id,
        ])->assertOk()->json();

        $this->assertFalse($second['data']['clarification_required'] ?? true);
        $this->assertStringContainsString('Landed cost for PO '.$secondOrder->id, (string) ($second['data']['message'] ?? ''));
        $this->assertStringNotContainsString('No supplier matched', (string) ($second['data']['message'] ?? ''));
    }
}
