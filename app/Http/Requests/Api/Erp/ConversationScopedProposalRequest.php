<?php

namespace App\Http\Requests\Api\Erp;

use App\Http\Requests\Api\Erp\Concerns\AuthorizesPurchasingProposalAccess;
use Illuminate\Foundation\Http\FormRequest;

class ConversationScopedProposalRequest extends FormRequest
{
    use AuthorizesPurchasingProposalAccess;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_key' => ['required', 'string', 'max:191'],
        ];
    }

    public function conversationKey(): string
    {
        return trim((string) $this->validated('conversation_key'));
    }
}
