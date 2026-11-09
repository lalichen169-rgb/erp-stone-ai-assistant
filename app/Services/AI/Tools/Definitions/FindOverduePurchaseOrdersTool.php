<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\UsesCompactToolDescriptions;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\AI\Grounding\SalesSupplierOverduePurchaseOrderAnalysisRefusal;
use App\Services\Erp\OverduePurchaseOrderService;
use App\Services\Erp\SupplierFilterResolution;
use Illuminate\Support\Carbon;

final class FindOverduePurchaseOrdersTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use UsesCompactToolDescriptions;
    use ValidatesToolArguments;

    public function __construct(
        private readonly OverduePurchaseOrderService $overduePurchaseOrderService,
        private readonly SupplierFilterResolution $supplierFilterResolution,
    ) {}

    public function name(): string
    {
        return 'findOverduePurchaseOrders';
    }

    public function legacyDefinition(): AiToolDefinition
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
                    'supplier_id' => ['type' => 'integer', 'minimum' => 1],
                    'supplier_name' => ['type' => 'string', 'maxLength' => 255],
                ],
            ],
        );
    }

    public function definition(): AiToolDefinition
    {
        $description = $this->useCompactToolDescriptions()
            ? 'List ordered POs with ETA before as_of (default today); optional supplier_id or supplier_name.'
            : 'List purchase orders that are ordered with a non-null ETA strictly before the reference date (default today). Excludes draft, received, and cancelled. Optional supplier_id or supplier_name filter (Purchasing).';

        return new AiToolDefinition(
            name: $this->name(),
            description: $description,
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'as_of' => ['type' => 'string', 'description' => 'Reference date Y-m-d (default today)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
                    'supplier_id' => ['type' => 'integer', 'minimum' => 1],
                    'supplier_name' => ['type' => 'string', 'maxLength' => 255],
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
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'supplier_name' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;
        $supplierName = isset($validated['supplier_name']) ? trim((string) $validated['supplier_name']) : null;
        $hasSupplierFilter = ($supplierId !== null && $supplierId > 0)
            || ($supplierName !== null && $supplierName !== '');

        if ($context->user->isSales() && $hasSupplierFilter) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'permission_denied',
                errorMessage: SalesSupplierOverduePurchaseOrderAnalysisRefusal::REFUSAL_MESSAGE,
            );
        }

        if ($supplierId === null && $supplierName !== null && $supplierName !== '') {
            $resolution = $this->supplierFilterResolution->resolveByNameHint($supplierName);
            if ($resolution['status'] === 'not_found') {
                return new ToolExecutionResult(
                    success: false,
                    errorCode: 'supplier_not_found',
                    errorMessage: 'No supplier matched that name.',
                );
            }
            if ($resolution['status'] === 'ambiguous') {
                return new ToolExecutionResult(
                    success: false,
                    errorCode: 'ambiguous_supplier',
                    errorMessage: 'Multiple suppliers matched that name.',
                    data: ['matches' => $resolution['matches'] ?? []],
                );
            }
            $supplierId = (int) ($resolution['supplier_id'] ?? 0);
        }

        $asOf = isset($validated['as_of'])
            ? Carbon::parse((string) $validated['as_of'])->startOfDay()
            : null;

        $limit = (int) ($validated['limit'] ?? OverduePurchaseOrderService::DEFAULT_LIMIT);

        $rows = $this->overduePurchaseOrderService->listOverdue(
            $context->user,
            $asOf,
            $limit,
            $supplierId !== null && $supplierId > 0 ? $supplierId : null,
        );

        return new ToolExecutionResult(success: true, data: ['orders' => $rows]);
    }
}
