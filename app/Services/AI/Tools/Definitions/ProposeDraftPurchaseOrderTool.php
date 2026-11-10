<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\DraftPurchaseOrderService;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;

final class ProposeDraftPurchaseOrderTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly DraftPurchaseOrderService $draftPurchaseOrderService) {}

    public function name(): string
    {
        return 'proposeDraftPurchaseOrder';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Create a pending draft purchase-order proposal only (no PO until human confirmation via API). Purchasing only. Ground product_id via resolveProductsForPurchase, supplier_id via searchSuppliers, unit_cost via validateSupplierProductOffering when available, and warehouse_id via resolveWarehouseForPurchase per line. Never ask the user for SKU to obtain product_id. Conversation and idempotency are supplied by the server, not model arguments.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['supplier_id', 'order_date', 'freight_cost', 'lines'],
                'properties' => [
                    'supplier_id' => ['type' => 'integer', 'minimum' => 1],
                    'order_date' => ['type' => 'string', 'description' => 'Y-m-d'],
                    'eta' => ['type' => ['string', 'null'], 'description' => 'Optional ETA Y-m-d'],
                    'freight_cost' => ['type' => ['string', 'number']],
                    'lines' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 50,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['product_id', 'quantity', 'unit_cost', 'warehouse_id'],
                            'properties' => [
                                'product_id' => ['type' => 'integer', 'minimum' => 1],
                                'warehouse_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Destination warehouse id from resolveWarehouseForPurchase'],
                                'quantity' => ['type' => 'integer', 'minimum' => 1],
                                'unit_cost' => ['type' => ['string', 'number']],
                            ],
                        ],
                    ],
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
        $conversationKey = $context->conversationKey;
        $idempotencyKey = $context->idempotencyKey;

        if ($conversationKey === null || trim($conversationKey) === '') {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'missing_trusted_context',
                errorMessage: 'Server did not supply a trusted conversation key for proposal creation.',
            );
        }

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'missing_trusted_context',
                errorMessage: 'Server did not supply a trusted idempotency key for proposal creation.',
            );
        }

        $validated = $this->validateOrError($arguments, [
            'supplier_id' => ['required', 'integer', 'min:1', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:order_date'],
            'freight_cost' => ['required', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'lines.*.warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $input = [
            'supplier_id' => (int) $validated['supplier_id'],
            'order_date' => $validated['order_date'],
            'freight_cost' => $validated['freight_cost'],
            'lines' => array_map(
                fn (array $line) => [
                    'product_id' => (int) $line['product_id'],
                    'warehouse_id' => (int) $line['warehouse_id'],
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ],
                $validated['lines']
            ),
        ];

        if (array_key_exists('eta', $validated) && $validated['eta'] !== null) {
            $input['eta'] = $validated['eta'];
        }

        try {
            $proposal = $this->draftPurchaseOrderService->createProposal(
                $context->user,
                $conversationKey,
                $idempotencyKey,
                $input,
            );
        } catch (PurchaseOrderProposalException $exception) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'proposal_error',
                errorMessage: 'Proposal could not be created.',
            );
        }

        $safe = [
            'proposal_id' => $proposal['proposal_id'],
            'status' => $proposal['status'],
            'expires_at' => $proposal['expires_at'],
            'supplier_id' => $proposal['supplier_id'],
            'supplier_name' => $proposal['supplier_name'],
            'order_date' => $proposal['order_date'],
            'eta' => $proposal['eta'],
            'freight_cost' => $proposal['freight_cost'],
            'subtotal' => $proposal['subtotal'],
            'lines' => $proposal['lines'],
        ];

        return new ToolExecutionResult(success: true, data: $safe);
    }
}
