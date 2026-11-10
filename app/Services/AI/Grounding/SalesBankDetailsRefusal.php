<?php

namespace App\Services\AI\Grounding;

use App\Models\User;

/**
 * Deterministic Sales-role refusal for supplier bank / wire detail questions (no LLM hop).
 */
final class SalesBankDetailsRefusal
{
    public const REFUSAL_MESSAGE = 'Supplier bank details are not available to the Sales role. Contact Purchasing or finance for wire instructions.';

    public function __construct(
        private readonly SupplierBankDetailsIntentDetector $intentDetector,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_sales_bank_refusal', true);
    }

    public function refusalMessageFor(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled() || ! $user->isSales()) {
            return null;
        }

        if ($this->intentDetector->isSupplierBankDetailsRequest($resolvedQuestion)) {
            return self::REFUSAL_MESSAGE;
        }

        if ($rawUserMessage !== $resolvedQuestion
            && $this->intentDetector->isSupplierBankDetailsRequest($rawUserMessage)) {
            return self::REFUSAL_MESSAGE;
        }

        return null;
    }
}
