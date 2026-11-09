<?php

namespace App\Models;

use App\Enums\PendingProcurementClarificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingProcurementClarification extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_id',
        'type',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PendingProcurementClarificationType::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
