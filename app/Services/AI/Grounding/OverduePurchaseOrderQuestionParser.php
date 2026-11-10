<?php

namespace App\Services\AI\Grounding;

/**
 * Natural-language detection for overdue / delayed purchase order questions.
 */
final class OverduePurchaseOrderQuestionParser
{
    public function __construct(
        private readonly PurchaseOrderSearchQuestionParser $purchaseOrderSearchQuestionParser,
    ) {}

    public function isOverduePurchaseOrderIntent(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        $hasPo = preg_match('/\b(purchase\s+orders?|POs?)\b/i', $trimmed) === 1;
        if (! $hasPo) {
            return false;
        }

        return preg_match(
            '/\b(overdue|delayed|late|past\s+(?:their\s+)?expected\s+delivery(?:\s+date)?|expected\s+delivery\s+date\s+passed)\b/i',
            $trimmed,
        ) === 1;
    }

    /**
     * Supplier-specific delayed/overdue analysis (named supplier), not aggregate "which suppliers" listings.
     */
    public function isSupplierSpecificOverdueAnalysis(string $text): bool
    {
        if (! $this->isOverduePurchaseOrderIntent($text)) {
            return false;
        }

        $trimmed = trim($text);

        if (preg_match('/\bwhich\s+suppliers?\b/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/\bgrouped\s+by\s+supplier\b/i', $trimmed) === 1
            && preg_match('/\bfrom\s+/i', $trimmed) !== 1) {
            return false;
        }

        return $this->extractSupplierNameHint($text) !== null;
    }

    public function extractSupplierNameHint(string $text): ?string
    {
        $hint = $this->purchaseOrderSearchQuestionParser->extractSupplierNameHint($text);
        if ($hint === null || trim($hint) === '') {
            return null;
        }

        $cleaned = preg_replace(
            '/\s+(?:are|is|that\s+have|which\s+have)\s+(?:delayed|overdue|late)\b.*$/iu',
            '',
            trim($hint),
        );

        $cleaned = is_string($cleaned) ? trim($cleaned) : trim($hint);

        return $cleaned !== '' ? $cleaned : null;
    }
}
