<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandedCostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->resource;

        return [
            'purchase_order_id' => $result['purchase_order_id'],
            'supplier_name' => $result['supplier_name'],
            'freight_cost' => $result['freight_cost'],
            'total_line_value' => $result['total_line_value'],
            'freight_reconciliation' => $result['freight_reconciliation'],
            'lines' => collect($result['lines'])->map(fn (array $line) => [
                'line_id' => $line['line_id'],
                'product_id' => $line['product_id'],
                'product_sku' => $line['product_sku'],
                'product_name' => $line['product_name'],
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'line_value' => $line['line_value'],
                'freight_share' => $line['freight_share'],
                'freight_per_unit' => $line['freight_per_unit'],
                'landed_unit_cost' => $line['landed_unit_cost'],
            ])->values()->all(),
        ];
    }
}
