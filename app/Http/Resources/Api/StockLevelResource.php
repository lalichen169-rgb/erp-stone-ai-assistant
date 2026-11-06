<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        if (isset($row['product'], $row['warehouse'])) {
            return [
                'product' => new ProductResource($row['product']),
                'warehouse' => new WarehouseResource($row['warehouse']),
                'slabs_on_hand' => $row['slabs_on_hand'],
                'has_stock_record' => $row['has_stock_record'] ?? true,
            ];
        }

        return [
            'found' => $row['found'] ?? true,
            'product' => isset($row['product']) ? new ProductResource($row['product']) : null,
            'warehouse' => isset($row['warehouse']) ? new WarehouseResource($row['warehouse']) : null,
            'slabs_on_hand' => $row['slabs_on_hand'] ?? 0,
            'has_stock_record' => $row['has_stock_record'] ?? false,
            'reason' => $row['reason'] ?? null,
        ];
    }
}
