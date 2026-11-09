<?php

namespace App\Http\Controllers\Api\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Erp\ConversationScopedProposalRequest;
use App\Http\Requests\Api\Erp\CreatePurchaseOrderProposalRequest;
use App\Http\Resources\Api\PurchaseOrderProposalConfirmationResource;
use App\Http\Resources\Api\PurchaseOrderProposalResource;
use App\Models\PurchaseOrderProposal;
use App\Services\Erp\DraftPurchaseOrderService;
use Illuminate\Http\JsonResponse;

class PurchaseOrderProposalController extends Controller
{
    public function __construct(private readonly DraftPurchaseOrderService $draftPurchaseOrderService) {}

    public function store(CreatePurchaseOrderProposalRequest $request): JsonResponse
    {
        $user = $request->user();
        $idempotencyKey = $request->idempotencyKey();

        $alreadyExists = PurchaseOrderProposal::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();

        $result = $this->draftPurchaseOrderService->createProposal(
            $user,
            $request->conversationKey(),
            $idempotencyKey,
            $request->proposalInput()
        );

        return (new PurchaseOrderProposalResource($result))
            ->response()
            ->setStatusCode($alreadyExists ? 200 : 201);
    }

    public function show(ConversationScopedProposalRequest $request, string $proposalId): PurchaseOrderProposalResource
    {
        $result = $this->draftPurchaseOrderService->getProposal(
            $request->user(),
            $proposalId,
            $request->conversationKey()
        );

        return new PurchaseOrderProposalResource($result);
    }

    public function confirm(ConversationScopedProposalRequest $request, string $proposalId): PurchaseOrderProposalConfirmationResource
    {
        $result = $this->draftPurchaseOrderService->confirmProposal(
            $request->user(),
            $proposalId,
            $request->conversationKey()
        );

        return new PurchaseOrderProposalConfirmationResource($result);
    }

    public function destroy(ConversationScopedProposalRequest $request, string $proposalId): PurchaseOrderProposalResource
    {
        $result = $this->draftPurchaseOrderService->cancelProposal(
            $request->user(),
            $proposalId,
            $request->conversationKey()
        );

        return new PurchaseOrderProposalResource($result);
    }
}
