<?php

namespace App\Services\AI\Grounding;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\ProductCatalogResolutionService;
use Illuminate\Support\Carbon;

/**
 * Extracts structured draft-PO fields from natural-language Purchasing requests (deterministic path).
 */
final class DraftPurchaseOrderMessageParser
{
    public function __construct(
        private readonly PurchaseOrderCreationIntentDetector $purchaseOrderCreationIntentDetector,
        private readonly ProductCatalogResolutionService $productCatalogResolutionService,
    ) {}

    public function messageMentionsKnownWarehouse(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        $warehouses = Warehouse::query()->orderBy('name')->get(['id', 'name', 'city']);
        foreach ($warehouses as $warehouse) {
            if ($this->containsWholePhrase($trimmed, $warehouse->name)) {
                return true;
            }
            if ($warehouse->city !== '' && $this->containsWholePhrase($trimmed, $warehouse->city)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     supplier_id: int,
     *     order_date: string,
     *     freight_cost: string,
     *     lines: list<array{product_id: int, quantity: int, unit_cost: string}>
     * }|null
     */
    public function extractDraftIntent(User $user, string $text): ?array
    {
        if (! $user->isPurchasing()) {
            return null;
        }

        $trimmed = trim($text);
        if ($trimmed === '' || ! $this->purchaseOrderCreationIntentDetector->isPurchaseOrderCreationRequest($trimmed)) {
            return null;
        }

        if ($this->messageMentionsKnownWarehouse($trimmed)) {
            return null;
        }

        $quantity = $this->extractQuantity($trimmed);
        if ($quantity === null) {
            return null;
        }

        $supplier = $this->resolveSupplier($trimmed);
        if ($supplier === null) {
            return null;
        }

        $productFilters = $this->extractProductFilters($trimmed);
        if ($productFilters === null) {
            return null;
        }

        $resolution = $this->productCatalogResolutionService->resolve($productFilters);
        if (($resolution['status'] ?? '') !== 'unique_match' || empty($resolution['product_id'])) {
            return null;
        }

        $productId = (int) $resolution['product_id'];

        return [
            'supplier_id' => $supplier->id,
            'order_date' => Carbon::now()->toDateString(),
            'freight_cost' => $this->extractFreightCost($trimmed),
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => $this->defaultUnitCostForLine($supplier->id, $productId),
                ],
            ],
        ];
    }

    /**
     * @return array{warehouse_name_hint: ?string, quantity: ?int}
     */
    public function parseWarehouseClarificationReply(string $text): array
    {
        $trimmed = trim($text);
        $quantity = $this->extractQuantity($trimmed);

        return [
            'warehouse_name_hint' => $this->extractWarehouseNameHint($trimmed),
            'quantity' => $quantity,
        ];
    }

    public function extractWarehouseNameHint(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/\bfor\s+(.+?)(?:\.|$)/iu', $trimmed, $matches) === 1) {
            return $this->normalizeWarehouseHint(trim($matches[1]));
        }

        if (preg_match('/\b(?:actually\s+)?(?:make\s+it\s+)?(?:\d+\s+slabs?\s+)?(?:for\s+)?(.+)$/iu', $trimmed, $matches) === 1) {
            $candidate = $this->normalizeWarehouseHint(trim($matches[1]));
            if ($candidate !== '' && ! preg_match('/^\d+$/', $candidate)) {
                return $candidate;
            }
        }

        return $this->normalizeWarehouseHint($trimmed);
    }

    private function normalizeWarehouseHint(string $hint): string
    {
        return trim($hint, " \t\n\r\0\x0B.,!?;");
    }

    public function looksLikeUnrelatedStandaloneQuestion(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return true;
        }

        if (preg_match('/\b(landed\s+cost|overdue|bank\s+details?)\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\bhow\s+many\b.*\b(slabs?|stock)\b/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\b(show|list|find|search)\b.*\bpurchase\s+orders?\b/i', $trimmed) === 1
            && preg_match('/\b(create|make|propose|draft)\b/i', $trimmed) !== 1) {
            return true;
        }

        return false;
    }

    private function extractQuantity(string $text): ?int
    {
        if (preg_match('/\b(\d+)\s+slabs?\b/i', $text, $matches) !== 1) {
            return null;
        }

        $qty = (int) $matches[1];

        return $qty > 0 ? $qty : null;
    }

    private function extractFreightCost(string $text): string
    {
        if (preg_match('/\bfreight\s*(?:cost|is|:)?\s*\$?(\d+(?:\.\d{2})?)/i', $text, $matches) === 1) {
            return number_format((float) $matches[1], 2, '.', '');
        }

        return '0.00';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractProductFilters(string $text): ?array
    {
        $filters = [];

        if (preg_match('/\b(\d+(?:\.\d+)?)\s*cm\b/i', $text, $matches) === 1) {
            $filters['thickness_cm'] = number_format((float) $matches[1], 2, '.', '');
        }

        if (preg_match('/\b(carrara|calacatta|statuario|thassos)\b/i', $text, $matches) === 1) {
            $filters['color'] = ucfirst(strtolower($matches[1]));
        }

        if (preg_match('/\b(marble|quartz|granite|porcelain)\b/i', $text, $matches) === 1) {
            $filters['material'] = ucfirst(strtolower($matches[1]));
        }

        if ($filters === []) {
            return null;
        }

        return $filters;
    }

    private function resolveSupplier(string $text): ?Supplier
    {
        if (preg_match('/\bfrom\s+(.+?)(?:\?|\.|$)/iu', $text, $matches) !== 1) {
            return null;
        }

        $hint = trim($matches[1]);
        if ($hint === '') {
            return null;
        }

        $exact = Supplier::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($hint)])
            ->first();
        if ($exact !== null) {
            return $exact;
        }

        $candidates = Supplier::query()
            ->where('name', 'like', '%'.$hint.'%')
            ->orderBy('name')
            ->limit(2)
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return null;
    }

    private function defaultUnitCostForLine(int $supplierId, int $productId): string
    {
        $catalog = \App\Models\SupplierProduct::query()
            ->where('supplier_id', $supplierId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->first();

        if ($catalog !== null && $catalog->unit_cost !== null) {
            return (string) $catalog->unit_cost;
        }

        return '1.00';
    }

    private function containsWholePhrase(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }

        return preg_match('/\b'.preg_quote($needle, '/').'\b/iu', $haystack) === 1;
    }
}
