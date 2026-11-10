<?php

namespace App\Services\Erp;

use App\Auth\ErpAbilities;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class SupplierLookupService
{
    /**
     * @param  array{name?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function searchSuppliers(User $user, array $filters = [], int $limit = 25): array
    {
        Gate::forUser($user)->authorize(ErpAbilities::VIEW_PURCHASE_ORDER_STATUS);

        $query = Supplier::query()->orderBy('name');

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Supplier $supplier): array => $this->supplierSummary($supplier))
            ->values()
            ->all();
    }

    /**
     * Resolve decrypted bank details for a supplier (Purchasing / viewSupplierBankDetails only).
     *
     * @return array{
     *     found: bool,
     *     reason?: string,
     *     supplier?: array<string, mixed>,
     *     matches?: list<array{id: int, name: string}>
     * }
     */
    public function resolveSupplierBankDetails(User $user, ?int $supplierId, ?string $nameHint): array
    {
        Gate::forUser($user)->authorize(ErpAbilities::VIEW_SUPPLIER_BANK_DETAILS);

        if ($supplierId !== null && $supplierId > 0) {
            $supplier = Supplier::query()->find($supplierId);
            if ($supplier === null) {
                return ['found' => false, 'reason' => 'supplier_not_found'];
            }

            return $this->bankDetailsSnapshot($supplier);
        }

        $hint = is_string($nameHint) ? trim($nameHint) : '';
        if ($hint === '') {
            return ['found' => false, 'reason' => 'missing_filter'];
        }

        $matches = Supplier::query()
            ->where('name', 'like', '%'.$hint.'%')
            ->orderBy('name')
            ->limit(6)
            ->get();

        if ($matches->isEmpty()) {
            return ['found' => false, 'reason' => 'supplier_not_found'];
        }

        foreach ($matches as $supplier) {
            if (strcasecmp($supplier->name, $hint) === 0) {
                return $this->bankDetailsSnapshot($supplier);
            }
        }

        if ($matches->count() === 1) {
            return $this->bankDetailsSnapshot($matches->first());
        }

        return [
            'found' => false,
            'reason' => 'ambiguous_supplier',
            'matches' => $matches
                ->map(static fn (Supplier $supplier): array => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{found: true, supplier: array<string, mixed>}
     */
    private function bankDetailsSnapshot(Supplier $supplier): array
    {
        return [
            'found' => true,
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact' => $supplier->contact,
                'payment_terms' => $supplier->payment_terms,
                'bank_details' => $supplier->bank_details,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierSummary(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'contact' => $supplier->contact,
            'payment_terms' => $supplier->payment_terms,
        ];
    }
}
