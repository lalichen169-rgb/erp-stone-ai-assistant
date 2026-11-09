<?php

namespace App\Services\Erp;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class OverduePurchaseOrderService
{
    use AuthorizesErpUser;

    public const DEFAULT_LIMIT = 100;

    /**
     * Ordered POs whose ETA is strictly before the reference date (default: today).
     *
     * @return list<array<string, mixed>>
     */
    public function listOverdue(
        User $user,
        ?CarbonInterface $today = null,
        int $limit = self::DEFAULT_LIMIT,
        ?int $supplierId = null,
    ): array {
        $this->authorizePurchaseOrderStatus($user);

        $referenceDate = Carbon::instance($today ?? Carbon::today())->startOfDay();
        $limit = max(1, min($limit, 500));

        $query = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->where('status', PurchaseOrderStatus::Ordered)
            ->whereNotNull('eta')
            ->whereDate('eta', '<', $referenceDate);

        if ($supplierId !== null && $supplierId > 0) {
            $query->where('supplier_id', $supplierId);
        }

        return $query
            ->orderBy('eta')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (PurchaseOrder $order) use ($referenceDate) {
                $eta = $order->eta->copy()->startOfDay();

                return [
                    'purchase_order_id' => $order->id,
                    'supplier_name' => $order->supplier->name,
                    'status' => $order->status->value,
                    'order_date' => $order->order_date?->toDateString(),
                    'eta' => $order->eta?->toDateString(),
                    'days_overdue' => (int) $eta->diffInDays($referenceDate),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Whether a single PO meets the overdue business rule for the reference date.
     */
    public function isOverdue(PurchaseOrder $order, ?CarbonInterface $today = null): bool
    {
        if ($order->status !== PurchaseOrderStatus::Ordered) {
            return false;
        }

        if ($order->eta === null) {
            return false;
        }

        $referenceDate = Carbon::instance($today ?? Carbon::today())->startOfDay();

        return $order->eta->copy()->startOfDay()->lt($referenceDate);
    }
}
