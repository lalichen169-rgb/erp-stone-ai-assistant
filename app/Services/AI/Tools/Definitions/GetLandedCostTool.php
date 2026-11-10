<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\Exceptions\LandedCostCalculationException;
use App\Services\Erp\LandedCostService;

final class GetLandedCostTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly LandedCostService $landedCostService) {}

    public function name(): string
    {
        return 'getLandedCost';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Calculate value-based freight allocation and landed cost for a purchase order (Purchasing only).',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['purchase_order_id'],
                'properties' => [
                    'purchase_order_id' => ['type' => 'integer', 'minimum' => 1],
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
            'purchase_order_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        try {
            $data = $this->landedCostService->calculateForPurchaseOrder(
                $context->user,
                (int) $validated['purchase_order_id'],
            );
        } catch (LandedCostCalculationException) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'not_found_or_invalid',
                errorMessage: 'Purchase order could not be calculated.',
            );
        }

        return new ToolExecutionResult(success: true, data: $data);
    }
}
