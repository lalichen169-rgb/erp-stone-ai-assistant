<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\AI\Conversations\ConversationService;

final readonly class AiConversationRecording
{
    public function __construct(
        public User $user,
        public Conversation $conversation,
        public ConversationMessage $userMessage,
        public ConversationService $conversationService,
    ) {}
}
