<?php

namespace App\Services\AI\Grounding;

use App\Models\Warehouse;

/**
 * Detects single-warehouse "how many slabs" questions that must be ERP-grounded.
 */
final class StockCountIntentDetector
{
    public function __construct(
        private readonly StockCountQuestionParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>|null  $inheritedProductFilters
     */
    public function requiresGrounding(string $resolvedQuestion, string $rawUserMessage, ?array $inheritedProductFilters = null): bool
    {
        if ($this->isWarehouseStockCountQuestion($resolvedQuestion)) {
            return true;
        }

        if ($this->isWarehouseOnlyFollowUp($rawUserMessage) && $inheritedProductFilters !== null) {
            return true;
        }

        return $this->parser->parse($resolvedQuestion, $inheritedProductFilters) !== null;
    }

    public function isWarehouseStockCountQuestion(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if (! $this->mentionsRegisteredWarehouse($trimmed)) {
            return false;
        }

        $countIntent = preg_match(
            '/\b(how many|do we have|available|in stock|stock level|quantity on hand)\b/i',
            $trimmed,
        ) === 1;

        $slabInventory = preg_match('/\bslabs?\b/i', $trimmed) === 1
            && (
                preg_match('/\b(how many|do we have|\d+(?:\.\d+)?\s*cm)\b/i', $trimmed) === 1
                || preg_match('/\b(in stock|available)\b/i', $trimmed) === 1
            );

        return $countIntent || $slabInventory;
    }

    public function isWarehouseOnlyFollowUp(string $text): bool
    {
        $trimmed = trim($text);

        return preg_match('/^(and\s+)?in\s+[A-Za-z][A-Za-z\s\-]+\??$/i', $trimmed) === 1;
    }

    private function mentionsRegisteredWarehouse(string $text): bool
    {
        $pattern = $this->registeredWarehouseNamePattern();

        return $pattern !== null && preg_match('/\b(?:'.$pattern.')\b/i', $text) === 1;
    }

    private function registeredWarehouseNamePattern(): ?string
    {
        $names = Warehouse::query()->orderBy('name')->pluck('name')
            ->map(static fn ($name) => is_string($name) ? trim($name) : '')
            ->filter(static fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return null;
        }

        $quoted = array_map(static fn (string $name): string => preg_quote($name, '/'), $names);

        return implode('|', $quoted);
    }
}
