<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAiInteraction extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_message_id',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'tool_iterations',
        'tool_calls_executed',
        'duration_ms',
        'completed',
        'error_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'metadata' => 'array',
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
}
