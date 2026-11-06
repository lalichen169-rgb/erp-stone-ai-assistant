<?php

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Auth\TokenController;
use App\Http\Controllers\Api\Erp\LandedCostController;
use App\Http\Controllers\Api\Erp\OverduePurchaseOrderController;
use App\Http\Controllers\Api\Erp\ProductController;
use App\Http\Controllers\Api\Erp\PurchaseOrderController;
use App\Http\Controllers\Api\Erp\PurchaseOrderProposalController;
use App\Http\Controllers\Api\Erp\StockController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/token', [TokenController::class, 'store'])
    ->middleware('throttle:api-login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::delete('/auth/token', [TokenController::class, 'destroy']);

    Route::post('/assistant', [AssistantController::class, 'store'])
        ->middleware('throttle:api-assistant');

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/stock', [StockController::class, 'index']);
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/overdue', [OverduePurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{purchaseOrder}/landed-cost', [LandedCostController::class, 'show']);

    Route::post('/purchase-order-proposals', [PurchaseOrderProposalController::class, 'store'])
        ->name('api.purchase-order-proposals.store');
    Route::get('/purchase-order-proposals/{proposalId}', [PurchaseOrderProposalController::class, 'show'])
        ->name('api.purchase-order-proposals.show');
    Route::post('/purchase-order-proposals/{proposalId}/confirm', [PurchaseOrderProposalController::class, 'confirm'])
        ->name('api.purchase-order-proposals.confirm');
    Route::delete('/purchase-order-proposals/{proposalId}', [PurchaseOrderProposalController::class, 'destroy'])
        ->name('api.purchase-order-proposals.destroy');
});
