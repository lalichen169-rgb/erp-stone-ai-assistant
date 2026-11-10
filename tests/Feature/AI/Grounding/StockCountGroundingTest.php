<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\Grounding\StockCountGroundingEnforcer;
use App\Services\AI\Grounding\StockCountIntentDetector;
use App\Services\AI\Grounding\StockCountQuestionParser;
use App\Services\AI\Grounding\StockThreadProductContextExtractor;
use App\Services\Erp\StockLookupService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class StockCountGroundingTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);
    }

    public function test_intent_detector_matches_warehouse_slab_count_questions(): void
    {
        $detector = app(StockCountIntentDetector::class);

        $this->assertTrue($detector->isWarehouseStockCountQuestion(
            'How many 2cm Calacatta slabs do we have in Austin?',
        ));
        $this->assertTrue($detector->isWarehouseStockCountQuestion(
            'How many 3 cm Calacatta slabs are available in Houston?',
        ));
        $this->assertFalse($detector->isWarehouseStockCountQuestion(
            'What is the landed cost of PO 12?',
        ));
        $this->assertFalse($detector->isWarehouseStockCountQuestion(
            'Create a draft PO for 5 slabs of 2cm Calacatta quartz from Demo Supplier — Central Texas Granite delivered to Dallas warehouse. Freight 500.',
        ));
    }

    public function test_parser_resolves_two_cm_calacatta_at_austin_without_default_material(): void
    {
        $parser = app(StockCountQuestionParser::class);
        $parsed = $parser->parse('How many 2cm Calacatta slabs do we have in Austin?');

        $this->assertNotNull($parsed);
        $this->assertSame('Austin', $parsed['warehouse']['name']);
        $this->assertSame('2.00', $parsed['product']['thickness_cm']);
        $this->assertSame('Calacatta', $parsed['product']['color']);
        $this->assertArrayNotHasKey('material', $parsed['product']);
    }

    public function test_enforcer_lists_quartz_and_marble_calacatta_at_austin_for_3cm(): void
    {
        $user = $this->createSalesApiUser();
        $enforcer = app(StockCountGroundingEnforcer::class);

        $question = 'How many 3cm Calacatta slabs do we have in Austin?';
        $text = $enforcer->enforce('Only quartz narrative.', $user, $question, []);

        $this->assertStringContainsString('9', $text);
        $this->assertStringContainsString('7', $text);
        $this->assertStringContainsString('DEMO-STQ-CAL-30', $text);
        $this->assertStringContainsString('DEMO-MAR-CAL-30', $text);
        $this->assertStringContainsString('Quartz', $text);
        $this->assertStringContainsString('Marble', $text);
    }

    public function test_enforcer_replaces_hallucinated_slab_count_with_erp_zero(): void
    {
        $user = $this->createSalesApiUser();
        $enforcer = app(StockCountGroundingEnforcer::class);

        $question = 'How many 2cm Calacatta slabs do we have in Austin?';
        $hallucination = 'There are 4 slabs of 2cm Calacatta Quartz available in the Austin warehouse.';

        $text = $enforcer->enforce($hallucination, $user, $question, []);

        $this->assertStringContainsString('Austin', $text);
        $this->assertMatchesRegularExpression('/\b0 slabs\b/i', $text);
        $this->assertStringNotContainsString('4 slabs', $text);
    }

    public function test_enforcer_returns_canonical_carrara_houston_fourteen(): void
    {
        $user = $this->createSalesApiUser();
        $enforcer = app(StockCountGroundingEnforcer::class);

        $question = 'How many 3cm Carrara slabs do we have in Houston?';
        $text = $enforcer->enforce('Wrong narrative.', $user, $question, []);

        $this->assertStringContainsString('14', $text);
        $this->assertStringContainsString('DEMO-STQ-CAR-30', $text);
        $this->assertStringContainsString('Houston', $text);
    }

    public function test_follow_up_in_dallas_grounds_carrara_eleven_not_paris_grey(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $svc->appendUserMessage($user, $conv, 'How many 3cm Carrara slabs do we have in Houston?');
        $svc->appendAssistantMessage($user, $conv, '14 slabs in Houston.');

        $conv = $conv->fresh();
        $followUp = $svc->appendUserMessage($user, $conv, 'in dallas');

        $inherit = app(StockThreadProductContextExtractor::class)->inheritProductFilters($conv->fresh(), $followUp);
        $this->assertNotNull($inherit);
        $this->assertSame('Carrara', $inherit['color'] ?? null);

        $resolver = app(FollowUpQuestionResolver::class);
        $resolved = $resolver->resolve('in dallas', $conv->fresh());
        $this->assertFalse($resolved->needsClarification);
        $this->assertStringContainsString('Carrara', $resolved->resolvedStandaloneQuestion ?? '');
        $this->assertStringContainsString('Dallas', $resolved->resolvedStandaloneQuestion ?? '');

        $detector = app(StockCountIntentDetector::class);
        $this->assertTrue($detector->requiresGrounding(
            $resolved->resolvedStandaloneQuestion ?? '',
            'in dallas',
            $inherit,
        ));

        $enforcer = app(StockCountGroundingEnforcer::class);
        $text = $enforcer->enforce(
            'There are 22 slabs of Paris Grey.',
            $user,
            $resolved->resolvedStandaloneQuestion ?? '',
            [],
            $inherit,
            'in dallas',
        );

        $this->assertStringContainsString('11', $text);
        $this->assertStringContainsString('DEMO-STQ-CAR-30', $text);
        $this->assertStringNotContainsString('PAR-30', $text);
    }

    public function test_direct_lookup_returns_nine_for_three_cm_austin(): void
    {
        $user = $this->createSalesApiUser();
        $service = app(StockLookupService::class);

        $result = $service->stockForProductAtWarehouseByAttributes(
            $user,
            ['color' => 'Calacatta', 'material' => 'Quartz', 'thickness_cm' => '3.00'],
            ['name' => 'Austin'],
        );

        $this->assertTrue($result['found']);
        $this->assertSame(9, $result['slabs_on_hand']);
        $this->assertSame('DEMO-STQ-CAL-30', $result['product']['sku']);
    }

}
