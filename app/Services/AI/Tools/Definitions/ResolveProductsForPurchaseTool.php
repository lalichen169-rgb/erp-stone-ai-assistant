<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\NormalizesThicknessCmArgument;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\ProductCatalogResolutionService;

final class ResolveProductsForPurchaseTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use NormalizesThicknessCmArgument;
    use ValidatesToolArguments;

    public function __construct(private readonly ProductCatalogResolutionService $resolutionService) {}

    public function name(): string
    {
        return 'resolveProductsForPurchase';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Strict catalogue product resolution for draft PO proposals (Purchasing). Returns match status, product_id when unique, alternatives when ambiguous, and clarification requirements. Never invent product_id.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'product_sku' => ['type' => 'string', 'maxLength' => 255],
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'material' => ['type' => 'string', 'maxLength' => 255],
                    'color' => ['type' => 'string', 'maxLength' => 255],
                    'thickness_cm' => ['type' => ['string', 'number']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
            ],
        );
    }

    public function allowedFor(User $user): bool
    {
        return $user->isPurchasing();
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
                errorMessage: 'Provide at least one product filter (sku, name, material, color, or thickness_cm).',
            );
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $resolution = $this->resolutionService->resolve($filters, $limit);

        return new ToolExecutionResult(success: true, data: $resolution);
    }
}
