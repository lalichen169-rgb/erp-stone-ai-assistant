<?php

namespace App\Services\AI\Grounding;

/**
 * Detects full standalone ERP questions that must not be expanded as short follow-ups on another domain thread.
 */
final class ErpCrossDomainRequestDetector
{
    public function __construct(
        private readonly PurchaseOrderSearchQuestionParser $purchaseOrderSearchParser,
        private readonly OverduePurchaseOrderQuestionParser $overduePurchaseOrderQuestionParser,
        private readonly StockCountIntentDetector $stockCountIntentDetector,
        private readonly StockCountQuestionParser $stockCountQuestionParser,
        private readonly SupplierBankDetailsIntentDetector $supplierBankDetailsIntentDetector,
        private readonly LandedCostIntentDetector $landedCostIntentDetector,
        private readonly PurchaseOrderCreationIntentDetector $purchaseOrderCreationIntentDetector,
    ) {}

    /**
     * User message is a complete new ERP request (product list, PO search, stock, etc.), not a terse filter follow-up.
     */
    public function isExplicitIndependentDomainRequest(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->isProductCatalogListingRequest($trimmed)) {
            return true;
        }

        if ($this->isSupplierCatalogListingRequest($trimmed)) {
            return true;
        }

        if ($this->stockCountIntentDetector->isWarehouseStockCountQuestion($trimmed)) {
            return true;
        }

        if ($this->stockCountQuestionParser->parse($trimmed) !== null) {
            return true;
        }

        if ($this->overduePurchaseOrderQuestionParser->isOverduePurchaseOrderIntent($trimmed)) {
            return true;
        }

        if ($this->purchaseOrderSearchParser->isPurchaseOrderSearchIntent($trimmed)
            && $this->isFullPurchaseOrderListingOrStatusQuestion($trimmed)) {
            return true;
        }

        if ($this->landedCostIntentDetector->isLandedCostQuestion($trimmed)) {
            return true;
        }

        if ($this->purchaseOrderCreationIntentDetector->isPurchaseOrderCreationRequest($trimmed)) {
            return true;
        }

        if ($this->supplierBankDetailsIntentDetector->isSupplierBankDetailsRequest($trimmed)) {
            return true;
        }

        return false;
    }

    public function isProductCatalogListingRequest(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/\bavailable\s+products?\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match(
            '/\b(?:show|list|find|search|what are|give me)\b.{0,48}\b(?:all\s+)?(?:available\s+)?products?\b/i',
            $trimmed,
        ) === 1) {
            return true;
        }

        return preg_match(
            '/\bproducts?\b.{0,36}\b(?:catalog(?:ue)?|in\s+(?:the\s+)?erp)\b/i',
            $trimmed,
        ) === 1;
    }

    private function isFullPurchaseOrderListingOrStatusQuestion(string $text): bool
    {
        if (preg_match('/^\s*(?:PO\s*[-#]?\s*)?\d+\s*\??\s*$/i', $text) === 1) {
            return false;
        }

        return preg_match(
            '/\b(?:find|list|show|search|what\s+is\s+the\s+status)\b/i',
            $text,
        ) === 1;
    }

    public function isSupplierCatalogListingRequest(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/\bpurchase\s+orders?\b/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/\boverdue\b/i', $trimmed) === 1) {
            return false;
        }

        return preg_match(
            '/\b(?:show|list|find|search|what are|give me)\b.{0,48}\b(?:all\s+)?suppliers?\b/i',
            $trimmed,
        ) === 1;
    }

    /**
     * User explicitly widens scope (all products, all warehouses, etc.).
     */
    public function isExplicitBroadeningInstruction(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->isProductCatalogListingRequest($trimmed)) {
            return true;
        }

        if (preg_match('/\b(?:show|list)\s+all\s+products?\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\bacross\s+all\s+warehouses?\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\bremove\s+(?:the\s+)?warehouse\s+filter\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\b(?:show|include)\s+all\s+thicknesses?\b/i', $trimmed) === 1) {
            return true;
        }

        return preg_match('/\b(?:include|all)\s+stone\s+types?\b/i', $trimmed) === 1;
    }

    /**
     * Do not merge stock-thread product filters (color, thickness) from earlier turns into this message.
     */
    public function shouldResetInheritedStockProductFilters(string $rawUserMessage, ?string $resolvedQuestion = null): bool
    {
        $raw = trim($rawUserMessage);
        $resolved = is_string($resolvedQuestion) ? trim($resolvedQuestion) : '';

        $candidates = array_values(array_unique(array_filter([$resolved, $raw], static fn (string $t) => $t !== '')));

        foreach ($candidates as $text) {
            if ($this->isExplicitBroadeningInstruction($text)) {
                return true;
            }
        }

        if ($this->stockCountIntentDetector->isWarehouseOnlyFollowUp($raw)) {
            return false;
        }

        if (preg_match('/^(?:actually,?\s+)?check\s+\d+/i', $raw) === 1) {
            return false;
        }

        if (preg_match('/^(what\s+about|and\s+for)\s+\d+(\.\d+)?\s*cm\b/i', $raw) === 1) {
            return false;
        }

        foreach ($candidates as $text) {
            if ($this->stockCountQuestionParser->parse($text) !== null) {
                return true;
            }

            if ($this->stockCountIntentDetector->isWarehouseStockCountQuestion($text)
                && ! $this->stockCountIntentDetector->isWarehouseOnlyFollowUp($text)) {
                return true;
            }
        }

        if ($this->isExplicitIndependentDomainRequest($raw)
            && ! $this->stockCountIntentDetector->isWarehouseOnlyFollowUp($raw)) {
            return true;
        }

        return false;
    }
}
