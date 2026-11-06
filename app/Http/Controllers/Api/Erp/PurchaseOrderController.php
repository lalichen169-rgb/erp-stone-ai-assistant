<?php

namespace App\Http\Controllers\Api\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Erp\PurchaseOrderSearchRequest;
use App\Http\Resources\Api\PurchaseOrderResource;
use App\Services\Erp\PurchaseOrderLookupService;
use Illuminate\Http\JsonResponse;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderLookupService $purchaseOrderLookupService) {}

    public function index(PurchaseOrderSearchRequest $request): JsonResponse
    {
        $result = $this->purchaseOrderLookupService->search(
            $request->user(),
            $request->filters(),
            (int) $request->integer('limit', PurchaseOrderLookupService::DEFAULT_LIMIT),
            (int) $request->integer('page', 1)
        );

        return response()->json([
            'data' => PurchaseOrderResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }
}
