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

final class SearchWarehousesTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly WarehouseLookupService $warehouseLookupService) {}

    public function name(): string
    {
        return 'searchWarehouses';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'List receiving warehouses from the ERP catalogue (Purchasing). Returns id, name, and city for each warehouse.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $limit = (int) ($validated['limit'] ?? 50);
        $warehouses = $this->warehouseLookupService->listReceivingWarehouses($context->user, $limit);

        return new ToolExecutionResult(success: true, data: [
            'count' => count($warehouses),
            'warehouses' => $warehouses,
        ]);
    }
}
