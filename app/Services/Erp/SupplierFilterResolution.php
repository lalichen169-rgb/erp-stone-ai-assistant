<?php

namespace App\Services\Erp;

use App\Models\Supplier;

/**
 * Resolves a supplier name hint to a single supplier id for ERP filters.
 *
 * @phpstan-type SupplierMatch array{id: int, name: string}
 */
final class SupplierFilterResolution
{
    /**
     * @return array{
     *     status: 'ok'|'not_found'|'ambiguous'|'missing',
     *     supplier_id?: int,
     *     matches?: list<SupplierMatch>
     * }
     */
    public function resolveByNameHint(string $nameHint): array
    {
        $hint = trim($nameHint);
        $hint = rtrim($hint, ".,;:!?\"'");
        $hint = trim($hint);
        if ($hint === '') {
            return ['status' => 'missing'];
        }

        $matches = Supplier::query()
            ->where('name', 'like', '%'.$hint.'%')
            ->orderBy('name')
            ->limit(6)
            ->get();

        if ($matches->isEmpty()) {
            return ['status' => 'not_found'];
        }

        foreach ($matches as $supplier) {
            if (strcasecmp($supplier->name, $hint) === 0) {
                return ['status' => 'ok', 'supplier_id' => $supplier->id];
            }
        }

        if ($matches->count() === 1) {
            return ['status' => 'ok', 'supplier_id' => $matches->first()->id];
        }

        return [
            'status' => 'ambiguous',
            'matches' => $matches
                ->map(static fn (Supplier $supplier): array => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])
                ->values()
                ->all(),
        ];
    }
}
