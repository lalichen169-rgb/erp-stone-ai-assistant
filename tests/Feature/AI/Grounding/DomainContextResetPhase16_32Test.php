<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Context\DeterministicFollowUpResolver;
use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\Grounding\BroadProductAvailabilityReply;
use App\Services\AI\Grounding\ErpCrossDomainRequestDetector;
use App\Services\AI\Grounding\StockCountGroundingEnforcer;
use App\Services\AI\Grounding\StockCountIntentDetector;
use App\Services\AI\Grounding\StockThreadProductContextExtractor;
use Database\Seeders\ProductSeeder;
use Database\Seeders\StockSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class DomainContextResetPhase16_32Test extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    private const HOUSTON_CALACATTA = 'How many 2cm Calacatta slabs do we have in Houston';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehouseSeeder::class);
        $this->seed(ProductSeeder::class);
        $this->seed(StockSeeder::class);
    }

    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_exact_three_message_reproduction(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_broad_product_availability_disclosure', true);
        Config::set('ai.assistant.enforce_stock_grounding', true);

        OpenAI::fake([]);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);
        $conv = app(ConversationService::class)->createForUser($user);

        $first = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => self::HOUSTON_CALACATTA,
        ])->assertOk()->json();

        $this->assertStringContainsString('Houston', (string) ($first['data']['message'] ?? ''));
        $this->assertStringContainsString('25', (string) ($first['data']['message'] ?? ''));

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'in austin',
        ])->assertOk()->json();

        $this->assertStringContainsString('Austin', (string) ($second['data']['message'] ?? ''));
        $this->assertStringNotContainsString('Houston', (string) ($second['data']['message'] ?? ''));

        $third = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => 'Show me all available products',
        ])->assertOk()->json();

        $broad = (string) ($third['data']['message'] ?? '');
        $this->assertStringContainsString('In-stock slabs', $broad);
        $this->assertGreaterThanOrEqual(2, substr_count($broad, 'SKU'));
        $this->assertStringNotContainsString('only 25 slabs', strtolower($broad));
    }

    public function test_austin_follow_up_retains_calacatta_thickness(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, self::HOUSTON_CALACATTA);
        $svc->appendAssistantMessage($user, $conv, 'Houston stock listed.');

        $follow = $svc->appendUserMessage($user, $conv, 'in austin');
        $inherit = app(StockThreadProductContextExtractor::class)->inheritProductFilters($conv->fresh(), $follow);

        $this->assertNotNull($inherit);
        $this->assertSame('Calacatta', $inherit['color'] ?? null);
        $this->assertSame('2.00', $inherit['thickness_cm'] ?? null);
        $this->assertFalse(app(ErpCrossDomainRequestDetector::class)->shouldResetInheritedStockProductFilters('in austin'));
    }

    public function test_broad_request_resets_inherited_filters(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, self::HOUSTON_CALACATTA);
        $svc->appendAssistantMessage($user, $conv, 'Houston 25.');
        $svc->appendUserMessage($user, $conv, 'in austin');
        $svc->appendAssistantMessage($user, $conv, 'Austin 0.');

        $third = $svc->appendUserMessage($user, $conv, 'Show me all available products');
        $detector = app(ErpCrossDomainRequestDetector::class);

        $this->assertTrue($detector->shouldResetInheritedStockProductFilters(
            'Show me all available products',
            'Show me all available products',
        ));
        $wouldInherit = app(StockThreadProductContextExtractor::class)->inheritProductFilters($conv->fresh(), $third);
        $this->assertNotNull($wouldInherit, 'Prior turns still contain filters; reset flag must suppress merging them.');
        $reply = app(BroadProductAvailabilityReply::class)->tryBuildReply(
            $user,
            'Show me all available products',
            'Show me all available products',
        );
        $this->assertNotNull($reply);
        $this->assertGreaterThanOrEqual(2, substr_count($reply, 'SKU'));

        $resolution = app(FollowUpQuestionResolver::class)->resolve('Show me all available products', $conv->fresh());
        $this->assertTrue($resolution->wasAlreadyStandalone);

        $this->assertNull(
            app(DeterministicFollowUpResolver::class)->tryExpand(
                'Show me all available products',
                self::HOUSTON_CALACATTA,
                'Stock...',
                null,
            ),
        );
    }

    public function test_new_narrow_carrara_dallas_does_not_inherit_calacatta_houston(): void
    {
        $user = $this->createSalesApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, self::HOUSTON_CALACATTA);
        $svc->appendAssistantMessage($user, $conv, 'ok');

        $question = 'How many 3cm Carrara slabs do we have in Dallas?';
        $this->assertTrue(app(ErpCrossDomainRequestDetector::class)->shouldResetInheritedStockProductFilters($question));

        $enforcer = app(StockCountGroundingEnforcer::class);
        $text = $enforcer->enforce('', $user, $question, [], null, $question);
        $this->assertStringContainsString('Dallas', $text);
        $this->assertStringContainsString('DEMO-STQ-CAR', $text);
        $this->assertStringNotContainsString('DEMO-STQ-CAL-20', $text);
    }

    public function test_thickness_replacement_retains_other_context(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);
        $expanded = $resolver->tryExpand(
            'What about 3cm?',
            self::HOUSTON_CALACATTA,
            '25 in Houston',
            null,
        );

        $this->assertNotNull($expanded);
        $resolved = (string) $expanded->resolvedStandaloneQuestion;
        $this->assertStringContainsString('3', $resolved);
        $this->assertStringContainsString('Calacatta', $resolved);
        $this->assertStringContainsString('Houston', $resolved);
        $this->assertFalse(app(ErpCrossDomainRequestDetector::class)->shouldResetInheritedStockProductFilters('What about 3cm?'));
    }

    public function test_explicit_broadening_phrases(): void
    {
        $detector = app(ErpCrossDomainRequestDetector::class);

        foreach ([
            'Show all products',
            'Show me all available products',
            'Show it across all warehouses',
        ] as $phrase) {
            $this->assertTrue($detector->isExplicitBroadeningInstruction($phrase), $phrase);
            $this->assertTrue($detector->shouldResetInheritedStockProductFilters($phrase), $phrase);
        }
    }

    public function test_across_all_warehouses_keeps_product_identity(): void
    {
        Config::set('ai.assistant.enforce_stock_across_warehouses_disclosure', true);

        $user = $this->createSalesApiUser();
        $reply = app(\App\Services\AI\Grounding\StockAcrossWarehousesReply::class)->tryBuildReply(
            $user,
            'Show Calacatta across all warehouses',
            'Show it across all warehouses',
        );

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Calacatta', $reply);
        $this->assertStringContainsString('Houston', $reply);
    }

    public function test_broad_listing_without_history(): void
    {
        Config::set('ai.assistant.enforce_broad_product_availability_disclosure', true);
        $user = $this->createSalesApiUser();

        $text = app(BroadProductAvailabilityReply::class)->tryBuildReply(
            $user,
            'Show me all available products',
            'Show me all available products',
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('In-stock slabs', $text);
    }

    public function test_stock_grounding_not_applied_with_reset_on_broad_request(): void
    {
        $user = $this->createSalesApiUser();
        $detector = app(StockCountIntentDetector::class);

        $this->assertFalse($detector->requiresGrounding(
            'Show me all available products',
            'Show me all available products',
            null,
        ));
    }
}
