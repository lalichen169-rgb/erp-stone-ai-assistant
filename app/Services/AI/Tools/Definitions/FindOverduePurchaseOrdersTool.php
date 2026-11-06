<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\OverduePurchaseOrderService;
use Illuminate\Support\Carbon;

final class FindOverduePurchaseOrdersTool implements AiToolInterface
{
    use ValidatesToolArguments;

    public function __construct(private readonly OverduePurchaseOrderService $overduePurchaseOrderService) {}

    public function name(): string
    {
        return 'findOverduePurchaseOrders';
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'List purchase orders that are ordered with a non-null ETA strictly before the reference date (default today). Excludes draft, received, and cancelled.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'as_of' => ['type' => 'string', 'description' => 'Reference date Y-m-d (default today)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
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
            'as_of' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $asOf = isset($validated['as_of'])
            ? Carbon::parse((string) $validated['as_of'])->startOfDay()
            : null;

        $limit = (int) ($validated['limit'] ?? OverduePurchaseOrderService::DEFAULT_LIMIT);

        $rows = $this->overduePurchaseOrderService->listOverdue(
            $context->user,
            $asOf,
            $limit,
        );

        return new ToolExecutionResult(success: true, data: ['orders' => $rows]);
    }
}
