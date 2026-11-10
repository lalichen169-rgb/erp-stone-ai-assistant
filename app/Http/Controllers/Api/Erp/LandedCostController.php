<?php

namespace App\Http\Controllers\Api\Erp;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\LandedCostResource;
use App\Models\PurchaseOrder;
use App\Services\Erp\Exceptions\LandedCostCalculationException;
use App\Services\Erp\LandedCostService;
use Illuminate\Http\Request;

class LandedCostController extends Controller
{
    public function __construct(private readonly LandedCostService $landedCostService) {}

    public function show(Request $request, PurchaseOrder $purchaseOrder): LandedCostResource
    {
        try {
            $result = $this->landedCostService->calculateForPurchaseOrder(
                $request->user(),
                $purchaseOrder->id
            );
        } catch (LandedCostCalculationException $exception) {
            abort(404, $exception->getMessage());
        }

        return new LandedCostResource($result);
    }
}
