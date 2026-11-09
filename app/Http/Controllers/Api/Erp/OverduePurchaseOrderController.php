<?php

namespace App\Http\Controllers\Api\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Erp\OverduePurchaseOrderRequest;
use App\Http\Resources\Api\OverduePurchaseOrderResource;
use App\Services\Erp\OverduePurchaseOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class OverduePurchaseOrderController extends Controller
{
    public function __construct(private readonly OverduePurchaseOrderService $overduePurchaseOrderService) {}

    public function index(OverduePurchaseOrderRequest $request): AnonymousResourceCollection
    {
        $asOf = $request->filled('as_of')
            ? Carbon::parse($request->string('as_of')->toString())
            : null;

        $orders = $this->overduePurchaseOrderService->listOverdue(
            $request->user(),
            $asOf,
            (int) $request->integer('limit', OverduePurchaseOrderService::DEFAULT_LIMIT)
        );

        return OverduePurchaseOrderResource::collection($orders);
    }
}
