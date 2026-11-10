<?php

namespace App\Services\AI\Grounding;

use App\Models\User;

/**
 * Deterministic ERP stock counts for a single warehouse (all matching stone types when material omitted).
 */
final class StockWarehouseCountReply
{
    public function __construct(
        private readonly StockCountIntentDetector $stockCountIntentDetector,
        private readonly StockAcrossWarehousesIntentDetector $acrossWarehousesIntentDetector,
        private readonly StockCountGroundingEnforcer $stockCountGroundingEnforcer,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_stock_grounding', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $user->isSales() && ! $user->isPurchasing()) {
            return null;
        }

        if ($this->acrossWarehousesIntentDetector->matches($resolvedQuestion, $rawUserMessage)) {
            return null;
        }

        $question = trim($resolvedQuestion) !== '' ? $resolvedQuestion : $rawUserMessage;
        if (! $this->stockCountIntentDetector->isWarehouseStockCountQuestion($question)
            && ! $this->stockCountIntentDetector->isWarehouseStockCountQuestion($rawUserMessage)) {
            return null;
        }

        $reply = $this->stockCountGroundingEnforcer->enforce(
            '',
            $user,
            $question,
            [],
            null,
            $rawUserMessage,
        );

        if (str_starts_with($reply, 'I could not verify inventory')) {
            return null;
        }

        return $reply;
    }
}
