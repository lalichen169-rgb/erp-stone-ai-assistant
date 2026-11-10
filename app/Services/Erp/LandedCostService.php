<?php

namespace App\Services\Erp;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use App\Services\Erp\Exceptions\LandedCostCalculationException;

class LandedCostService
{
    use AuthorizesErpUser;

    /**
     * Value-based freight allocation; read-only — does not persist changes.
     *
     * @return array<string, mixed>
     */
    public function calculateForPurchaseOrder(User $user, int $purchaseOrderId): array
    {
        $this->authorizeLandedCosts($user);

        $order = PurchaseOrder::query()
            ->with(['supplier:id,name', 'lines.product:id,sku,name'])
            ->find($purchaseOrderId);

        if ($order === null) {
            throw new LandedCostCalculationException('Purchase order not found.');
        }

        if ($order->lines->isEmpty()) {
            throw new LandedCostCalculationException('Purchase order has no lines.');
        }

        $freightCost = ErpMoney::formatCurrency(ErpMoney::fromDecimal((string) $order->freight_cost));

        $lineContexts = [];

        foreach ($order->lines as $line) {
            if ($line->quantity <= 0) {
                throw new LandedCostCalculationException(
                    'Invalid line quantity: quantity must be greater than zero.'
                );
            }

            $quantity = (string) $line->quantity;
            $unitCost = ErpMoney::formatCurrency(ErpMoney::fromDecimal((string) $line->unit_cost));
            $lineValue = ErpMoney::roundCurrency(ErpMoney::mul($quantity, $unitCost));

            $lineContexts[] = [
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_sku' => $line->product->sku,
                'product_name' => $line->product->name,
                'quantity' => (int) $line->quantity,
                'unit_cost' => $unitCost,
                'line_value' => $lineValue,
                'line_value_raw' => ErpMoney::mul($quantity, $unitCost),
            ];
        }

        $totalLineValue = array_reduce(
            $lineContexts,
            fn (string $carry, array $ctx) => ErpMoney::add($carry, $ctx['line_value_raw']),
            '0'
        );

        if (ErpMoney::compare($totalLineValue, '0') <= 0) {
            throw new LandedCostCalculationException(
                'Cannot allocate freight: total line value is zero.'
            );
        }

        $roundedShares = [];
        $exactShares = [];

        foreach ($lineContexts as $index => $ctx) {
            if (ErpMoney::compare($freightCost, '0') === 0) {
                $exact = '0';
            } else {
                $ratio = ErpMoney::div($ctx['line_value_raw'], $totalLineValue);
                $exact = ErpMoney::mul($freightCost, $ratio);
            }

            $exactShares[$index] = $exact;
            $roundedShares[$index] = ErpMoney::roundCurrency($exact);
        }

        $allocatedFreight = array_reduce(
            $roundedShares,
            fn (string $carry, string $share) => ErpMoney::add($carry, $share),
            '0'
        );

        $remainder = ErpMoney::sub($freightCost, ErpMoney::roundCurrency($allocatedFreight));

        if (ErpMoney::compare($remainder, '0') !== 0) {
            $targetIndex = $this->indexOfLargestLineValue($lineContexts);
            $roundedShares[$targetIndex] = ErpMoney::formatCurrency(
                ErpMoney::add($roundedShares[$targetIndex], $remainder)
            );
        }

        $lines = [];

        foreach ($lineContexts as $index => $ctx) {
            $freightShare = $roundedShares[$index];
            $quantity = (string) $ctx['quantity'];
            $freightPerUnit = ErpMoney::compare($freightShare, '0') === 0
                ? '0.00'
                : ErpMoney::roundCurrency(ErpMoney::div($freightShare, $quantity));
            $landedUnitCost = ErpMoney::roundCurrency(ErpMoney::add($ctx['unit_cost'], $freightPerUnit));

            $lines[] = [
                'line_id' => $ctx['line_id'],
                'product_id' => $ctx['product_id'],
                'product_sku' => $ctx['product_sku'],
                'product_name' => $ctx['product_name'],
                'quantity' => $ctx['quantity'],
                'unit_cost' => $ctx['unit_cost'],
                'line_value' => $ctx['line_value'],
                'freight_share' => $freightShare,
                'freight_per_unit' => $freightPerUnit,
                'landed_unit_cost' => $landedUnitCost,
            ];
        }

        $reconciledTotal = array_reduce(
            $lines,
            fn (string $carry, array $line) => ErpMoney::add($carry, $line['freight_share']),
            '0'
        );

        return [
            'purchase_order_id' => $order->id,
            'supplier_name' => $order->supplier->name,
            'freight_cost' => $freightCost,
            'total_line_value' => ErpMoney::roundCurrency($totalLineValue),
            'freight_reconciliation' => [
                'allocated_freight_total' => ErpMoney::formatCurrency($reconciledTotal),
                'matches_po_freight' => ErpMoney::compare(
                    ErpMoney::formatCurrency($reconciledTotal),
                    $freightCost
                ) === 0,
                'remainder_applied_to_line_id' => ErpMoney::compare($remainder, '0') !== 0
                    ? $lineContexts[$this->indexOfLargestLineValue($lineContexts)]['line_id']
                    : null,
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @param  list<array{line_value_raw: string}>  $lineContexts
     */
    private function indexOfLargestLineValue(array $lineContexts): int
    {
        $bestIndex = 0;
        $bestValue = $lineContexts[0]['line_value_raw'];

        foreach ($lineContexts as $index => $ctx) {
            if (ErpMoney::compare($ctx['line_value_raw'], $bestValue) > 0) {
                $bestValue = $ctx['line_value_raw'];
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }
}
