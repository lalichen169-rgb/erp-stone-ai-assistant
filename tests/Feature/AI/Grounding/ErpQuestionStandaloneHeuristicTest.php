<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Grounding\ErpQuestionStandaloneHeuristic;
use Database\Seeders\SupplierSeeder;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class ErpQuestionStandaloneHeuristicTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    private const META_BANK_QUESTION = "Could you please specify which supplier's bank details you need for Gulf Stone Imports";

    public function test_heuristic_treats_meta_bank_phrase_with_supplier_as_actionable(): void
    {
        $heuristic = app(ErpQuestionStandaloneHeuristic::class);

        $this->assertTrue($heuristic->isActionableStandalone(self::META_BANK_QUESTION));
        $this->assertStringContainsString(
            'Gulf Stone Imports',
            (string) $heuristic->canonicalQuestion(self::META_BANK_QUESTION),
        );
    }

    public function test_follow_up_resolver_does_not_clarify_meta_bank_phrase(): void
    {
        $user = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);

        $svc->appendUserMessage($user, $conv, 'Hello');
        $svc->appendAssistantMessage($user, $conv, 'Hi, how can I help?');

        $result = app(FollowUpQuestionResolver::class)->resolve(self::META_BANK_QUESTION, $conv->fresh());

        $this->assertFalse($result->needsClarification);
        $this->assertSame('erp_actionable_standalone', $result->strategy);
        $this->assertStringContainsStringIgnoringCase('bank details', (string) $result->resolvedStandaloneQuestion);
    }

    public function test_api_meta_bank_phrase_returns_erp_not_clarification(): void
    {
        $this->seed(SupplierSeeder::class);

        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.conversation.follow_up.use_ai_when_ambiguous', true);

        OpenAI::fake([]);

        $user = $this->createPurchasingApiUser();
        $this->actingAsSanctum($user);

        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $svc->appendUserMessage($user, $conv, 'Previous unrelated turn');
        $svc->appendAssistantMessage($user, $conv, 'Ok.');

        $json = $this->postJson('/api/assistant', [
            'conversation_id' => $conv->public_id,
            'message' => self::META_BANK_QUESTION,
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertFalse($json['data']['clarification_required'] ?? true);
        $this->assertStringContainsString('DEMO-GSI-001', (string) ($json['data']['message'] ?? ''));
        $this->assertStringNotContainsString('do you need', strtolower((string) ($json['data']['message'] ?? '')));
    }

    public function test_api_sales_meta_bank_phrase_returns_role_refusal_not_clarification(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        OpenAI::fake([]);

        $user = $this->createSalesApiUser();
        $this->actingAsSanctum($user);

        $json = $this->postJson('/api/assistant', [
            'message' => self::META_BANK_QUESTION,
        ])->assertOk()->json();

        $this->assertFalse($json['data']['clarification_required'] ?? true);
        $this->assertStringContainsString('Sales role', (string) ($json['data']['message'] ?? ''));
    }
}
