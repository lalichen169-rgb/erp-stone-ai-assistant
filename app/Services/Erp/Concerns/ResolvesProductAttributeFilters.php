<?php

namespace App\Services\Erp\Concerns;

use App\Models\Product;
use App\Support\ProductColorLexicon;
use App\Support\ThicknessCmNormalizer;
use Illuminate\Support\Collection;

trait ResolvesProductAttributeFilters
{
    /**
     * @param  array{sku?: string, name?: string, material?: string, color?: string, thickness_cm?: string|float|int}  $productFilters
     * @return array{sku?: string, name?: string, material?: string, color?: string, thickness_cm?: string}
     */
    private function coerceProductFilters(array $productFilters): array
    {
        $filters = $productFilters;

        $lexicon = app(ProductColorLexicon::class);

        if (! empty($filters['material']) && empty($filters['color'])) {
            $materialRaw = trim((string) $filters['material']);

            $colorInMaterial = $lexicon->matchInText($materialRaw);
            $stoneType = null;
            if (preg_match('/\b(quartz|marble|granite|travertine|porcelain)\b/i', $materialRaw, $stone) === 1) {
                $stoneType = ucfirst(strtolower($stone[1]));
            }

            if ($colorInMaterial !== null && $stoneType !== null) {
                $filters['color'] = $colorInMaterial;
                $filters['material'] = $stoneType;
            } elseif ($lexicon->isKnownColor($materialRaw)) {
                $filters['color'] = $materialRaw;
                unset($filters['material']);
            }
        }

        if (empty($filters['color']) && ! empty($filters['name'])) {
            $colorFromName = $lexicon->matchInText((string) $filters['name']);
            if ($colorFromName !== null) {
                $filters['color'] = $colorFromName;
            }
        }

        if (isset($filters['thickness_cm']) && $filters['thickness_cm'] !== '') {
            $normalized = ThicknessCmNormalizer::normalizeOrNull($filters['thickness_cm']);
            if ($normalized !== null) {
                $filters['thickness_cm'] = $normalized;
            } else {
                unset($filters['thickness_cm']);
            }
        }

        if (! empty($filters['colors']) && is_array($filters['colors'])) {
            $filters['colors'] = array_values(array_filter(array_map(
                static fn (mixed $color) => is_string($color) ? trim($color) : '',
                $filters['colors'],
            ), static fn (string $color) => $color !== ''));
            if ($filters['colors'] === []) {
                unset($filters['colors']);
            } elseif (count($filters['colors']) === 1) {
                $filters['color'] = $filters['colors'][0];
                unset($filters['colors']);
            }
        }

        if (! empty($filters['color']) && is_string($filters['color'])) {
            $filters['color'] = trim($filters['color']);
        }

        foreach (['sku', 'name', 'material', 'color'] as $key) {
            if (isset($filters[$key]) && is_string($filters[$key]) && trim($filters[$key]) === '') {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    /**
     * @param  array{sku?: string, name?: string, material?: string, color?: string, colors?: list<string>, thickness_cm?: string|float|int}  $productFilters
     * @return array{
     *     products: Collection<int, Product>,
     *     resolution: ?string,
     *     coerced_filters: array<string, mixed>,
     *     input_filters: array<string, mixed>
     * }
     */
    private function resolveProducts(array $productFilters): array
    {
        $inputFilters = $this->coerceProductFilters($productFilters);
        $effectiveFilters = $inputFilters;
        $hadMaterialFilter = isset($inputFilters['material']) && $inputFilters['material'] !== '';

        $products = $this->applyProductFilters(Product::query(), $effectiveFilters)->get();
        $resolution = null;
        $materialTrusted = $hadMaterialFilter && $products->isNotEmpty();

        if ($products->isEmpty() && $hadMaterialFilter) {
            $relaxed = $inputFilters;
            unset($relaxed['material']);
            $effectiveFilters = $relaxed;
            $products = $this->applyProductFilters(Product::query(), $effectiveFilters)->get();
            if ($products->isNotEmpty()) {
                $resolution = 'material_filter_relaxed';
            }
        }

        if ($products->count() > 1 && $materialTrusted) {
            $picked = $this->pickPreferredProduct($products, $inputFilters);
            if ($picked !== null) {
                $products = collect([$picked]);
                $resolution = $resolution ?? 'ambiguous_product_narrowed_by_material';
            }
        }

        return [
            'products' => $products,
            'resolution' => $resolution,
            'coerced_filters' => $effectiveFilters,
            'input_filters' => $inputFilters,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $filters
     */
    private function pickPreferredProduct(Collection $products, array $filters): ?Product
    {
        $material = isset($filters['material']) && is_string($filters['material'])
            ? trim($filters['material']) : '';

        if ($material !== '') {
            $byMaterial = $products->filter(
                fn (Product $product) => strcasecmp($product->material, $material) === 0
                    || stripos($product->material, $material) !== false,
            );
            if ($byMaterial->count() === 1) {
                return $byMaterial->first();
            }
        }

        return null;
    }
}
