<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\NormalizesThicknessCmArgument;
use App\Services\AI\Tools\Concerns\UsesCompactToolDescriptions;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\StockLookupService;

final class CheckStockTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use NormalizesThicknessCmArgument;
    use UsesCompactToolDescriptions;
    use ValidatesToolArguments;

    public function __construct(private readonly StockLookupService $stockLookupService) {}

    public function name(): string
    {
        return 'checkStock';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Check slab stock. One warehouse: warehouse_name + optional thickness_cm. All warehouses / all thicknesses: all_warehouses=true with color/material; omit thickness_cm unless the user names one thickness. Put Calacatta in color, Quartz in material. No costs.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'all_warehouses' => [
                        'type' => 'boolean',
                        'description' => 'When true, return stock_levels for every matching thickness and warehouse. Omit warehouse_name. Omit thickness_cm unless the user asked for one thickness.',
                    ],
                    'product_sku' => ['type' => 'string', 'max' => 255],
                    'material' => ['type' => 'string', 'max' => 255],
                    'color' => ['type' => 'string', 'max' => 255],
                    'thickness_cm' => ['type' => ['string', 'number']],
                    'warehouse_name' => ['type' => 'string', 'max' => 255],
                    'warehouse_city' => ['type' => 'string', 'max' => 255],
                ],
            ],
        );
    }

    public function definition(): AiToolDefinition
    {
        $description = $this->useCompactToolDescriptions()
            ? 'Slab stock: one warehouse (warehouse_name + thickness_cm when known) OR all_warehouses=true for every thickness/warehouse. Filters: material, color, product_sku. No costs.'
            : 'Check slab inventory. One warehouse: product filters + warehouse_name. '
                .'Omit material when the user wants every stone type for a variety (returns stock_levels for each SKU). '
                .'all_warehouses=true lists every thickness/warehouse for the filters. '
                .'color=variety; material=stone type (Quartz, Marble, Porcelain) only when named. '
                .'Returns slabs_on_hand (single SKU) or stock_levels (multi-product). No costs.';

        return new AiToolDefinition(
            name: $this->name(),
            description: $description,
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'all_warehouses' => [
                        'type' => 'boolean',
                        'description' => 'When true, list stock_levels for every matching product thickness and warehouse. Do not pass warehouse_name. Omit thickness_cm unless the user asked for one thickness only.',
                    ],
                    'product_sku' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Product SKU when known (e.g. TEST-STQ-CAL-30)',
                    ],
                    'material' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Stone material type: Quartz, Granite, etc. Do not put color names here.',
                    ],
                    'color' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Color or variety name from the user (any catalog variety, e.g. Carrara, Statuario)',
                    ],
                    'thickness_cm' => [
                        'type' => ['string', 'number'],
                        'description' => 'Slab thickness in centimeters as decimal (3 cm slabs → 3.00)',
                    ],
                    'warehouse_name' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Warehouse name, usually same as city (Houston, Dallas, Austin)',
                    ],
                    'warehouse_city' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Warehouse city when name is unknown; prefer warehouse_name when the user names a city',
                    ],
                ],
            ],
        );
    }

    public function allowedFor(User $user): bool
    {
        return $user->isSales() || $user->isPurchasing();
    }

    public function execute(ToolExecutionContext $context, array $arguments): ToolExecutionResult
    {
        $validated = $this->validateOrError($arguments, [
            'all_warehouses' => ['sometimes', 'boolean'],
            'product_sku' => ['sometimes', 'string', 'max:255'],
            'material' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:255'],
            'thickness_cm' => ['sometimes', 'nullable'],
            'warehouse_name' => ['sometimes', 'string', 'max:255'],
            'warehouse_city' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $thickness = $this->normalizeThicknessCm($validated['thickness_cm'] ?? null);
        if ($thickness instanceof ToolExecutionResult) {
            return $thickness;
        }

        $warehouseConflict = $this->detectConflictingWarehouseFilters($validated);
        if ($warehouseConflict !== null) {
            return $warehouseConflict;
        }

        $productFilters = array_filter([
            'sku' => $validated['product_sku'] ?? null,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'thickness_cm' => $thickness,
        ], fn ($v) => $v !== null && $v !== '');

        $allWarehouses = (bool) ($validated['all_warehouses'] ?? false);

        $warehouseFilters = array_filter([
            'name' => $validated['warehouse_name'] ?? null,
            'city' => $validated['warehouse_city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($productFilters === []) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide at least one product filter (product_sku or material/color/thickness_cm).',
            );
        }

        if ($allWarehouses) {
            if ($warehouseFilters !== []) {
                return new ToolExecutionResult(
                    success: false,
                    errorCode: 'validation_error',
                    errorMessage: 'When all_warehouses is true, omit warehouse_name and warehouse_city.',
                );
            }

            $levels = $this->stockLookupService->searchStockLevels(
                $context->user,
                $productFilters,
                [],
                200,
            );

            return new ToolExecutionResult(success: true, data: [
                'found' => $levels !== [],
                'multi_product' => true,
                'stock_levels' => $levels,
                'stock_summary' => 'found=true; report every stock_levels row: warehouse name, material, color, thickness_cm, slabs_on_hand.',
            ]);
        }

        if ($warehouseFilters === []) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide warehouse_name or warehouse_city, or set all_warehouses=true for every warehouse.',
            );
        }

        $result = $this->stockLookupService->stockForProductAtWarehouseByAttributes(
            $context->user,
            $productFilters,
            $warehouseFilters,
        );

        return $this->wrapStockLookupResult($result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function wrapStockLookupResult(array $result): ToolExecutionResult
    {
        if (($result['found'] ?? false) === true) {
            if (! empty($result['stock_levels']) && is_array($result['stock_levels'])) {
                $result['stock_summary'] = 'found=true; report every stock_levels row with warehouse, thickness_cm, material, and slabs_on_hand.';
            } elseif (isset($result['slabs_on_hand'])) {
                $result['stock_summary'] = 'found=true; report slabs_on_hand='.$result['slabs_on_hand'].' for this warehouse.';
            }

            return new ToolExecutionResult(success: true, data: $result);
        }

        $reason = is_string($result['reason'] ?? null) ? $result['reason'] : 'lookup_failed';

        $message = match ($reason) {
            'product_not_found' => 'No product matched those filters. Use color for variety (Calacatta), material Quartz when known, thickness_cm 3.00 (or 3cm), and warehouse_name.',
            'warehouse_not_found' => 'No warehouse matched. Use warehouse_name or warehouse_city exactly as stored in the ERP (e.g. the city/location the user named).',
            'ambiguous_product' => 'Multiple products matched. Add material, thickness_cm, or product_sku to narrow the search.',
            'ambiguous_warehouse' => 'Multiple warehouses matched. Use warehouse_name only (e.g. Dallas).',
            default => 'Could not resolve stock for those filters. Adjust arguments and call checkStock again.',
        };

        return new ToolExecutionResult(
            success: false,
            data: $result,
            errorCode: $reason,
            errorMessage: $message,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function detectConflictingWarehouseFilters(array $validated): ?ToolExecutionResult
    {
        $name = isset($validated['warehouse_name']) && is_string($validated['warehouse_name'])
            ? trim($validated['warehouse_name']) : '';
        $city = isset($validated['warehouse_city']) && is_string($validated['warehouse_city'])
            ? trim($validated['warehouse_city']) : '';

        if ($name === '' || $city === '') {
            return null;
        }

        $matches = Warehouse::query()
            ->where('name', 'like', '%'.$name.'%')
            ->where('city', 'like', '%'.$city.'%')
            ->count();

        if ($matches === 0) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'warehouse_name and warehouse_city do not match any single warehouse; use one filter or correct values.',
            );
        }

        return null;
    }
}
