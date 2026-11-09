<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Enums\PurchaseOrderStatus;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\UsesCompactToolDescriptions;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\PurchaseOrderLookupService;
use Illuminate\Validation\Rule;

final class SearchPurchaseOrdersTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use UsesCompactToolDescriptions;
    use ValidatesToolArguments;

    public function __construct(private readonly PurchaseOrderLookupService $purchaseOrderLookupService) {}

    public function name(): string
    {
        return 'searchPurchaseOrders';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Search purchase orders by numeric purchase_order_id, status, supplier name, and order date range. Use purchase_order_id only when the user refers to a specific PO number. Returns status and line quantities without unit costs, freight, or bank details for authorized roles.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'purchase_order_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact ERP purchase order id when the user asks about a specific PO number.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'ordered', 'received', 'cancelled'],
                    ],
                    'supplier_name' => ['type' => 'string', 'max' => 255],
                    'date_from' => ['type' => 'string', 'description' => 'Order date from (Y-m-d)'],
                    'date_to' => ['type' => 'string', 'description' => 'Order date to (Y-m-d)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
        );
    }

    public function definition(): AiToolDefinition
    {
        $description = $this->useCompactToolDescriptions()
            ? 'Search POs by id, status, supplier, dates. Use purchase_order_id only for a specific PO reference. No unit costs or bank details.'
            : 'Search purchase orders by purchase_order_id, status, supplier name, and optional order date range. '
                .'Status alone is valid (e.g. status=draft) — do not require dates or supplier unless the user asks to narrow. '
                .'Sales and Purchasing may use this tool. Returns status and line quantities without unit costs or bank details.';

        return new AiToolDefinition(
            name: $this->name(),
            description: $description,
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'purchase_order_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact ERP purchase order id when the user asks about a specific PO number.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'ordered', 'received', 'cancelled'],
                    ],
                    'supplier_name' => ['type' => 'string', 'max' => 255],
                    'date_from' => ['type' => 'string', 'description' => 'Order date from (Y-m-d)'],
                    'date_to' => ['type' => 'string', 'description' => 'Order date to (Y-m-d)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                    'page' => ['type' => 'integer', 'minimum' => 1],
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
            'purchase_order_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(PurchaseOrderStatus::values())],
            'supplier_name' => ['sometimes', 'string', 'max:255'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.PurchaseOrderLookupService::MAX_LIMIT],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $filters = [];

        if (isset($validated['purchase_order_id'])) {
            $filters['purchase_order_id'] = (int) $validated['purchase_order_id'];
        }

        if (isset($validated['status'])) {
            $filters['status'] = $validated['status'];
        }

        if (isset($validated['supplier_name'])) {
            $filters['supplier_name'] = $validated['supplier_name'];
        }

        if (isset($validated['date_from'])) {
            $filters['order_date_from'] = $validated['date_from'];
        }

        if (isset($validated['date_to'])) {
            $filters['order_date_to'] = $validated['date_to'];
        }

        $limit = (int) ($validated['limit'] ?? PurchaseOrderLookupService::DEFAULT_LIMIT);
        $page = (int) ($validated['page'] ?? 1);

        $result = $this->purchaseOrderLookupService->search($context->user, $filters, $limit, $page);

        if (isset($validated['purchase_order_id']) && (int) ($result['meta']['total'] ?? 0) === 0) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'not_found',
                errorMessage: 'No purchase order was found with that id.',
            );
        }

        return new ToolExecutionResult(success: true, data: $result);
    }
}
