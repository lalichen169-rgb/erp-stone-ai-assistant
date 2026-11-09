<?php

namespace App\Services\AI\Grounding;

/**
 * Detects variety stock questions spanning all warehouses (every thickness × site).
 */
final class StockAcrossWarehousesIntentDetector
{
    public function __construct(
        private readonly StockCountQuestionParser $questionParser,
    ) {}

    public function matches(string $resolvedQuestion, string $rawUserMessage): bool
    {
        foreach ([$resolvedQuestion, $rawUserMessage] as $text) {
            $trimmed = trim($text);
            if ($trimmed === '') {
                continue;
            }

            if (! $this->mentionsAcrossAllWarehouses($trimmed)) {
                continue;
            }

            $filters = $this->questionParser->productFiltersFromQuestion($trimmed, defaultMaterialToQuartz: false);
            if ($filters === [] || (empty($filters['color']) && empty($filters['sku']))) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function mentionsAcrossAllWarehouses(string $text): bool
    {
        if (preg_match('/\bacross\s+(?:all\s+)?warehouses?\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\b(?:all|every|each)\s+warehouses?\b/i', $text) === 1) {
            return true;
        }

        return preg_match('/\bwarehouses?\b.*\b(?:all|every|each)\b/i', $text) === 1;
    }
}
