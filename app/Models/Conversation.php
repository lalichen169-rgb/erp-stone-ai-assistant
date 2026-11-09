<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'title',
        'status',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation): void {
            if ($conversation->public_id === null) {
                $conversation->public_id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('sequence');
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(ConversationSummary::class)->orderByDesc('version');
    }

    public function latestSummary(): HasOne
    {
        return $this->hasOne(ConversationSummary::class)->ofMany('version', 'max');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ConversationToolCall::class);
    }

    public function aiInteractions(): HasMany
    {
        return $this->hasMany(ConversationAiInteraction::class)->orderByDesc('created_at');
    }

    /**
     * Stable key passed to DraftPurchaseOrderService (trusted server scope).
     */
    public function getProposalConversationKey(): string
    {
        return $this->public_id;
    }
}
