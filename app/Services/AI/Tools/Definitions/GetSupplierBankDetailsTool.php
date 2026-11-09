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

final class GetSupplierBankDetailsTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly SupplierLookupService $supplierLookupService) {}

    public function name(): string
    {
        return 'getSupplierBankDetails';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Return supplier wire/bank details from the ERP (Purchasing only). Provide supplier_id or a partial/full name. searchSuppliers does not include bank fields.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'supplier_id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Partial or full supplier name'],
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
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;
        $name = isset($validated['name']) ? trim((string) $validated['name']) : null;

        if (($supplierId === null || $supplierId < 1) && ($name === null || $name === '')) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'Provide supplier_id or name.',
            );
        }

        $snapshot = $this->supplierLookupService->resolveSupplierBankDetails(
            $context->user,
            $supplierId,
            $name,
        );

        if (($snapshot['found'] ?? false) !== true) {
            return new ToolExecutionResult(
                success: false,
                errorCode: (string) ($snapshot['reason'] ?? 'lookup_failed'),
                errorMessage: 'Supplier bank details could not be resolved.',
                data: $snapshot,
            );
        }

        return new ToolExecutionResult(success: true, data: $snapshot);
    }
}
