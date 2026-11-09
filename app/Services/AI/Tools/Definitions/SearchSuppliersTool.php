<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\SupplierLookupService;

final class SearchSuppliersTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly SupplierLookupService $supplierLookupService) {}

    public function name(): string
    {
        return 'searchSuppliers';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Search suppliers by name. Returns id, name, contact, and payment_terms only (no bank details). Purchasing only. Use before proposeDraftPurchaseOrder to obtain a real supplier_id.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Partial or full supplier name'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25],
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
            'name' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        if (empty($validated['name'])) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide a supplier name filter.',
            );
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $suppliers = $this->supplierLookupService->searchSuppliers(
            $context->user,
            ['name' => $validated['name']],
            $limit,
        );

        return new ToolExecutionResult(success: true, data: [
            'count' => count($suppliers),
            'suppliers' => $suppliers,
        ]);
    }
}
