<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $product */
        $product = $this->resource;

        return [
            'id' => $product['id'],
            'sku' => $product['sku'],
            'name' => $product['name'],
            'material' => $product['material'],
            'color' => $product['color'],
            'thickness_cm' => $product['thickness_cm'],
            'slab_size' => $product['slab_size'],
        ];
    }
}
