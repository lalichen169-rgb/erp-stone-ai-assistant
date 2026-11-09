<?php

namespace App\Http\Controllers\Api\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Erp\StockSearchRequest;
use App\Http\Resources\Api\StockLevelResource;
use App\Services\Erp\StockLookupService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private readonly StockLookupService $stockLookupService) {}

    public function index(StockSearchRequest $request): AnonymousResourceCollection|StockLevelResource
    {
        $user = $request->user();
        $limit = (int) $request->integer('limit', 100);

        if ($request->filled('product_id') && $request->filled('warehouse_id')) {
            $result = $this->stockLookupService->stockForProductAtWarehouse(
                $user,
                (int) $request->integer('product_id'),
                (int) $request->integer('warehouse_id')
            );

            return new StockLevelResource($result);
        }

        $productFilters = $request->productFilters();
        $warehouseFilters = $request->warehouseFilters();

        if ($productFilters !== [] && $warehouseFilters !== []) {
            $result = $this->stockLookupService->stockForProductAtWarehouseByAttributes(
                $user,
                $productFilters,
                $warehouseFilters
            );

            return new StockLevelResource($result);
        }

        $rows = $this->stockLookupService->searchStockLevels(
            $user,
            $productFilters,
            $warehouseFilters,
            $limit
        );

        return StockLevelResource::collection($rows);
    }
}
