<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\WarehouseLookupService;

final class ResolveWarehouseForPurchaseTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly WarehouseLookupService $warehouseLookupService) {}

    public function name(): string
    {
        return 'resolveWarehouseForPurchase';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Resolve a destination warehouse for PO lines (Purchasing). Match by warehouse_id, name, or city. Never invent warehouse_id.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'warehouse_id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'city' => ['type' => 'string', 'maxLength' => 255],
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
            'warehouse_id' => ['sometimes', 'integer', 'min:1', 'exists:warehouses,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $filters = array_filter([
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'name' => $validated['name'] ?? null,
            'city' => $validated['city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($filters === []) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide warehouse_id, name, and/or city to resolve a destination warehouse.',
            );
        }

        $resolution = $this->warehouseLookupService->resolve($context->user, $filters);

        return new ToolExecutionResult(success: true, data: $resolution);
    }
}
