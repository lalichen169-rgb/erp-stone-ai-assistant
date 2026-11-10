<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AssistantRequestService;
use App\Services\AI\AssistantSystemInstructionBuilder;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\Facades\Config;
use Tests\Support\ActsAsApiUser;
use Tests\TestCase;

class AssistantSystemInstructionBuilderTest extends TestCase
{
    use ActsAsApiUser;

    public function test_baseline_sales_instruction_mentions_restrictions(): void
    {
        $sales = $this->createSalesApiUser();
        $text = app(AssistantSystemInstructionBuilder::class)->build($sales, false);

        $this->assertStringContainsString('Sales role', $text);
        $this->assertStringContainsString('getLandedCost', $text);
        $this->assertStringContainsString('proposeDraftPurchaseOrder', $text);
        $this->assertStringContainsString('thickness_cm', $text);
    }

    public function test_compact_sales_omits_purchasing_draft_po_workflow_detail(): void
    {
        $sales = $this->createSalesApiUser();
        $text = app(AssistantSystemInstructionBuilder::class)->build($sales, true);

        $this->assertStringContainsString('Sales', $text);
        $this->assertStringContainsString('getLandedCost', $text);
        $this->assertStringContainsString('proposeDraftPurchaseOrder', $text);
        $this->assertStringNotContainsString('searchSuppliers first', $text);
        $this->assertStringContainsString('material', $text);
        $this->assertStringContainsString('variety', $text);
        $this->assertLessThan(
            strlen(app(AssistantSystemInstructionBuilder::class)->build($sales, false)),
            strlen($text),
        );
    }

    public function test_compact_purchasing_retains_draft_po_and_id_rules(): void
    {
        $purchasing = $this->createPurchasingApiUser();
        $text = app(AssistantSystemInstructionBuilder::class)->build($purchasing, true);

        $this->assertStringContainsString('Purchasing', $text);
        $this->assertStringContainsString('getLandedCost', $text);
        $this->assertStringContainsString('proposeDraftPurchaseOrder', $text);
        $this->assertStringContainsString('searchWarehouses', $text);
        $this->assertStringContainsString('warehouse_id', $text);
        $this->assertStringContainsString('resolveProductsForPurchase', $text);
        $this->assertStringContainsString('confirm', $text);
        $this->assertStringContainsString('never ask for sku', strtolower($text));
    }

    public function test_baseline_purchasing_draft_po_never_requests_sku_from_user(): void
    {
        $purchasing = $this->createPurchasingApiUser();
        $text = app(AssistantSystemInstructionBuilder::class)->build($purchasing, false);

        $this->assertStringContainsString('Never ask the user for SKU', $text);
        $this->assertStringContainsString('resolveProductsForPurchase', $text);
    }

    public function test_compact_tool_descriptions_reduce_json_without_changing_sales_tool_count(): void
    {
        $sales = $this->createSalesApiUser();
        $registry = app(ToolRegistry::class);

        Config::set('ai.provider', 'ollama');
        Config::set('ai.assistant.compact_tool_descriptions', false);
        $baselineJson = $this->toolsJsonChars($registry->definitionsFor($sales, 'ollama'));

        Config::set('ai.assistant.compact_tool_descriptions', true);
        $compactDefs = $registry->definitionsFor($sales, 'ollama');
        $compactJson = $this->toolsJsonChars($compactDefs);

        $this->assertCount(4, $compactDefs);
        $this->assertSame(
            ['checkStock', 'findOverduePurchaseOrders', 'searchProducts', 'searchPurchaseOrders'],
            collect($compactDefs)->pluck('name')->sort()->values()->all(),
        );
        $this->assertLessThan($baselineJson, $compactJson);
    }

    public function test_purchasing_tool_count_unchanged_with_compact_descriptions(): void
    {
        $purchasing = $this->createPurchasingApiUser();
        Config::set('ai.provider', 'ollama');
        Config::set('ai.assistant.compact_tool_descriptions', true);
        $this->assertCount(13, app(ToolRegistry::class)->definitionsFor($purchasing, 'ollama'));
    }

    public function test_compact_env_flags_do_not_apply_when_provider_is_not_ollama(): void
    {
        $sales = $this->createSalesApiUser();
        Config::set('ai.provider', 'openai');
        Config::set('ai.assistant.compact_system_instructions', true);
        Config::set('ai.assistant.compact_tool_descriptions', true);

        $builder = app(AssistantSystemInstructionBuilder::class);
        $baselineLen = strlen($builder->buildLegacy($sales));
        $service = app(AssistantRequestService::class);
        $build = new \ReflectionMethod(AssistantRequestService::class, 'buildSystemInstruction');
        $build->setAccessible(true);
        $instruction = $build->invoke($service, $sales);

        $this->assertSame($baselineLen, strlen($instruction));

        Config::set('ai.assistant.compact_tool_descriptions', true);
        $withCompactFlag = strlen($this->toolsJsonChars(app(ToolRegistry::class)->definitionsFor($sales, 'openai')));
        Config::set('ai.assistant.compact_tool_descriptions', false);
        $withoutCompactFlag = strlen($this->toolsJsonChars(app(ToolRegistry::class)->definitionsFor($sales, 'openai')));
        $this->assertSame($withoutCompactFlag, $withCompactFlag);
    }

    /**
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    private function toolsJsonChars(array $definitions): int
    {
        $encoded = json_encode(array_map(fn ($d) => [
            'name' => $d->name,
            'description' => $d->description,
            'parameters' => $d->parameters,
        ], $definitions), JSON_THROW_ON_ERROR);

        return strlen($encoded);
    }
}
