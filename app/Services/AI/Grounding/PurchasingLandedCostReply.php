<?php

namespace App\Services\AI\Grounding;

use App\Models\User;
use App\Services\Erp\ErpMoney;
use App\Services\Erp\Exceptions\LandedCostCalculationException;
use App\Services\Erp\LandedCostService;

final class PurchasingLandedCostReply
{
    public function __construct(
        private readonly LandedCostIntentDetector $intentDetector,
        private readonly LandedCostQuestionParser $questionParser,
        private readonly LandedCostService $landedCostService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_purchasing_landed_cost_disclosure', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled() || ! $user->isPurchasing()) {
            return null;
        }

        $poId = $this->questionParser->extractPurchaseOrderId($resolvedQuestion)
            ?? $this->questionParser->extractPurchaseOrderId($rawUserMessage);

        if ($poId === null) {
            return null;
        }

        $landedIntent = $this->intentDetector->isLandedCostQuestion($resolvedQuestion)
            || $this->intentDetector->isLandedCostQuestion($rawUserMessage)
            || $this->questionParser->isPurchaseOrderFollowUpPhrase($rawUserMessage)
            || $this->questionParser->isPurchaseOrderFollowUpPhrase($resolvedQuestion)
            || $this->intentDetector->isLandedCostQuestion(
                'What is the landed cost for purchase order '.$poId.'?',
            );

        if (! $landedIntent) {
            return null;
        }

        try {
            $data = $this->landedCostService->calculateForPurchaseOrder($user, $poId);
        } catch (LandedCostCalculationException) {
            return 'No purchase order with id '.$poId.' was found, or it has no lines for landed cost calculation.';
        }

        return $this->formatReply($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function formatReply(array $data): string
    {
        $poId = (int) ($data['purchase_order_id'] ?? 0);
        $supplier = is_string($data['supplier_name'] ?? null) ? $data['supplier_name'] : 'supplier';
        $freight = is_string($data['freight_cost'] ?? null) ? $data['freight_cost'] : '0.00';
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        if ($lines === []) {
            return 'Purchase order '.$poId.' has no lines to calculate landed cost.';
        }

        $parts = [
            "Landed cost for PO {$poId} ({$supplier}):",
            'Freight: '.$this->formatMoneyDisplay($freight),
            '',
        ];

        $poLandedTotalRaw = '0';

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $sku = is_string($line['product_sku'] ?? null) ? $line['product_sku'] : '';
            $name = is_string($line['product_name'] ?? null) ? $line['product_name'] : 'line';
            $qty = (int) ($line['quantity'] ?? 0);
            $landed = is_string($line['landed_unit_cost'] ?? null) ? $line['landed_unit_cost'] : '0.00';
            $lineTotalRaw = $qty > 0
                ? ErpMoney::mul((string) $qty, $landed)
                : '0';
            $lineTotal = ErpMoney::roundCurrency($lineTotalRaw);
            $poLandedTotalRaw = ErpMoney::add($poLandedTotalRaw, $lineTotal);

            $slabLabel = $qty === 1 ? 'slab' : 'slabs';
            if ($sku !== '') {
                $parts[] = "{$sku} ({$name}) × {$qty}:";
            } else {
                $parts[] = "{$name} × {$qty}:";
            }
            $parts[] = '  Landed unit cost: '.$this->formatMoneyDisplay($landed);
            $parts[] = '  Line landed total: '.$this->formatMoneyDisplay($lineTotal)
                ." ({$qty} {$slabLabel} × ".$this->formatMoneyDisplay($landed).')';
            $parts[] = '';
        }

        $parts[] = 'Total PO landed cost: '.$this->formatMoneyDisplay(ErpMoney::roundCurrency($poLandedTotalRaw));

        return rtrim(implode("\n", $parts));
    }

    private function formatMoneyDisplay(string $amount): string
    {
        $normalized = ErpMoney::formatCurrency(ErpMoney::fromDecimal($amount));

        return number_format((float) $normalized, 2, '.', ',');
    }
}
