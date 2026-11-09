<?php

namespace Tests\Feature\AI\Grounding;

use App\DTOs\AI\AiToolCall;
use App\Models\ConversationToolCall;
use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Conversations\ConversationTurnRecorder;
use App\Services\AI\Grounding\SalesBankDetailsRefusal;
use App\Services\AI\Grounding\SupplierBankDetailsIntentDetector;
use App\Services\AI\Grounding\SupplierBankDetailsQuestionParser;
use App\Services\AI\Tools\ToolExecutionResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\Support\ActsAsApiUser;
use Tests\Support\UsesErpDatabaseTransactions;
use Tests\TestCase;

class SalesBankDetailsRefusalTest extends TestCase
{
    use ActsAsApiUser;
    use UsesErpDatabaseTransactions;

    public function test_question_parser_extracts_supplier_from_clarification_style_phrase(): void
    {
        $parser = app(SupplierBankDetailsQuestionParser::class);

        $this->assertSame(
            'Gulf Stone Imports',
            $parser->extractSupplierNameHint(
                "Could you please specify which supplier's bank details you need for Gulf Stone Imports",
            ),
        );
    }

    public function test_intent_detector_matches_supplier_bank_questions(): void
    {
        $detector = app(SupplierBankDetailsIntentDetector::class);

        $this->assertTrue($detector->isSupplierBankDetailsRequest(
            'Supplier bank details for Gulf Stone Imports',
        ));
        $this->assertTrue($detector->isSupplierBankDetailsRequest(
            "Could you please specify which supplier's bank details you need for Gulf Stone Imports",
        ));
        $this->assertTrue($detector->isSupplierBankDetailsRequest(
            'What are the bank account details for our suppliers?',
        ));
        $this->assertFalse($detector->isSupplierBankDetailsRequest(
            'How many 3cm Calacatta slabs are available in Houston?',
        ));
    }

    public function test_sales_refusal_message_for_sales_user_only(): void
    {
        $refusal = app(SalesBankDetailsRefusal::class);
        $sales = $this->createSalesApiUser();
        $purchasing = $this->createPurchasingApiUser();

        $question = 'Supplier bank details for Gulf Stone Imports';

        $this->assertSame(
            SalesBankDetailsRefusal::REFUSAL_MESSAGE,
            $refusal->refusalMessageFor($sales, $question, $question),
        );
        $this->assertNull($refusal->refusalMessageFor($purchasing, $question, $question));
    }

    public function test_api_sales_bank_details_returns_deterministic_refusal_without_provider(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_sales_bank_refusal', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertSame(SalesBankDetailsRefusal::REFUSAL_MESSAGE, $json['data']['message'] ?? null);
        $this->assertSame(0, $json['data']['tool_calls'] ?? -1);
        $this->assertArrayHasKey('provider', $json['data']);
        $this->assertNull($json['data']['provider']);
        $this->assertTrue($json['data']['completed'] ?? false);
    }

    public function test_persist_tool_traces_passes_viewer_user_for_bank_redaction(): void
    {
        $user = $this->createPurchasingApiUser();
        $svc = app(ConversationService::class);
        $conv = $svc->createForUser($user);
        $userMsg = $svc->appendUserMessage($user, $conv, 'Bank details');

        $recorder = new ConversationTurnRecorder($conv, $userMsg);
        $recorder->add(
            new AiToolCall('bank1', 'getSupplierBankDetails', ['name' => 'Gulf']),
            new ToolExecutionResult(success: true, data: [
                'found' => true,
                'supplier' => [
                    'id' => 1,
                    'name' => 'Demo',
                    'bank_details' => 'Account DEMO-PERSIST-001',
                ],
            ]),
        );

        $svc->persistToolTraces($user, $recorder);

        $tool = ConversationToolCall::query()->where('conversation_id', $conv->id)->first();
        $this->assertNotNull($tool);
        $encoded = json_encode($tool->safe_result, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Account DEMO-PERSIST-001', $encoded);
    }

    public function test_api_purchasing_bank_details_returns_erp_bank_text_without_provider(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchasing_bank_disclosure', true);

        Supplier::query()->create([
            'name' => 'Demo Supplier — Gulf Stone Imports',
            'contact' => 'orders@gulf-stone-demo.example',
            'payment_terms' => 'Net 30',
            'bank_details' => 'Demo Bank — Account DEMO-GSI-001 — Routing DEMO-111000',
        ]);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertStringContainsString('DEMO-GSI-001', (string) ($json['data']['message'] ?? ''));
        $this->assertNull($json['data']['provider']);
        $this->assertSame(0, $json['data']['tool_calls'] ?? -1);
    }

    public function test_api_purchasing_clarification_style_bank_question_returns_erp_bank_text(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchasing_bank_disclosure', true);

        Supplier::query()->create([
            'name' => 'Demo Supplier — Gulf Stone Imports',
            'contact' => 'orders@gulf-stone-demo.example',
            'payment_terms' => 'Net 30',
            'bank_details' => 'Demo Bank — Account DEMO-GSI-PHRASE',
        ]);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => "Could you please specify which supplier's bank details you need for Gulf Stone Imports",
        ])->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertStringContainsString('DEMO-GSI-PHRASE', (string) ($json['data']['message'] ?? ''));
        $this->assertNull($json['data']['provider']);
    }

    public function test_api_purchasing_bank_details_follow_up_for_other_supplier(): void
    {
        $this->seed(SupplierSeeder::class);

        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.assistant.enforce_purchasing_bank_disclosure', true);

        OpenAI::fake([]);

        $this->actingAsSanctum($this->createPurchasingApiUser());

        $first = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $conversationId = $first['data']['conversation_id'] ?? null;
        $this->assertIsString($conversationId);
        $this->assertStringContainsString('DEMO-GSI-001', (string) ($first['data']['message'] ?? ''));

        $second = $this->postJson('/api/assistant', [
            'conversation_id' => $conversationId,
            'message' => 'for Hill Country Quartz Co',
        ])->assertOk()->json();

        $this->assertTrue($second['success'] ?? false);
        $this->assertStringContainsString('HCQ', (string) ($second['data']['message'] ?? ''));
        $this->assertNull($second['data']['provider']);
    }

    public function test_api_claude_sales_bank_details_returns_deterministic_refusal_without_http(): void
    {
        Config::set('ai.provider', 'claude');
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.assistant.enforce_sales_bank_refusal', true);

        Http::fake();

        $this->actingAsSanctum($this->createSalesApiUser());

        $json = $this->postJson('/api/assistant', [
            'message' => 'Supplier bank details for Gulf Stone Imports',
        ])->assertOk()->json();

        $this->assertSame(SalesBankDetailsRefusal::REFUSAL_MESSAGE, $json['data']['message'] ?? null);
        $this->assertArrayHasKey('provider', $json['data']);
        $this->assertNull($json['data']['provider']);
        Http::assertNothingSent();
    }
}
