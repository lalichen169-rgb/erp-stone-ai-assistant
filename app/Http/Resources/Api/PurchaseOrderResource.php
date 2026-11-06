<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
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
            'supplier_id' => $order['supplier_id'],
            'supplier_name' => $order['supplier_name'],
            'status' => $order['status'],
            'order_date' => $order['order_date'],
            'eta' => $order['eta'],
            'lines' => collect($order['lines'])->map(fn (array $line) => [
                'line_id' => $line['line_id'],
                'product_id' => $line['product_id'],
                'product_sku' => $line['product_sku'],
                'product_name' => $line['product_name'],
                'product_material' => $line['product_material'],
                'product_color' => $line['product_color'],
                'product_thickness_cm' => $line['product_thickness_cm'],
                'quantity' => $line['quantity'],
            ])->values()->all(),
        ];
    }
}
