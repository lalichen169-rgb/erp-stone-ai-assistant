<?php

namespace App\Services\AI\Grounding;

/**
 * Detects natural-language requests to create or propose a purchase order (not read-only PO queries).
 */
final class PurchaseOrderCreationIntentDetector
{
    public function isPurchaseOrderCreationRequest(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->isReadOnlyPurchaseOrderQuestion($trimmed)) {
            return false;
        }

        if (preg_match('/\b(confirm|cancel)\b.*\b(proposal|draft)\b.*\b(purchase\s*order|PO)\b/i', $trimmed) === 1) {
            return false;
        }

        return preg_match(
            '/\b(create|make|propose|place|submit|raise|start)\b/i',
            $trimmed,
        ) === 1
            && preg_match('/\b(draft\s*)?(purchase\s*orders?|POs?)\b/i', $trimmed) === 1;
    }

    private function isReadOnlyPurchaseOrderQuestion(string $text): bool
    {
        if (preg_match('/\bwhat\s+is\s+the\s+status\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\b(show|list|find|search)\b/i', $text) === 1
            && preg_match('/\b(purchase\s*orders?|POs?)\b/i', $text) === 1
            && preg_match('/\b(create|make|propose|place|submit|raise|start)\b/i', $text) !== 1) {
            return true;
        }

        return preg_match('/\b(overdue|landed\s*cost)\b.*\bpurchase\s*orders?\b/i', $text) === 1;
    }
}
