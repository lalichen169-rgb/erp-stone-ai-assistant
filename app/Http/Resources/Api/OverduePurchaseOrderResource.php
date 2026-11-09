<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OverduePurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $order */
        $order = $this->resource;

        return [
            'purchase_order_id' => $order['purchase_order_id'],
            'supplier_name' => $order['supplier_name'],
            'status' => $order['status'],
            'order_date' => $order['order_date'],
            'eta' => $order['eta'],
            'days_overdue' => $order['days_overdue'],
        ];
    }
}
