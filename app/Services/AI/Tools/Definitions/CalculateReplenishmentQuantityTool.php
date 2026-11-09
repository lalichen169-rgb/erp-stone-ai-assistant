<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\ReplenishmentQuantityService;

final class CalculateReplenishmentQuantityTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly ReplenishmentQuantityService $replenishmentQuantityService) {}

    public function name(): string
    {
        return 'calculateReplenishmentQuantity';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Calculate replenishment shortage for a target stock level at a specific warehouse (Purchasing). Uses on-hand stock at that warehouse only. Does not change explicit direct PO quantities.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['product_id', 'warehouse_id', 'target_quantity'],
                'properties' => [
                    'product_id' => ['type' => 'integer', 'minimum' => 1],
                    'warehouse_id' => ['type' => 'integer', 'minimum' => 1],
                    'target_quantity' => ['type' => 'integer', 'minimum' => 1],
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
            'product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id'],
            'target_quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $result = $this->replenishmentQuantityService->calculateShortageAtWarehouse(
            $context->user,
            (int) $validated['product_id'],
            (int) $validated['warehouse_id'],
            (int) $validated['target_quantity'],
        );

        if (($result['success'] ?? false) !== true) {
            return new ToolExecutionResult(
                success: false,
                errorCode: (string) ($result['error_code'] ?? 'calculation_error'),
                errorMessage: (string) ($result['message'] ?? 'Replenishment calculation failed.'),
            );
        }

        return new ToolExecutionResult(success: true, data: $result);
    }
}
