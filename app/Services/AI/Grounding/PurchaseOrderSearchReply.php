<?php

namespace App\Services\AI\Grounding;

use App\Models\User;
use App\Services\Erp\PurchaseOrderLookupService;

/**
 * Deterministic purchase order list/search for Sales and Purchasing (status view, no unit costs).
 */
final class PurchaseOrderSearchReply
{
    public function __construct(
        private readonly PurchaseOrderSearchQuestionParser $questionParser,
        private readonly PurchaseOrderLookupService $purchaseOrderLookupService,
        private readonly LandedCostIntentDetector $landedCostIntent,
        private readonly LandedCostQuestionParser $landedCostQuestionParser,
        private readonly OverduePurchaseOrderQuestionParser $overduePurchaseOrderQuestionParser,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_purchase_order_search_disclosure', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $user->isSales() && ! $user->isPurchasing()) {
            return null;
        }

        foreach ([$resolvedQuestion, $rawUserMessage] as $candidate) {
            $text = trim($candidate);
            if ($text === '' || $this->landedCostIntent->isLandedCostQuestion($text)) {
                continue;
            }

            if ($candidate === $rawUserMessage
                && $this->landedCostQuestionParser->isPurchaseOrderFollowUpPhrase($text)) {
                continue;
            }

            if ($this->overduePurchaseOrderQuestionParser->isOverduePurchaseOrderIntent($text)) {
                continue;
            }

            if (! $this->questionParser->isPurchaseOrderSearchIntent($text)) {
                continue;
            }

            $filters = $this->questionParser->extractSearchFilters($text);

            return $this->formatSearchResult($user, $filters, $text);
        }

        return null;
    }

    /**
     * @param  array{status?: string, supplier_name?: string, purchase_order_id?: int}  $filters
     */
    private function formatSearchResult(User $user, array $filters, string $sourceQuestion): string
    {
        $serviceFilters = $filters;
        if (isset($serviceFilters['purchase_order_id'])) {
            $serviceFilters = ['purchase_order_id' => $serviceFilters['purchase_order_id']];
        }

        $result = $this->purchaseOrderLookupService->search($user, $serviceFilters, 50, 1);
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $total = (int) ($result['meta']['total'] ?? count($rows));

        if ($total === 0) {
            if (isset($filters['purchase_order_id'])) {
                return 'No purchase order with ID '.$filters['purchase_order_id'].' exists in the ERP.';
            }

            return $this->formatEmptyResult($filters, $sourceQuestion);
        }

        if (isset($filters['purchase_order_id']) && $total === 1 && is_array($rows[0] ?? null)) {
            $row = $rows[0];
            $id = (int) ($row['purchase_order_id'] ?? 0);
            $status = is_string($row['status'] ?? null) ? $row['status'] : 'unknown';
            $supplier = is_string($row['supplier_name'] ?? null) ? $row['supplier_name'] : 'Unknown supplier';
            $orderDate = is_string($row['order_date'] ?? null) ? $row['order_date'] : 'n/a';

            return sprintf(
                'Purchase order #%d status is %s (%s — order date %s).',
                $id,
                $status,
                $supplier,
                $orderDate,
            );
        }

        $lines = ['Purchase orders ('.$total.'):'];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = $this->formatOrderLine($row);
        }

        $lastPage = (int) ($result['meta']['last_page'] ?? 1);
        if ($lastPage > 1) {
            $lines[] = 'Showing page 1 of '.$lastPage.'. Ask for the next page if you need more.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{status?: string, supplier_name?: string, purchase_order_id?: int}  $filters
     */
    private function formatEmptyResult(array $filters, string $sourceQuestion): string
    {
        $parts = [];
        if (isset($filters['status'])) {
            $parts[] = 'status '.$filters['status'];
        }
        if (isset($filters['supplier_name'])) {
            $parts[] = 'supplier '.$filters['supplier_name'];
        }
        if (isset($filters['purchase_order_id'])) {
            $parts[] = 'PO '.$filters['purchase_order_id'];
        }

        $scope = $parts === [] ? 'those filters' : implode(', ', $parts);

        return 'No purchase orders in the ERP matched '.$scope.'.';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatOrderLine(array $row): string
    {
        $id = (int) ($row['purchase_order_id'] ?? 0);
        $status = is_string($row['status'] ?? null) ? $row['status'] : 'unknown';
        $supplier = is_string($row['supplier_name'] ?? null) ? $row['supplier_name'] : 'Unknown supplier';
        $orderDate = is_string($row['order_date'] ?? null) ? $row['order_date'] : 'n/a';
        $lineCount = is_array($row['lines'] ?? null) ? count($row['lines']) : 0;

        return sprintf(
            '- PO #%d — %s — %s — order date %s — %d line(s)',
            $id,
            $status,
            $supplier,
            $orderDate,
            $lineCount,
        );
    }
}
