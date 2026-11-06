<?php

namespace App\Http\Requests\Api\Erp\Concerns;

use App\Auth\ErpAbilities;
use Illuminate\Support\Facades\Gate;

trait AuthorizesPurchasingProposalAccess
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows(ErpAbilities::CREATE_DRAFT_PURCHASE_ORDERS);
    }
}
