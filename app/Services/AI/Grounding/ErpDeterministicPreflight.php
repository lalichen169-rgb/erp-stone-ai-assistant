<?php

namespace App\Services\AI\Grounding;

use App\Models\User;

/**
 * Decides whether clarification can be skipped because a deterministic ERP handler can answer.
 */
final class ErpDeterministicPreflight
{
    public function __construct(
        private readonly ErpQuestionStandaloneHeuristic $standaloneHeuristic,
        private readonly SalesPurchaseOrderCreationRefusal $salesPurchaseOrderCreationRefusal,
        private readonly SalesBankDetailsRefusal $salesBankRefusal,
        private readonly PurchasingBankDetailsReply $purchasingBankDetailsReply,
        private readonly PurchasingLandedCostReply $purchasingLandedCostReply,
        private readonly StockAcrossWarehousesReply $stockAcrossWarehousesReply,
        private readonly StockWarehouseCountReply $stockWarehouseCountReply,
        private readonly PurchaseOrderSearchReply $purchaseOrderSearchReply,
        private readonly OverduePurchaseOrderSearchReply $overduePurchaseOrderSearchReply,
        private readonly SalesSupplierOverduePurchaseOrderAnalysisRefusal $salesSupplierOverdueAnalysisRefusal,
        private readonly BroadProductAvailabilityReply $broadProductAvailabilityReply,
    ) {}

    public function shouldBypassClarification(User $user, string $rawMessage, string $resolvedQuestion): bool
    {
        if ($this->standaloneHeuristic->isActionableStandalone($rawMessage)
            || $this->standaloneHeuristic->isActionableStandalone($resolvedQuestion)) {
            return true;
        }

        if ($this->salesPurchaseOrderCreationRefusal->refusalMessageFor($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->salesSupplierOverdueAnalysisRefusal->refusalMessageFor($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->salesBankRefusal->refusalMessageFor($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->purchasingBankDetailsReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->purchasingLandedCostReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->broadProductAvailabilityReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->stockAcrossWarehousesReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->stockWarehouseCountReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->overduePurchaseOrderSearchReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        if ($this->purchaseOrderSearchReply->tryBuildReply($user, $resolvedQuestion, $rawMessage) !== null) {
            return true;
        }

        return false;
    }

    public function preferredResolvedQuestion(string $rawMessage, string $currentResolved): string
    {
        $canonical = $this->standaloneHeuristic->canonicalQuestion($rawMessage)
            ?? $this->standaloneHeuristic->canonicalQuestion($currentResolved);

        if ($canonical !== null) {
            return $canonical;
        }

        if ($currentResolved !== '' && $currentResolved !== $rawMessage) {
            return $currentResolved;
        }

        return $rawMessage;
    }
}
