<?php

namespace App\Http\Controllers\Api\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Erp\ProductSearchRequest;
use App\Http\Resources\Api\ProductResource;
use App\Services\Erp\StockLookupService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private readonly StockLookupService $stockLookupService) {}

    public function index(ProductSearchRequest $request): AnonymousResourceCollection
    {
        $limit = (int) $request->integer('limit', 50);

        $products = $this->stockLookupService->searchProducts(
            $request->user(),
            $request->filters(),
            $limit
        );

        return ProductResource::collection($products);
    }
}
