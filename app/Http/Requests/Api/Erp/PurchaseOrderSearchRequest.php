<?php

namespace App\Http\Requests\Api\Erp;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderSearchRequest extends FormRequest
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
            'purchase_order_id' => ['sometimes', 'integer', 'min:1'],
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'supplier_name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(PurchaseOrderStatus::values())],
            'order_date_from' => ['sometimes', 'date'],
            'order_date_to' => ['sometimes', 'date', 'after_or_equal:order_date_from'],
            'eta_from' => ['sometimes', 'date'],
            'eta_to' => ['sometimes', 'date', 'after_or_equal:eta_from'],
            'product_sku' => ['sometimes', 'string', 'max:255'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->only([
                'purchase_order_id',
                'supplier_id',
                'supplier_name',
                'status',
                'order_date_from',
                'order_date_to',
                'eta_from',
                'eta_to',
                'product_sku',
                'product_name',
            ]),
            fn ($value) => $value !== null && $value !== ''
        );
    }
}
