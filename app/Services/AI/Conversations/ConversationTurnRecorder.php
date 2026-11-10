<?php

namespace App\Services\AI\Conversations;

use App\DTOs\AI\AiToolCall;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AI\Tools\ToolExecutionResult;

/**
 * Mutable bag of tool traces for one user turn (filled by AiToolCallingService).
 */
final class ConversationTurnRecorder
{
    /**
     * @var list<array{call: AiToolCall, result: ToolExecutionResult}>
     */
    public array $traces = [];

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ConversationMessage $userMessage,
    ) {}

    public function add(AiToolCall $call, ToolExecutionResult $result): void
    {
        $this->traces[] = ['call' => $call, 'result' => $result];
    }
}
