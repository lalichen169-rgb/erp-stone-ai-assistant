<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $proposal */
        $proposal = $this->resource;

        return [
            'proposal_id' => $proposal['proposal_id'],
            'status' => $proposal['status'],
            'expires_at' => $proposal['expires_at'],
            'confirmed_at' => $proposal['confirmed_at'] ?? null,
            'confirmed_purchase_order_id' => $proposal['confirmed_purchase_order_id'] ?? null,
            'supplier_id' => $proposal['supplier_id'],
            'supplier_name' => $proposal['supplier_name'],
            'order_date' => $proposal['order_date'],
            'eta' => $proposal['eta'],
            'freight_cost' => $proposal['freight_cost'],
            'subtotal' => $proposal['subtotal'],
            'lines' => collect($proposal['lines'])->map(fn (array $line) => [
                'product_id' => $line['product_id'],
                'product_sku' => $line['product_sku'],
                'product_name' => $line['product_name'],
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'line_total' => $line['line_total'],
            ])->values()->all(),
        ];
    }
}
