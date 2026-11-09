<?php

namespace App\Services\Erp;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderLookupService
{
    use AuthorizesErpUser;

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * Search purchase orders without exposing unit costs or freight costs.
     *
     * @param  array{
     *     purchase_order_id?: int,
     *     supplier_id?: int,
     *     supplier_name?: string,
     *     status?: PurchaseOrderStatus|string,
     *     order_date_from?: string,
     *     order_date_to?: string,
     *     eta_from?: string,
     *     eta_to?: string,
     *     product_sku?: string,
     *     product_name?: string,
     * }  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>}
     */
    public function search(User $user, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $page = 1): array
    {
        $this->authorizePurchaseOrderStatus($user);

        $limit = min(max($limit, 1), self::MAX_LIMIT);
        $page = max($page, 1);

        $query = PurchaseOrder::query()
            ->with([
                'supplier:id,name',
                'lines.product:id,sku,name,material,color,thickness_cm',
            ])
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        $paginator = $query->paginate(perPage: $limit, page: $page);

        $data = collect($paginator->items())->map(fn (PurchaseOrder $order) => $this->formatOrder($order))->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['purchase_order_id'])) {
            $query->whereKey((int) $filters['purchase_order_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['supplier_name'])) {
            $name = (string) $filters['supplier_name'];
            $query->whereHas('supplier', fn (Builder $q) => $q->where('name', 'like', '%'.$name.'%'));
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'] instanceof PurchaseOrderStatus
                ? $filters['status']
                : PurchaseOrderStatus::from((string) $filters['status']);
            $query->where('status', $status);
        }

        if (! empty($filters['order_date_from'])) {
            $query->whereDate('order_date', '>=', $filters['order_date_from']);
        }

        if (! empty($filters['order_date_to'])) {
            $query->whereDate('order_date', '<=', $filters['order_date_to']);
        }

        if (! empty($filters['eta_from'])) {
            $query->whereDate('eta', '>=', $filters['eta_from']);
        }

        if (! empty($filters['eta_to'])) {
            $query->whereDate('eta', '<=', $filters['eta_to']);
        }

        if (! empty($filters['product_sku']) || ! empty($filters['product_name'])) {
            $query->whereHas('lines.product', function (Builder $q) use ($filters) {
                if (! empty($filters['product_sku'])) {
                    $q->where('sku', $filters['product_sku']);
                }
                if (! empty($filters['product_name'])) {
                    $q->where('name', 'like', '%'.$filters['product_name'].'%');
                }
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder(PurchaseOrder $order): array
    {
        return [
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier->name,
            'status' => $order->status->value,
            'order_date' => $order->order_date?->toDateString(),
            'eta' => $order->eta?->toDateString(),
            'lines' => $order->lines->map(fn ($line) => [
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_sku' => $line->product->sku,
                'product_name' => $line->product->name,
                'product_material' => $line->product->material,
                'product_color' => $line->product->color,
                'product_thickness_cm' => (string) $line->product->thickness_cm,
                'quantity' => (int) $line->quantity,
            ])->values()->all(),
        ];
    }
}
