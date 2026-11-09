<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderProposalConfirmationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->resource;

        return [
            'proposal_id' => $result['proposal_id'],
            'status' => $result['status'],
            'purchase_order_id' => $result['purchase_order_id'],
            'purchase_order_status' => $result['purchase_order_status'],
            'confirmed_at' => $result['confirmed_at'],
        ];
    }
}
