<?php

namespace App\Services\AI\Grounding;

use App\Models\User;

/**
 * Deterministic Sales-role refusal for draft PO creation / proposal requests (no LLM hop).
 */
final class SalesPurchaseOrderCreationRefusal
{
    public const REFUSAL_MESSAGE = 'Creating purchase orders is available to the Purchasing role only. '
        .'As Sales, you can still search existing purchase orders and check their statuses. '
        .'Please ask Purchasing if you need a new order drafted.';

    public function __construct(
        private readonly PurchaseOrderCreationIntentDetector $intentDetector,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_sales_po_creation_refusal', true);
    }

    public function refusalMessageFor(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled() || ! $user->isSales()) {
            return null;
        }

        if ($this->intentDetector->isPurchaseOrderCreationRequest($resolvedQuestion)) {
            return self::REFUSAL_MESSAGE;
        }

        if ($rawUserMessage !== $resolvedQuestion
            && $this->intentDetector->isPurchaseOrderCreationRequest($rawUserMessage)) {
            return self::REFUSAL_MESSAGE;
        }

        return null;
    }
}
