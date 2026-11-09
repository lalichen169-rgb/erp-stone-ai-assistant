<?php

namespace App\Services\Erp;

use App\Models\Product;
use App\Support\ProductColorLexicon;
use App\Support\ThicknessCmNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Strict catalogue resolution for purchasing (no silent material relaxation or arbitrary picks).
 */
final class ProductCatalogResolutionService
{
    /**
     * @param  array{
     *     sku?: string,
     *     name?: string,
     *     material?: string,
     *     color?: string,
     *     thickness_cm?: string|float|int|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function resolve(array $filters, int $limit = 25): array
    {
        $inputFilters = $this->normalizeFilters($filters);
        $requestedMaterial = isset($filters['material']) && trim((string) $filters['material']) !== ''
            ? trim((string) $filters['material']) : null;
        $requestedThickness = isset($inputFilters['thickness_cm']) ? (string) $inputFilters['thickness_cm'] : null;

        $products = $this->applyProductFilters(Product::query(), $inputFilters)->get();

        if ($products->isEmpty() && $requestedMaterial !== null) {
            $withoutMaterial = $inputFilters;
            unset($withoutMaterial['material']);
            $relaxedMatches = $this->applyProductFilters(Product::query(), $withoutMaterial)->get();

            return [
                'status' => 'unmatched_attribute',
                'product_id' => null,
                'matched_product' => null,
                'alternatives' => $this->summarizeProducts($relaxedMatches->take($limit)),
                'unmatched_requested_attributes' => ['material' => $requestedMaterial],
                'clarification_required' => true,
                'clarification_reason' => 'material_not_satisfied',
                'message' => 'No product matched the requested material with the other filters.',
                'effective_filters' => $inputFilters,
            ];
        }

        if ($products->isEmpty()) {
            return [
                'status' => 'not_found',
                'product_id' => null,
                'matched_product' => null,
                'alternatives' => [],
                'unmatched_requested_attributes' => $this->unmatchedAttributes($inputFilters, $products),
                'clarification_required' => false,
                'clarification_reason' => 'product_not_found',
                'message' => 'No matching catalogue product was found.',
                'effective_filters' => $inputFilters,
            ];
        }

        if ($products->count() === 1) {
            $product = $products->first();

            return [
                'status' => 'unique_match',
                'product_id' => $product->id,
                'matched_product' => $this->productSummary($product),
                'alternatives' => [],
                'unmatched_requested_attributes' => [],
                'clarification_required' => false,
                'clarification_reason' => null,
                'message' => null,
                'effective_filters' => $inputFilters,
            ];
        }

        if ($requestedThickness === null && $this->distinctThicknessCount($products) > 1) {
            return [
                'status' => 'ambiguous_product',
                'product_id' => null,
                'matched_product' => null,
                'alternatives' => $this->summarizeProducts($products->take($limit)),
                'unmatched_requested_attributes' => ['thickness_cm' => null],
                'clarification_required' => true,
                'clarification_reason' => 'missing_thickness',
                'message' => 'Multiple products match; specify thickness_cm to disambiguate.',
                'effective_filters' => $inputFilters,
            ];
        }

        return [
            'status' => 'ambiguous_product',
            'product_id' => null,
            'matched_product' => null,
            'alternatives' => $this->summarizeProducts($products->take($limit)),
            'unmatched_requested_attributes' => [],
            'clarification_required' => true,
            'clarification_reason' => 'ambiguous_product',
            'message' => 'Multiple catalogue products matched the request. Choose one before creating a proposal.',
            'effective_filters' => $inputFilters,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        $lexicon = app(ProductColorLexicon::class);

        foreach (['sku', 'name', 'material', 'color'] as $key) {
            if (! empty($filters[$key]) && is_string($filters[$key])) {
                $value = trim($filters[$key]);
                if ($value !== '') {
                    $normalized[$key] = $value;
                }
            }
        }

        if (! empty($normalized['material']) && empty($normalized['color'])) {
            $materialRaw = $normalized['material'];
            $colorInMaterial = $lexicon->matchInText($materialRaw);
            $stoneType = null;
            if (preg_match('/\b(quartz|marble|granite|travertine|porcelain)\b/i', $materialRaw, $stone) === 1) {
                $stoneType = ucfirst(strtolower($stone[1]));
            }
            if ($colorInMaterial !== null && $stoneType !== null) {
                $normalized['color'] = $colorInMaterial;
                $normalized['material'] = $stoneType;
            } elseif ($lexicon->isKnownColor($materialRaw)) {
                $normalized['color'] = $materialRaw;
                unset($normalized['material']);
            }
        }

        if (empty($normalized['color']) && ! empty($normalized['name'])) {
            $colorFromName = $lexicon->matchInText($normalized['name']);
            if ($colorFromName !== null) {
                $normalized['color'] = $colorFromName;
            }
        }

        if (array_key_exists('thickness_cm', $filters) && $filters['thickness_cm'] !== null && $filters['thickness_cm'] !== '') {
            $thickness = ThicknessCmNormalizer::normalizeOrNull($filters['thickness_cm']);
            if ($thickness !== null) {
                $normalized['thickness_cm'] = $thickness;
            }
        }

        return $normalized;
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    private function applyProductFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['sku'])) {
            $query->where('sku', $filters['sku']);
        }

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        if (! empty($filters['material'])) {
            $query->where('material', 'like', '%'.$filters['material'].'%');
        }

        if (! empty($filters['color'])) {
            $query->where('color', 'like', '%'.$filters['color'].'%');
        }

        if (isset($filters['thickness_cm']) && $filters['thickness_cm'] !== '') {
            $thickness = number_format((float) $filters['thickness_cm'], 2, '.', '');
            $query->where('thickness_cm', $thickness);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    private function summarizeProducts(Collection $products): array
    {
        return $products
            ->sortBy('sku')
            ->values()
            ->map(fn (Product $product) => $this->productSummary($product))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function productSummary(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'material' => $product->material,
            'color' => $product->color,
            'thickness_cm' => (string) $product->thickness_cm,
            'slab_size' => $product->slab_size,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function distinctThicknessCount(Collection $products): int
    {
        return $products->pluck('thickness_cm')->unique()->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     */
    private function unmatchedAttributes(array $filters, Collection $products): array
    {
        if ($products->isNotEmpty()) {
            return [];
        }

        $unmatched = [];
        foreach (['sku', 'material', 'color', 'thickness_cm'] as $key) {
            if (! empty($filters[$key])) {
                $unmatched[$key] = $filters[$key];
            }
        }

        return $unmatched;
    }
}
