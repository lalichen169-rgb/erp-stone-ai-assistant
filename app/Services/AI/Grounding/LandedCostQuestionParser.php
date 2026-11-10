<?php

namespace App\Services\AI\Grounding;

final class LandedCostQuestionParser
{
    public function extractPurchaseOrderId(string $text): ?int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(?:and\s+)?for\s+(?:purchase\s+order\s*|PO\s*[-#]?\s*)?(?P<id>\d{1,9})\??$/iu', $trimmed, $matches) === 1) {
            return (int) $matches['id'];
        }

        if (preg_match('/^(?:and\s+)?(?:for\s+)?(?:PO\s*[-#]?\s*)?(?P<id>\d{1,9})\??$/iu', $trimmed, $matches) === 1) {
            return (int) $matches['id'];
        }

        if (preg_match('/^what\s+about\s+(?:PO\s*[-#]?\s*)?(?P<id>\d{1,9})\??$/iu', $trimmed, $matches) === 1) {
            return (int) $matches['id'];
        }

        if (preg_match('/\b(?:purchase\s+order|PO)\s*[-#]?\s*(?P<id>\d{1,9})\b/i', $trimmed, $matches) === 1) {
            return (int) $matches['id'];
        }

        if (preg_match('/\bpurchase\s+order\s+#(?P<id>\d{1,9})\b/i', $trimmed, $matches) === 1) {
            return (int) $matches['id'];
        }

        if (preg_match('/\blanded\s*cost\b.*\b(?P<id>\d{1,9})\b/i', $trimmed, $matches) === 1) {
            return (int) $matches['id'];
        }

        return null;
    }

    public function isPurchaseOrderReferenceHint(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^PO\s*[-#]?\s*\d{1,9}\s*$/iu', $trimmed) === 1
            || preg_match('/^purchase\s+order\s*[-#]?\s*\d{1,9}\s*$/iu', $trimmed) === 1;
    }

    public function isPurchaseOrderFollowUpPhrase(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->extractPurchaseOrderId($trimmed) === null) {
            return false;
        }

        return preg_match('/^(?:and\s+)?for\s+/iu', $trimmed) === 1
            || preg_match('/^what\s+about\s+/iu', $trimmed) === 1
            || $this->isPurchaseOrderReferenceHint($trimmed);
    }
}
