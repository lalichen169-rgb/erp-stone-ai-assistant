<?php

namespace App\Models;

use App\Enums\AssistantRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AssistantRun extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'conversation_id',
        'conversation_message_id',
        'status',
        'original_message',
        'client_idempotency_key',
        'request_hash',
        'last_queue_dispatch_at',
        'queue_dispatch_attempts',
        'result_payload',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssistantRunStatus::class,
            'result_payload' => 'array',
            'last_queue_dispatch_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if ($run->public_id === null || $run->public_id === '') {
                $run->public_id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [AssistantRunStatus::Completed, AssistantRunStatus::Failed], true);
    }
}
