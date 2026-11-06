<?php

namespace App\Services\AI\Tools;

use App\Models\User;
use App\Services\AI\Conversations\ConversationTurnRecorder;

/**
 * Trusted server-side context for AI tool execution.
 *
 * Conversation key and idempotency key must never originate from model output alone;
 * the assistant orchestration layer supplies them when invoking the executor.
 */
final readonly class ToolExecutionContext
{
    public function __construct(
        public User $user,
        public ?string $conversationKey = null,
        public ?string $idempotencyKey = null,
        public ?ConversationTurnRecorder $turnRecorder = null,
    ) {}
}
