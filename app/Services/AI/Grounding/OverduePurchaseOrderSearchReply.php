<?php

namespace App\Services\AI\Grounding;

use App\Models\User;
use App\Services\Erp\OverduePurchaseOrderService;
use App\Services\Erp\SupplierFilterResolution;
use Illuminate\Support\Carbon;

/**
 * Deterministic overdue / delayed PO answers for Purchasing (ERP-backed).
 */
final class OverduePurchaseOrderSearchReply
{
    public function __construct(
        private readonly OverduePurchaseOrderQuestionParser $questionParser,
        private readonly OverduePurchaseOrderService $overduePurchaseOrderService,
        private readonly SupplierFilterResolution $supplierFilterResolution,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_overdue_purchase_order_disclosure', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled() || ! $user->isPurchasing()) {
            return null;
        }

        foreach ([$resolvedQuestion, $rawUserMessage] as $candidate) {
            $text = trim($candidate);
            if ($text === '' || ! $this->questionParser->isOverduePurchaseOrderIntent($text)) {
                continue;
            }

            $supplierHint = $this->questionParser->extractSupplierNameHint($text);
            $asOf = Carbon::today();

            if ($supplierHint !== null && $supplierHint !== '') {
                $resolution = $this->supplierFilterResolution->resolveByNameHint($supplierHint);
                if ($resolution['status'] === 'not_found') {
                    return 'No supplier in the ERP matched "'.$supplierHint.'".';
                }
                if ($resolution['status'] === 'ambiguous') {
                    $names = collect($resolution['matches'] ?? [])
                        ->pluck('name')
                        ->filter(fn ($n) => is_string($n) && $n !== '')
                        ->take(5)
                        ->implode(', ');

                    return 'Several suppliers match "'.$supplierHint.'". Please specify the full supplier name'
                        .($names !== '' ? ' (e.g. '.$names.')' : '')
                        .'.';
                }
                if ($resolution['status'] !== 'ok' || ! isset($resolution['supplier_id'])) {
                    continue;
                }

                $orders = $this->overduePurchaseOrderService->listOverdue(
                    $user,
                    $asOf,
                    OverduePurchaseOrderService::DEFAULT_LIMIT,
                    (int) $resolution['supplier_id'],
                );

                return $this->formatOrders($orders, $supplierHint, scoped: true);
            }

            $orders = $this->overduePurchaseOrderService->listOverdue($user, $asOf);

            return $this->formatOrders($orders, null, scoped: false);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    private function formatOrders(array $orders, ?string $supplierLabel, bool $scoped): string
    {
        $count = count($orders);
        if ($count === 0) {
            if ($scoped && $supplierLabel !== null) {
                return 'No overdue purchase orders were found for '.$supplierLabel.' in the ERP.';
            }

            return 'No overdue purchase orders were found in the ERP.';
        }

        $header = $scoped && $supplierLabel !== null
            ? 'Overdue purchase orders for '.$supplierLabel.' ('.$count.'):'
            : 'Overdue purchase orders ('.$count.'):';

        $lines = [$header];
        foreach ($orders as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['purchase_order_id'] ?? 0);
            $supplier = is_string($row['supplier_name'] ?? null) ? $row['supplier_name'] : 'Unknown supplier';
            $status = is_string($row['status'] ?? null) ? $row['status'] : 'unknown';
            $eta = is_string($row['eta'] ?? null) ? $row['eta'] : 'n/a';
            $days = (int) ($row['days_overdue'] ?? 0);

            $lines[] = sprintf(
                '- PO #%d — %s — status %s — ETA %s — %d day(s) overdue',
                $id,
                $supplier,
                $status,
                $eta,
                $days,
            );
        }

        return implode("\n", $lines);
    }
}
