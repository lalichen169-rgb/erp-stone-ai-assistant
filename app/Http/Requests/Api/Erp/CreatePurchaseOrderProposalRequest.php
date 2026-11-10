<?php

namespace App\Http\Requests\Api\Erp;

use App\Http\Requests\Api\Erp\Concerns\AuthorizesPurchasingProposalAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreatePurchaseOrderProposalRequest extends FormRequest
{
    use AuthorizesPurchasingProposalAccess;

    public function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_key' => ['required', 'string', 'max:191'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'supplier_id' => ['required', 'integer', 'min:1', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:order_date'],
            'freight_cost' => ['required', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'lines.*.warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = $this->header('Idempotency-Key');

            if ($key !== null && trim($key) === '') {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must not be empty.');
            }
        });
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->validated('idempotency_key'));
    }

    public function conversationKey(): string
    {
        return trim((string) $this->validated('conversation_key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function proposalInput(): array
    {
        $validated = $this->validated();

        $input = [
            'supplier_id' => (int) $validated['supplier_id'],
            'order_date' => $validated['order_date'],
            'freight_cost' => $validated['freight_cost'],
            'lines' => array_map(
                fn (array $line): array => [
                    'product_id' => (int) $line['product_id'],
                    'warehouse_id' => (int) $line['warehouse_id'],
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ],
                $validated['lines']
            ),
        ];

        if (array_key_exists('eta', $validated) && $validated['eta'] !== null) {
            $input['eta'] = $validated['eta'];
        }

        return $input;
    }
}
