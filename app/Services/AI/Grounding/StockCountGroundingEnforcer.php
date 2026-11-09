<?php

namespace App\Services\AI\Grounding;

use App\DTOs\AI\AiToolCall;
use App\Models\User;
use App\Services\AI\AiProviderProfile;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\StockLookupService;

final class StockCountGroundingEnforcer
{
    public function __construct(
        private readonly StockCountQuestionParser $parser,
        private readonly StockLookupService $stockLookupService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_stock_grounding', true);
    }

    /**
     * Provider-specific tool_choice for the first model hop on stock-count questions.
     *
     * @return string|array<string, mixed>|null
     */
    public function firstHopToolChoice(?string $provider = null): string|array|null
    {
        return match (AiProviderProfile::normalize($provider)) {
            'claude' => ['type' => 'tool', 'name' => 'checkStock'],
            'openai' => ['type' => 'function', 'name' => 'checkStock'],
            'ollama' => 'required',
            default => ['type' => 'function', 'name' => 'checkStock'],
        };
    }

    /**
     * @param  list<array{call: AiToolCall, result: ToolExecutionResult}>  $traces
     * @param  array<string, mixed>|null  $inheritedProductFilters
     */
    public function enforce(
        string $modelText,
        User $user,
        string $question,
        array $traces,
        ?array $inheritedProductFilters = null,
        ?string $rawUserMessage = null,
    ): string {
        if (! $this->isEnabled()) {
            return $modelText;
        }

        $snapshot = $this->resolveAuthoritativeSnapshot(
            $user,
            $question,
            $traces,
            $inheritedProductFilters,
            $rawUserMessage,
        );
        if ($snapshot === null) {
            return 'I could not verify inventory for that question against the ERP. Please include product (color/thickness) and warehouse (e.g. Houston, Dallas, Austin).';
        }

        if (($snapshot['found'] ?? false) !== true) {
            return $this->formatUnresolvedLookup($snapshot);
        }

        $levels = $snapshot['stock_levels'] ?? null;
        if (is_array($levels) && $levels !== []) {
            return $this->formatMultiStockLevels($levels);
        }

        return $this->formatCanonicalAnswer($snapshot);
    }

    /**
     * @param  list<array{call: AiToolCall, result: ToolExecutionResult}>  $traces
     * @param  array<string, mixed>|null  $inheritedProductFilters
     * @return array<string, mixed>|null
     */
    public function resolveAuthoritativeSnapshot(
        User $user,
        string $question,
        array $traces,
        ?array $inheritedProductFilters = null,
        ?string $rawUserMessage = null,
    ): ?array {
        foreach ($this->questionCandidates($question, $rawUserMessage) as $candidate) {
            $parsed = $this->parser->parse($candidate, $inheritedProductFilters);
            if ($parsed === null) {
                continue;
            }

            $direct = $this->stockLookupService->stockForProductAtWarehouseByAttributes(
                $user,
                $parsed['product'],
                $parsed['warehouse'],
            );

            if (($direct['found'] ?? false) === true) {
                return $direct;
            }

            if (isset($direct['reason'])) {
                return $direct;
            }
        }

        $traceData = $this->lastSuccessfulCheckStockData($traces);
        if ($traceData !== null && ($traceData['found'] ?? false) === true) {
            if (! empty($traceData['stock_levels']) && is_array($traceData['stock_levels'])) {
                return $traceData;
            }

            $reParsed = $this->parser->parse($question, $inheritedProductFilters)
                ?? ($rawUserMessage !== null ? $this->parser->parse($rawUserMessage, $inheritedProductFilters) : null);
            if ($reParsed !== null) {
                $directRetry = $this->stockLookupService->stockForProductAtWarehouseByAttributes(
                    $user,
                    $reParsed['product'],
                    $reParsed['warehouse'],
                );
                if (($directRetry['found'] ?? false) === true && ! empty($directRetry['stock_levels'])) {
                    return $directRetry;
                }
            }

            return $traceData;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function questionCandidates(string $resolvedQuestion, ?string $rawUserMessage): array
    {
        $candidates = [trim($resolvedQuestion)];
        if ($rawUserMessage !== null) {
            $raw = trim($rawUserMessage);
            if ($raw !== '' && ! in_array($raw, $candidates, true)) {
                $candidates[] = $raw;
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array{call: AiToolCall, result: ToolExecutionResult}>  $traces
     * @return array<string, mixed>|null
     */
    private function lastSuccessfulCheckStockData(array $traces): ?array
    {
        for ($i = count($traces) - 1; $i >= 0; $i--) {
            $trace = $traces[$i];
            if ($trace['call']->name !== 'checkStock' || ! $trace['result']->success) {
                continue;
            }

            $data = $trace['result']->data;
            if (! is_array($data) || ($data['found'] ?? false) !== true) {
                continue;
            }

            if (! empty($data['stock_levels']) && is_array($data['stock_levels'])) {
                return $data;
            }

            if (array_key_exists('slabs_on_hand', $data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function formatCanonicalAnswer(array $snapshot): string
    {
        $levels = $snapshot['stock_levels'] ?? null;
        if (is_array($levels) && $levels !== []) {
            return $this->formatMultiStockLevels($levels);
        }

        $product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
        $warehouse = is_array($snapshot['warehouse'] ?? null) ? $snapshot['warehouse'] : [];
        $name = is_string($product['name'] ?? null) ? $product['name'] : 'product';
        $thickness = is_string($product['thickness_cm'] ?? null) ? $product['thickness_cm'] : '';
        $sku = is_string($product['sku'] ?? null) ? $product['sku'] : '';
        $warehouseLabel = is_string($warehouse['name'] ?? null) ? $warehouse['name'] : 'warehouse';
        $count = (int) ($snapshot['slabs_on_hand'] ?? 0);

        $thicknessLabel = $thickness !== '' ? rtrim(rtrim($thickness, '0'), '.').' cm ' : '';

        if ($count === 0) {
            return "There are no {$thicknessLabel}{$name} slabs in stock at the {$warehouseLabel} warehouse"
                .($sku !== '' ? " (SKU {$sku})." : '.');
        }

        return "There are {$count} slabs of {$thicknessLabel}{$name} available at the {$warehouseLabel} warehouse"
            .($sku !== '' ? " (SKU {$sku})." : '.');
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     */
    private function formatMultiStockLevels(array $levels): string
    {
        $lines = [];
        foreach ($levels as $row) {
            if (! is_array($row)) {
                continue;
            }
            $product = is_array($row['product'] ?? null) ? $row['product'] : [];
            $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : [];
            $count = (int) ($row['slabs_on_hand'] ?? 0);
            $hasRecord = (bool) ($row['has_stock_record'] ?? false);
            if ($count === 0 && ! $hasRecord) {
                continue;
            }
            $material = is_string($product['material'] ?? null) ? $product['material'] : '';
            $color = is_string($product['color'] ?? null) ? $product['color'] : '';
            $thickness = is_string($product['thickness_cm'] ?? null) ? $product['thickness_cm'] : '';
            $warehouseLabel = is_string($warehouse['name'] ?? null) ? $warehouse['name'] : 'warehouse';
            $thicknessLabel = $thickness !== '' ? rtrim(rtrim($thickness, '0'), '.').' cm' : 'unspecified thickness';
            $variety = trim($color !== '' ? $color : (is_string($product['name'] ?? null) ? $product['name'] : 'product'));
            $type = $material !== '' ? "{$material} " : '';
            $sku = is_string($product['sku'] ?? null) ? $product['sku'] : '';
            $skuSuffix = $sku !== '' ? " (SKU {$sku})" : '';

            $countLabel = $count === 1 ? '1 slab' : "{$count} slabs";
            $lines[] = "- {$warehouseLabel}: {$countLabel} — {$type}{$variety}, {$thicknessLabel}{$skuSuffix}";
        }

        if ($lines === []) {
            $warehouseLabel = 'that warehouse';
            foreach ($levels as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : [];
                $name = is_string($warehouse['name'] ?? null) ? trim($warehouse['name']) : '';
                if ($name !== '') {
                    $warehouseLabel = $name;
                    break;
                }
            }

            return "There are 0 slabs in stock at {$warehouseLabel} for the products that matched your question.";
        }

        return "Available stock:\n".implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function formatUnresolvedLookup(array $snapshot): string
    {
        $reason = is_string($snapshot['reason'] ?? null) ? $snapshot['reason'] : 'lookup_failed';

        return match ($reason) {
            'ambiguous_product' => 'Multiple products matched those filters. Specify thickness (e.g. 2.00 cm vs 3.00 cm), material (Quartz vs Marble), or SKU, then ask again.',
            'product_not_found' => 'No product matched those filters in the ERP. Check color, material, thickness, or SKU.',
            'warehouse_not_found' => 'No warehouse matched. Use Houston, Dallas, or Austin.',
            'ambiguous_warehouse' => 'Several warehouses matched; name one warehouse (e.g. Austin).',
            default => 'Inventory could not be resolved for that question. Refine product and warehouse details.',
        };
    }

}
