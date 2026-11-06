<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $warehouse */
        $warehouse = $this->resource;

        return [
            'id' => $warehouse['id'],
            'name' => $warehouse['name'],
            'city' => $warehouse['city'],
        ];
    }
}
