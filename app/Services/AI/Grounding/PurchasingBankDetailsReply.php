<?php

namespace App\Services\AI\Grounding;

use App\Models\User;
use App\Services\Erp\SupplierLookupService;

/**
 * Deterministic Purchasing replies for supplier bank-detail questions (ERP-backed).
 */
final class PurchasingBankDetailsReply
{
    public function __construct(
        private readonly SupplierBankDetailsIntentDetector $intentDetector,
        private readonly SupplierBankDetailsQuestionParser $questionParser,
        private readonly SupplierLookupService $supplierLookupService,
        private readonly LandedCostQuestionParser $landedCostQuestionParser,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_purchasing_bank_disclosure', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled() || ! $user->isPurchasing()) {
            return null;
        }

        $bankIntent = $this->intentDetector->isSupplierBankDetailsRequest($resolvedQuestion)
            || $this->intentDetector->isSupplierBankDetailsRequest($rawUserMessage);

        $hint = $this->questionParser->extractSupplierNameHint($resolvedQuestion)
            ?? $this->questionParser->extractSupplierNameHint($rawUserMessage)
            ?? $this->extractFollowUpSupplierHint($resolvedQuestion, $rawUserMessage);

        if (! $bankIntent && $hint === null) {
            return null;
        }

        if ($hint === null) {
            return null;
        }

        if ($this->landedCostQuestionParser->isPurchaseOrderReferenceHint($hint)) {
            return null;
        }

        if (! $bankIntent) {
            $bankIntent = $this->intentDetector->isSupplierBankDetailsRequest(
                'What are the bank details for '.$hint.'?',
            );
        }

        if (! $bankIntent) {
            return null;
        }

        $snapshot = $this->supplierLookupService->resolveSupplierBankDetails($user, null, $hint);

        return $this->formatUserFacingReply($snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function formatUserFacingReply(array $snapshot): string
    {
        if (($snapshot['found'] ?? false) !== true) {
            return match ($snapshot['reason'] ?? 'lookup_failed') {
                'supplier_not_found' => 'No supplier matched that name in the ERP. Check the supplier name and try again.',
                'ambiguous_supplier' => 'Multiple suppliers matched that name. Ask again with the full supplier name from the ERP.',
                'missing_filter' => 'Specify a supplier name or supplier_id to look up bank details.',
                default => 'Could not resolve supplier bank details for that request.',
            };
        }

        $supplier = is_array($snapshot['supplier'] ?? null) ? $snapshot['supplier'] : [];
        $name = is_string($supplier['name'] ?? null) ? $supplier['name'] : 'the supplier';
        $terms = is_string($supplier['payment_terms'] ?? null) ? $supplier['payment_terms'] : '';
        $bank = is_string($supplier['bank_details'] ?? null) ? trim($supplier['bank_details']) : '';

        if ($bank === '') {
            return "Supplier {$name} has no bank details on file.".($terms !== '' ? " Payment terms: {$terms}." : '');
        }

        $prefix = "Banking details for {$name}";
        if ($terms !== '') {
            $prefix .= " (payment terms: {$terms})";
        }

        return "{$prefix}:\n{$bank}";
    }

    private function extractFollowUpSupplierHint(string $resolvedQuestion, string $rawUserMessage): ?string
    {
        foreach ([$resolvedQuestion, $rawUserMessage] as $text) {
            $trimmed = trim($text);
            if (preg_match('/^(?:and\s+)?for\s+(?P<supplier>.+?)\??$/iu', $trimmed, $matches) === 1) {
                $supplier = trim($matches['supplier']);
                if ($supplier !== '' && ! $this->landedCostQuestionParser->isPurchaseOrderReferenceHint($supplier)) {
                    return $supplier;
                }
            }
        }

        return null;
    }
}
