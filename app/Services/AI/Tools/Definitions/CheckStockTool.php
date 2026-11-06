<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\StockLookupService;

final class CheckStockTool implements AiToolInterface
{
    use ValidatesToolArguments;

    public function __construct(private readonly StockLookupService $stockLookupService) {}

    public function name(): string
    {
        return 'checkStock';
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Check slab stock for a product at a warehouse using product attributes (SKU, material, color, thickness) and warehouse name or city. Returns product and warehouse identity plus slabs on hand. No cost fields.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
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

    public function allowedFor(User $user): bool
    {
        return $user->isSales() || $user->isPurchasing();
    }

    public function execute(ToolExecutionContext $context, array $arguments): ToolExecutionResult
    {
        $validated = $this->validateOrError($arguments, [
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

        $productFilters = array_filter([
            'sku' => $validated['product_sku'] ?? null,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'thickness_cm' => $validated['thickness_cm'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $warehouseFilters = array_filter([
            'name' => $validated['warehouse_name'] ?? null,
            'city' => $validated['warehouse_city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($productFilters === [] || $warehouseFilters === []) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide at least one product filter (e.g. product_sku or material) and at least one warehouse filter (warehouse_name or warehouse_city).',
            );
        }

        $result = $this->stockLookupService->stockForProductAtWarehouseByAttributes(
            $context->user,
            $productFilters,
            $warehouseFilters,
        );

        return new ToolExecutionResult(success: true, data: $result);
    }
}
