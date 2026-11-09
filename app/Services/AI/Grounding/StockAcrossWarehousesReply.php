<?php

namespace App\Services\AI\Grounding;

use App\Models\User;
use App\Services\Erp\StockLookupService;

/**
 * Deterministic multi-warehouse, multi-thickness stock disclosure (ERP-backed).
 */
final class StockAcrossWarehousesReply
{
    public function __construct(
        private readonly StockAcrossWarehousesIntentDetector $intentDetector,
        private readonly StockCountQuestionParser $questionParser,
        private readonly StockLookupService $stockLookupService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_stock_across_warehouses_disclosure', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $user->isSales() && ! $user->isPurchasing()) {
            return null;
        }

        if (! $this->intentDetector->matches($resolvedQuestion, $rawUserMessage)) {
            return null;
        }

        $question = trim($resolvedQuestion) !== '' ? $resolvedQuestion : $rawUserMessage;
        $filters = $this->questionParser->productFiltersFromQuestion($question, defaultMaterialToQuartz: false);

        if ($filters === []) {
            return null;
        }

        $levels = $this->stockLookupService->searchStockLevels($user, $filters, [], 200);

        return $this->formatReply($filters, $levels);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $levels
     */
    public function formatReply(array $filters, array $levels): string
    {
        $variety = is_string($filters['color'] ?? null) ? $filters['color'] : 'matching products';

        if ($levels === []) {
            return 'No in-stock slabs matched '.$variety.' across warehouses for those filters in the ERP.';
        }

        $sorted = collect($levels)
            ->sortBy([
                fn (array $row) => is_array($row['warehouse'] ?? null) ? ($row['warehouse']['name'] ?? '') : '',
                fn (array $row) => is_array($row['product'] ?? null) ? ($row['product']['material'] ?? '') : '',
                fn (array $row) => is_array($row['product'] ?? null) ? ($row['product']['thickness_cm'] ?? '') : '',
            ])
            ->values()
            ->all();

        $lines = [];
        foreach ($sorted as $row) {
            $product = is_array($row['product'] ?? null) ? $row['product'] : [];
            $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : [];
            $count = (int) ($row['slabs_on_hand'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $material = is_string($product['material'] ?? null) ? $product['material'] : '';
            $color = is_string($product['color'] ?? null) ? $product['color'] : $variety;
            $thickness = is_string($product['thickness_cm'] ?? null) ? $product['thickness_cm'] : '';
            $slabSize = is_string($product['slab_size'] ?? null) ? trim($product['slab_size']) : '';
            $warehouseLabel = is_string($warehouse['name'] ?? null) ? $warehouse['name'] : 'warehouse';
            $thicknessLabel = $thickness !== '' ? rtrim(rtrim($thickness, '0'), '.').' cm' : 'unspecified thickness';
            $type = $material !== '' ? "{$material} " : '';

            $line = "- {$warehouseLabel}: {$count} slabs — {$type}{$color}, {$thicknessLabel}";
            if ($slabSize !== '') {
                $line .= ", slab size {$slabSize}";
            }
            $lines[] = $line;
        }

        if ($lines === []) {
            return 'No in-stock slabs matched '.$variety.' across warehouses for those filters in the ERP.';
        }

        return 'Available '.$variety." stock across all warehouses:\n".implode("\n", $lines);
    }
}
