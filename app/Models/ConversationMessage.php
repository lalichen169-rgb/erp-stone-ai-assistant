<?php

namespace App\Models;

use App\Enums\ConversationMessageRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ConversationMessage extends Model
{
    protected $fillable = [
        'public_id',
        'conversation_id',
        'sequence',
        'role',
        'content',
        'metadata',
        'is_internal',
        'turn_idempotency_key',
        'client_idempotency_key',
        'idempotency_response',
    ];

    protected function casts(): array
    {
        return [
            'role' => ConversationMessageRole::class,
            'metadata' => 'array',
            'is_internal' => 'boolean',
            'idempotency_response' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ConversationMessage $message): void {
            if ($message->public_id === null) {
                $message->public_id = (string) Str::uuid();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toolCallsWhereUserTurn(): HasMany
    {
        return $this->hasMany(ConversationToolCall::class, 'user_message_id');
    }

    public function toolResultAsMessage(): HasOne
    {
        return $this->hasOne(ConversationToolCall::class, 'tool_result_message_id');
    }
}
