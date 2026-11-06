<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSummary extends Model
{
    protected $fillable = [
        'conversation_id',
        'version',
        'content',
        'last_summarized_message_id',
        'last_summarized_sequence',
        'covered_message_count',
    ];

    protected function casts(): array
    {
        return [
            'covered_message_count' => 'integer',
            'last_summarized_sequence' => 'integer',
            'version' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lastSummarizedMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'last_summarized_message_id');
    }
}
