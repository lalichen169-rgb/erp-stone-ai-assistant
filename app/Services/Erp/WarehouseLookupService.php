<?php

namespace App\Services\Erp;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use Illuminate\Database\Eloquent\Builder;

final class WarehouseLookupService
{
    use AuthorizesErpUser;

    /**
     * @return list<array{id: int, name: string, city: string}>
     */
    public function listReceivingWarehouses(User $user, int $limit = 50): array
    {
        $this->authorizeProductsAndStock($user);

        return Warehouse::query()
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Warehouse $warehouse) => $this->warehouseSummary($warehouse))
            ->all();
    }

    /**
     * @param  array{warehouse_id?: int, name?: string, city?: string}  $filters
     * @return array<string, mixed>
     */
    public function resolve(User $user, array $filters, int $limit = 25): array
    {
        $this->authorizeProductsAndStock($user);

        if (! empty($filters['warehouse_id'])) {
            $warehouse = Warehouse::query()->find((int) $filters['warehouse_id']);
            if ($warehouse === null) {
                return [
                    'status' => 'not_found',
                    'warehouse_id' => null,
                    'warehouse' => null,
                    'alternatives' => [],
                    'clarification_required' => false,
                    'message' => 'Warehouse not found.',
                ];
            }

            return [
                'status' => 'unique_match',
                'warehouse_id' => $warehouse->id,
                'warehouse' => $this->warehouseSummary($warehouse),
                'alternatives' => [],
                'clarification_required' => false,
                'message' => null,
            ];
        }

        $query = Warehouse::query();
        $this->applyFilters($query, $filters);
        $matches = $query->orderBy('name')->limit($limit + 1)->get();

        if ($matches->isEmpty()) {
            return [
                'status' => 'not_found',
                'warehouse_id' => null,
                'warehouse' => null,
                'alternatives' => [],
                'clarification_required' => false,
                'message' => 'No warehouse matched the provided filters.',
            ];
        }

        if ($matches->count() === 1) {
            $warehouse = $matches->first();

            return [
                'status' => 'unique_match',
                'warehouse_id' => $warehouse->id,
                'warehouse' => $this->warehouseSummary($warehouse),
                'alternatives' => [],
                'clarification_required' => false,
                'message' => null,
            ];
        }

        if ($matches->count() > $limit) {
            $matches = $matches->take($limit);
        }

        return [
            'status' => 'ambiguous_warehouse',
            'warehouse_id' => null,
            'warehouse' => null,
            'alternatives' => $matches->map(fn (Warehouse $w) => $this->warehouseSummary($w))->values()->all(),
            'clarification_required' => true,
            'message' => 'Multiple warehouses matched. Ask the user to choose a destination.',
        ];
    }

    public function requireWarehouseOrFail(?int $warehouseId): Warehouse
    {
        if ($warehouseId === null || $warehouseId <= 0) {
            throw new \InvalidArgumentException('warehouse_id_required');
        }

        $warehouse = Warehouse::query()->find($warehouseId);
        if ($warehouse === null) {
            throw new \InvalidArgumentException('warehouse_not_found');
        }

        return $warehouse;
    }

    /**
     * @param  Builder<Warehouse>  $query
     * @param  array{name?: string, city?: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.trim((string) $filters['name']).'%');
        }
        if (! empty($filters['city'])) {
            $query->where('city', 'like', '%'.trim((string) $filters['city']).'%');
        }
    }

    /**
     * @return array{id: int, name: string, city: string}
     */
    private function warehouseSummary(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'city' => $warehouse->city,
        ];
    }
}
