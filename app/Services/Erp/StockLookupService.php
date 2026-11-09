<?php

namespace App\Services\Erp;

use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use App\Services\Erp\Concerns\ResolvesProductAttributeFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockLookupService
{
    use AuthorizesErpUser;
    use ResolvesProductAttributeFilters;

    /**
     * Search products by SKU, name, material, color, and thickness.
     *
     * @param  array{sku?: string, name?: string, material?: string, color?: string, thickness_cm?: string|float|int}  $filters
     * @return list<array<string, mixed>>
     */
    public function searchProducts(User $user, array $filters = [], int $limit = 50): array
    {
        $this->authorizeProductsAndStock($user);

        $resolved = $this->resolveProducts($filters);

        return $resolved['products']
            ->sortBy('sku')
            ->take($limit)
            ->map(fn (Product $product) => $this->productSummary($product))
            ->values()
            ->all();
    }

    /**
     * Slabs on hand for one product at one warehouse (0 if no stock row exists).
     *
     * @return array<string, mixed>
     */
    public function stockForProductAtWarehouse(User $user, int $productId, int $warehouseId): array
    {
        $this->authorizeProductsAndStock($user);

        $product = Product::query()->find($productId);
        $warehouse = Warehouse::query()->find($warehouseId);

        if ($product === null || $warehouse === null) {
            return [
                'found' => false,
                'product' => $product ? $this->productSummary($product) : null,
                'warehouse' => $warehouse ? $this->warehouseSummary($warehouse) : null,
                'slabs_on_hand' => 0,
                'has_stock_record' => false,
            ];
        }

        return $this->buildStockResult($product, $warehouse);
    }

    /**
     * Resolve product and warehouse from attribute filters (e.g. Calacatta, 3cm, Houston).
     *
     * @param  array{sku?: string, name?: string, material?: string, color?: string, thickness_cm?: string|float|int}  $productFilters
     * @param  array{name?: string, city?: string}  $warehouseFilters
     * @return array<string, mixed>
     */
    public function stockForProductAtWarehouseByAttributes(
        User $user,
        array $productFilters,
        array $warehouseFilters
    ): array {
        $this->authorizeProductsAndStock($user);

        $resolved = $this->resolveProducts($productFilters);
        $products = $resolved['products'];

        if ($products->isEmpty()) {
            return [
                'found' => false,
                'reason' => 'product_not_found',
                'product' => null,
                'warehouse' => null,
                'slabs_on_hand' => 0,
                'has_stock_record' => false,
                'effective_filters' => $resolved['coerced_filters'] ?? [],
            ];
        }

        $warehouseQuery = Warehouse::query();
        $this->applyWarehouseFilters($warehouseQuery, $warehouseFilters);
        $warehouses = $warehouseQuery->get();

        if ($products->count() > 1 && $warehouses->count() === 1) {
            $effective = is_array($resolved['coerced_filters'] ?? null) ? $resolved['coerced_filters'] : [];
            $skuSpecified = ! empty($effective['sku']);

            if (! $skuSpecified) {
                $levels = $this->stockLevelRowsFor($products, $warehouses);
                $payload = [
                    'found' => true,
                    'multi_product' => true,
                    'stock_levels' => $levels,
                    'effective_filters' => $effective,
                    'stock_summary' => 'found=true; list each stock_levels row with warehouse, material, thickness_cm, SKU, and slabs_on_hand.',
                ];
                if ($resolved['resolution'] !== null) {
                    $payload['filter_resolution'] = $resolved['resolution'];
                }

                return $payload;
            }
        }

        if ($products->count() > 1) {
            return [
                'found' => false,
                'reason' => 'ambiguous_product',
                'matching_products' => $products->map(fn (Product $p) => $this->productSummary($p))->values()->all(),
                'slabs_on_hand' => 0,
                'has_stock_record' => false,
            ];
        }

        if ($warehouses->isEmpty()) {
            return [
                'found' => false,
                'reason' => 'warehouse_not_found',
                'product' => $this->productSummary($products->first()),
                'warehouse' => null,
                'slabs_on_hand' => 0,
                'has_stock_record' => false,
            ];
        }

        if ($warehouses->count() > 1) {
            return [
                'found' => false,
                'reason' => 'ambiguous_warehouse',
                'product' => $this->productSummary($products->first()),
                'matching_warehouses' => $warehouses->map(fn (Warehouse $w) => $this->warehouseSummary($w))->values()->all(),
                'slabs_on_hand' => 0,
                'has_stock_record' => false,
            ];
        }

        $result = $this->buildStockResult($products->first(), $warehouses->first());

        if ($resolved['resolution'] !== null) {
            $result['filter_resolution'] = $resolved['resolution'];
        }

        return $result;
    }

    /**
     * Stock levels for one product in every warehouse (missing rows reported as 0 slabs).
     *
     * @return array{found: bool, product: ?array<string, mixed>, warehouses: list<array<string, mixed>>}
     */
    public function stockForProductAllWarehouses(User $user, int $productId): array
    {
        $this->authorizeProductsAndStock($user);

        $product = Product::query()->find($productId);

        if ($product === null) {
            return [
                'found' => false,
                'product' => null,
                'warehouses' => [],
            ];
        }

        $stockByWarehouse = Stock::query()
            ->with('warehouse')
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('warehouse_id');

        $rows = Warehouse::query()
            ->orderBy('name')
            ->get()
            ->map(function (Warehouse $warehouse) use ($stockByWarehouse) {
                $stock = $stockByWarehouse->get($warehouse->id);

                return [
                    'warehouse' => $this->warehouseSummary($warehouse),
                    'slabs_on_hand' => $stock ? (int) $stock->slabs_on_hand : 0,
                    'has_stock_record' => $stock !== null,
                ];
            })
            ->values()
            ->all();

        return [
            'found' => true,
            'product' => $this->productSummary($product),
            'warehouses' => $rows,
        ];
    }

    /**
     * Search stock rows by product and warehouse attributes (one row per product/warehouse pair).
     *
     * @param  array{sku?: string, name?: string, material?: string, color?: string, thickness_cm?: string|float|int}  $productFilters
     * @param  array{name?: string, city?: string}  $warehouseFilters
     * @return list<array<string, mixed>>
     */
    public function searchStockLevels(
        User $user,
        array $productFilters = [],
        array $warehouseFilters = [],
        int $limit = 100
    ): array {
        $this->authorizeProductsAndStock($user);

        $resolved = $this->resolveProducts($productFilters);

        $query = Stock::query()
            ->with(['product', 'warehouse'])
            ->whereHas('warehouse', fn (Builder $q) => $this->applyWarehouseFilters($q, $warehouseFilters));

        if ($resolved['products']->isNotEmpty()) {
            $query->whereIn('product_id', $resolved['products']->pluck('id'));
        } else {
            $query->whereRaw('0 = 1');
        }

        return $query
            ->orderBy('product_id')
            ->orderBy('warehouse_id')
            ->limit($limit)
            ->get()
            ->map(fn (Stock $stock) => $this->formatStockLevelRow($stock))
            ->values()
            ->all();
    }

    /**
     * Stock rows for explicit product and warehouse sets (existing stock records only).
     *
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Warehouse>  $warehouses
     * @return list<array<string, mixed>>
     */
    private function stockLevelRowsFor(Collection $products, Collection $warehouses): array
    {
        if ($products->isEmpty() || $warehouses->isEmpty()) {
            return [];
        }

        $stockByPair = Stock::query()
            ->with(['product', 'warehouse'])
            ->whereIn('product_id', $products->pluck('id'))
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->get()
            ->keyBy(fn (Stock $stock) => $stock->product_id.'-'.$stock->warehouse_id);

        $rows = [];
        foreach ($products as $product) {
            foreach ($warehouses as $warehouse) {
                $key = $product->id.'-'.$warehouse->id;
                $stock = $stockByPair->get($key);

                if ($stock !== null) {
                    $rows[] = $this->formatStockLevelRow($stock);

                    continue;
                }

                $rows[] = [
                    'product' => $this->productSummary($product),
                    'warehouse' => $this->warehouseSummary($warehouse),
                    'slabs_on_hand' => 0,
                    'has_stock_record' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStockLevelRow(Stock $stock): array
    {
        return [
            'product' => $this->productSummary($stock->product),
            'warehouse' => $this->warehouseSummary($stock->warehouse),
            'slabs_on_hand' => (int) $stock->slabs_on_hand,
            'has_stock_record' => true,
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array{sku?: string, name?: string, material?: string, color?: string, thickness_cm?: string|float|int}  $filters
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

        if (! empty($filters['colors']) && is_array($filters['colors'])) {
            $colors = array_values(array_filter($filters['colors'], static fn (mixed $c) => is_string($c) && trim($c) !== ''));
            if ($colors !== []) {
                $query->where(function (Builder $inner) use ($colors): void {
                    foreach ($colors as $color) {
                        $inner->orWhere('color', 'like', '%'.trim((string) $color).'%');
                    }
                });
            }
        } elseif (! empty($filters['color'])) {
            $query->where('color', 'like', '%'.$filters['color'].'%');
        }

        if (isset($filters['thickness_cm']) && $filters['thickness_cm'] !== '') {
            $thickness = number_format((float) $filters['thickness_cm'], 2, '.', '');
            $query->where('thickness_cm', $thickness);
        }

        return $query;
    }

    /**
     * @param  Builder<Warehouse>  $query
     * @param  array{name?: string, city?: string}  $filters
     */
    private function applyWarehouseFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        if (! empty($filters['city'])) {
            $query->where('city', 'like', '%'.$filters['city'].'%');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStockResult(Product $product, Warehouse $warehouse): array
    {
        $stock = Stock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        return [
            'found' => true,
            'product' => $this->productSummary($product),
            'warehouse' => $this->warehouseSummary($warehouse),
            'slabs_on_hand' => $stock ? (int) $stock->slabs_on_hand : 0,
            'has_stock_record' => $stock !== null,
        ];
    }

}
