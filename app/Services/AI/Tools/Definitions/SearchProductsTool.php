<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\NormalizesThicknessCmArgument;
use App\Services\AI\Tools\Concerns\UsesCompactToolDescriptions;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\Contracts\OllamaExclusiveTool;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\StockLookupService;

final class SearchProductsTool implements AiToolInterface, OllamaExclusiveTool
{
    use LegacyExecutionMatchesEnhanced;
    use NormalizesThicknessCmArgument;
    use UsesCompactToolDescriptions;
    use ValidatesToolArguments;

    public function __construct(private readonly StockLookupService $stockLookupService) {}

    public function name(): string
    {
        return 'searchProducts';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        $description = $this->useCompactToolDescriptions()
            ? 'Search products by sku, name, material, color, or thickness_cm. Returns id, sku, name, material, color, thickness_cm (no costs).'
            : 'Search products by SKU, name, material, color, or thickness_cm. Returns id, sku, name, material, color, thickness_cm for each match (no costs). Use before draft PO proposals to obtain real product_id values.';

        return new AiToolDefinition(
            name: $this->name(),
            description: $description,
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'product_sku' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Exact SKU when known'],
                    'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Partial product name'],
                    'material' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Stone material type, e.g. Quartz, Granite (not the color name)'],
                    'color' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Color/variety name, e.g. Calacatta'],
                    'thickness_cm' => ['type' => ['string', 'number'], 'description' => 'Thickness in centimeters as a decimal string, e.g. 3.00 for 3 cm'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
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
            'name' => ['sometimes', 'string', 'max:255'],
            'material' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:255'],
            'thickness_cm' => ['sometimes', 'nullable'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $thickness = $this->normalizeThicknessCm($validated['thickness_cm'] ?? null);
        if ($thickness instanceof ToolExecutionResult) {
            return $thickness;
        }

        $filters = array_filter([
            'sku' => $validated['product_sku'] ?? null,
            'name' => $validated['name'] ?? null,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'thickness_cm' => $thickness,
        ], fn ($v) => $v !== null && $v !== '');

        if ($filters === []) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide at least one search filter (sku, name, material, color, or thickness_cm).',
            );
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $products = $this->stockLookupService->searchProducts($context->user, $filters, $limit);

        return new ToolExecutionResult(success: true, data: [
            'count' => count($products),
            'products' => $products,
        ]);
    }
}
