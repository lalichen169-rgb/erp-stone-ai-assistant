<?php

namespace App\Models;

use App\Enums\ConversationToolCallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationToolCall extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_message_id',
        'tool_result_message_id',
        'execution_order',
        'provider_tool_call_id',
        'tool_name',
        'arguments',
        'status',
        'safe_result',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'safe_result' => 'array',
            'status' => ConversationToolCallStatus::class,
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'user_message_id');
    }

    public function toolResultMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'tool_result_message_id');
    }
}
