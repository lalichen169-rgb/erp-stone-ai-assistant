<?php

namespace App\Services\AI\Grounding;

use App\Models\User;

/**
 * Deterministic Sales-role soft denial for supplier-specific delayed/overdue PO analysis.
 */
final class SalesSupplierOverduePurchaseOrderAnalysisRefusal
{
    public const REFUSAL_MESSAGE = 'Supplier-specific delayed purchase order analysis is available to the Purchasing role only. '
        .'You can still check individual purchase-order statuses and view existing purchase-order information.';

    public function __construct(
        private readonly OverduePurchaseOrderQuestionParser $overdueQuestionParser,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_sales_supplier_overdue_po_analysis_refusal', true);
    }

    public function refusalMessageFor(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled() || ! $user->isSales()) {
            return null;
        }

        if ($this->overdueQuestionParser->isSupplierSpecificOverdueAnalysis($resolvedQuestion)) {
            return self::REFUSAL_MESSAGE;
        }

        if ($rawUserMessage !== $resolvedQuestion
            && $this->overdueQuestionParser->isSupplierSpecificOverdueAnalysis($rawUserMessage)) {
            return self::REFUSAL_MESSAGE;
        }

        return null;
    }
}
