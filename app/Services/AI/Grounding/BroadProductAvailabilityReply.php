<?php

namespace App\Services\AI\Grounding;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deterministic in-stock product availability across the ERP (not a single prior stock-thread filter).
 */
final class BroadProductAvailabilityReply
{
    public function __construct(
        private readonly ErpCrossDomainRequestDetector $crossDomainRequestDetector,
        private readonly StockCountQuestionParser $stockCountQuestionParser,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_broad_product_availability_disclosure', true);
    }

    public function tryBuildReply(User $user, string $resolvedQuestion, string $rawUserMessage): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $user->isSales() && ! $user->isPurchasing()) {
            return null;
        }

        $question = trim($resolvedQuestion) !== '' ? trim($resolvedQuestion) : trim($rawUserMessage);
        if ($question === '') {
            return null;
        }

        if (! $this->crossDomainRequestDetector->isProductCatalogListingRequest($question)
            && ! $this->crossDomainRequestDetector->isProductCatalogListingRequest($rawUserMessage)) {
            return null;
        }

        $explicitFilters = $this->stockCountQuestionParser->productFiltersFromQuestion($question, defaultMaterialToQuartz: false);
        if ($explicitFilters !== [] && (! empty($explicitFilters['color']) || ! empty($explicitFilters['sku']))) {
            return null;
        }

        $limit = max(1, min(200, (int) config('ai.assistant.broad_product_availability_limit', 200)));

        $rows = Stock::query()
            ->with(['product', 'warehouse'])
            ->where('slabs_on_hand', '>', 0)
            ->whereHas('product')
            ->whereHas('warehouse')
            ->orderBy('product_id')
            ->orderBy('warehouse_id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return 'No slabs are currently in stock at any warehouse in the ERP for the catalogue.';
        }

        $lines = [];
        foreach ($rows as $stock) {
            $product = $stock->product;
            $warehouse = $stock->warehouse;
            if ($product === null || $warehouse === null) {
                continue;
            }

            $material = is_string($product->material) ? trim($product->material) : '';
            $color = is_string($product->color) ? trim($product->color) : '';
            $name = is_string($product->name) ? trim($product->name) : '';
            $variety = $color !== '' ? $color : $name;
            $type = $material !== '' ? "{$material} " : '';
            $thickness = $product->thickness_cm !== null ? rtrim(rtrim((string) $product->thickness_cm, '0'), '.').' cm' : '';
            $count = (int) $stock->slabs_on_hand;
            $sku = is_string($product->sku) ? trim($product->sku) : '';
            $skuSuffix = $sku !== '' ? " (SKU {$sku})" : '';

            $thicknessLabel = $thickness !== '' ? $thickness : 'unspecified thickness';
            $lines[] = "- {$type}{$variety}, {$thicknessLabel}, {$count} slab"
                .($count === 1 ? '' : 's')." in {$warehouse->name}{$skuSuffix}";
        }

        if ($lines === []) {
            return 'No slabs are currently in stock at any warehouse in the ERP for the catalogue.';
        }

        return "In-stock slabs in the ERP (up to {$limit} rows):\n".implode("\n", $lines);
    }
}
