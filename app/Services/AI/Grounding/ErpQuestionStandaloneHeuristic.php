<?php

namespace App\Services\AI\Grounding;

/**
 * Treats complete ERP intents as standalone questions (not vague follow-ups), even mid-conversation.
 */
final class ErpQuestionStandaloneHeuristic
{
    public function __construct(
        private readonly SupplierBankDetailsIntentDetector $bankIntent,
        private readonly SupplierBankDetailsQuestionParser $bankParser,
        private readonly LandedCostIntentDetector $landedCostIntent,
        private readonly LandedCostQuestionParser $landedCostParser,
        private readonly StockCountIntentDetector $stockCountIntent,
        private readonly StockCountQuestionParser $stockCountParser,
        private readonly PurchaseOrderSearchQuestionParser $purchaseOrderSearchParser,
        private readonly PurchaseOrderCreationIntentDetector $purchaseOrderCreationIntentDetector,
        private readonly ErpCrossDomainRequestDetector $crossDomainRequestDetector,
    ) {}

    public function isActionableStandalone(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->crossDomainRequestDetector->isExplicitIndependentDomainRequest($trimmed)) {
            return true;
        }

        if ($this->isActionableBankQuestion($trimmed)) {
            return true;
        }

        if ($this->isActionableLandedCostQuestion($trimmed)) {
            return true;
        }

        if ($this->stockCountIntent->isWarehouseStockCountQuestion($trimmed)) {
            return true;
        }

        if ($this->stockCountParser->parse($trimmed) !== null) {
            return true;
        }

        if ($this->purchaseOrderCreationIntentDetector->isPurchaseOrderCreationRequest($trimmed)) {
            return true;
        }

        if (preg_match('/\boverdue\b.*\bpurchase\s+orders?\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\b(find|list|show|search)\b.*\bpurchase\s+orders?\b/i', $trimmed) === 1) {
            return true;
        }

        if (! $this->purchaseOrderCreationIntentDetector->isPurchaseOrderCreationRequest($trimmed)
            && preg_match('/\bpurchase\s+orders?\b.*\b(status|ordered|received|draft)\b/i', $trimmed) === 1) {
            return true;
        }

        $poId = $this->landedCostParser->extractPurchaseOrderId($trimmed);
        if ($poId !== null && ! $this->isActionableLandedCostQuestion($trimmed)) {
            if ($this->landedCostParser->isPurchaseOrderFollowUpPhrase($trimmed)) {
                return false;
            }

            return $this->isExplicitPurchaseOrderLookupQuestion($trimmed);
        }

        return false;
    }

    public function canonicalQuestion(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if ($this->isActionableBankQuestion($trimmed)) {
            $hint = $this->bankParser->extractSupplierNameHint($trimmed);
            if ($hint !== null) {
                return 'What are the bank details for '.$hint.'?';
            }
        }

        if ($this->isActionableLandedCostQuestion($trimmed)) {
            $poId = $this->landedCostParser->extractPurchaseOrderId($trimmed);
            if ($poId !== null) {
                return 'What is the landed cost for purchase order '.$poId.'?';
            }
        }

        if ($this->purchaseOrderSearchParser->isPurchaseOrderSearchIntent($trimmed)) {
            $filters = $this->purchaseOrderSearchParser->extractSearchFilters($trimmed);

            return $this->purchaseOrderSearchParser->buildCanonicalSearchQuestion($filters, $trimmed);
        }

        return null;
    }

    private function isActionableBankQuestion(string $text): bool
    {
        if (! $this->bankIntent->isSupplierBankDetailsRequest($text)) {
            return false;
        }

        return $this->bankParser->extractSupplierNameHint($text) !== null;
    }

    private function isActionableLandedCostQuestion(string $text): bool
    {
        if (! $this->landedCostIntent->isLandedCostQuestion($text)) {
            return false;
        }

        return $this->landedCostParser->extractPurchaseOrderId($text) !== null;
    }

    private function isExplicitPurchaseOrderLookupQuestion(string $text): bool
    {
        if ($this->landedCostParser->isPurchaseOrderReferenceHint($text)) {
            return true;
        }

        if (preg_match('/\bwhat\s+is\s+the\s+status\b.*\bpurchase\s+order\b/i', $text) === 1) {
            return true;
        }

        return preg_match('/\b(show|list|find|search|status)\b.*\bpurchase\s+order\b/i', $text) === 1;
    }
}
