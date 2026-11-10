<?php

namespace App\Services\AI\Tools\Definitions;

use App\DTOs\AI\AiToolDefinition;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AI\Tools\Concerns\LegacyExecutionMatchesEnhanced;
use App\Services\AI\Tools\Concerns\ValidatesToolArguments;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\Erp\SupplierProductCatalogService;

final class ValidateSupplierProductOfferingTool implements AiToolInterface
{
    use LegacyExecutionMatchesEnhanced;
    use ValidatesToolArguments;

    public function __construct(private readonly SupplierProductCatalogService $catalogService) {}

    public function name(): string
    {
        return 'validateSupplierProductOffering';
    }

    public function legacyDefinition(): AiToolDefinition
    {
        return $this->definition();
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            name: $this->name(),
            description: 'Validate supplier-product catalogue mapping and return active status and catalogue unit_cost when configured (Purchasing only). Use returned unit_cost in proposeDraftPurchaseOrder; do not ask the user to type unit prices when this tool supplies a cost.',
            parameters: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['supplier_id', 'product_id'],
                'properties' => [
                    'supplier_id' => ['type' => 'integer', 'minimum' => 1],
                    'product_id' => ['type' => 'integer', 'minimum' => 1],
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
            'supplier_id' => ['required', 'integer', 'min:1', 'exists:suppliers,id'],
            'product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
        ]);

        if ($validated instanceof ToolExecutionResult) {
            return $validated;
        }

        $supplier = Supplier::query()->find((int) $validated['supplier_id']);
        $product = Product::query()->find((int) $validated['product_id']);

        if ($supplier === null || $product === null) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'not_found',
                errorMessage: 'Supplier or product not found.',
            );
        }

        $catalog = $this->catalogService->lookup($supplier, $product);
        $enforce = (bool) config('erp.procurement.enforce_supplier_product_catalog', false);

        return new ToolExecutionResult(success: true, data: [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'catalog_enforcement_enabled' => $enforce,
            'mapping_found' => $catalog['found'],
            'mapping_active' => $catalog['active'],
            'supplier_product_id' => $catalog['supplier_product_id'],
            'catalog_unit_cost' => $catalog['unit_cost'],
            'supplier_sku' => $catalog['supplier_sku'],
            'compatible_for_proposal' => $enforce
                ? ($catalog['found'] && $catalog['active'])
                : true,
        ]);
    }
}
