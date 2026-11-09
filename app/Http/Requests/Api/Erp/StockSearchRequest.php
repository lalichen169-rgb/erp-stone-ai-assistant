<?php

namespace App\Http\Requests\Api\Erp;

use Illuminate\Foundation\Http\FormRequest;

class StockSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'sku' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'material' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:255'],
            'thickness_cm' => ['sometimes', 'numeric', 'min:0', 'max:999.99'],
            'warehouse_name' => ['sometimes', 'string', 'max:255'],
            'warehouse_city' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productFilters(): array
    {
        return array_filter([
            'sku' => $this->input('sku'),
            'name' => $this->input('name'),
            'material' => $this->input('material'),
            'color' => $this->input('color'),
            'thickness_cm' => $this->input('thickness_cm'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function warehouseFilters(): array
    {
        return array_filter([
            'name' => $this->input('warehouse_name'),
            'city' => $this->input('warehouse_city'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
