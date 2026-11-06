<?php

namespace App\Services\AI\Context;

use App\DTOs\AI\AiMessage;
use App\Enums\ConversationMessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;

final class ConversationContextManager
{
    /**
     * Build provider-ready messages: latest summary (as system prefix) + recent non-internal messages.
     *
     * @return list<AiMessage>
     */
    /**
     * @param  list<int>  $excludeMessageIds  Skip these rows (e.g. current turn user message not yet persisted in the slice we want).
     */
    public function buildAiMessages(
        Conversation $conversation,
        User $viewer,
        array $excludeMessageIds = [],
    ): array {
        $summary = $conversation->latestSummary;
        $max = (int) config('ai.conversation.context.max_messages', 24);
        $maxChars = (int) config('ai.conversation.context.max_chars', 12000);

        $rows = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->orderByDesc('sequence')
            ->limit($max * 4)
            ->get();

        $chosen = [];
        $charBudget = $maxChars;

        foreach ($rows as $row) {
            if ($excludeMessageIds !== [] && in_array((int) $row->id, $excludeMessageIds, true)) {
                continue;
            }

            if ($row->role === ConversationMessageRole::Tool && ! $this->includeToolRowForUser($row, $viewer)) {
                continue;
            }

            $msg = $this->mapMessage($row);
            if ($msg === null) {
                continue;
            }

            $len = strlen($msg->content) + strlen($msg->role) * 2;
            if ($charBudget < $len && $chosen !== []) {
                break;
            }

            $chosen[] = $msg;
            $charBudget -= $len;

            if (count($chosen) >= $max) {
                break;
            }
        }

        $messages = array_reverse($chosen);

        $summaryText = $summary?->content;
        if ($summaryText !== null && trim($summaryText) !== '') {
            array_unshift($messages, AiMessage::system(
                "Conversation summary (may omit details; full history is stored on the server):\n".$summaryText
            ));
        }

        return $messages;
    }

    public function buildContext(Conversation $conversation, User $viewer): ConversationContext
    {
        $summary = $conversation->latestSummary;

        return new ConversationContext(
            aiMessages: $this->buildAiMessages($conversation, $viewer),
            latestSummaryText: $summary?->content,
            metadata: [
                'message_count_used' => ConversationMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('is_internal', false)
                    ->count(),
            ],
        );
    }

    private function includeToolRowForUser(ConversationMessage $row, User $user): bool
    {
        if ($user->isPurchasing()) {
            return true;
        }

        $name = $row->metadata['tool_name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return true;
        }

        // Sales must not see prior landed-cost tool payloads in context (defense in depth vs ToolResultRedactor).
        if ($name === 'getLandedCost') {
            return false;
        }

        return true;
    }

    private function mapMessage(ConversationMessage $row): ?AiMessage
    {
        if ($row->role === ConversationMessageRole::System) {
            return AiMessage::system((string) $row->content);
        }

        if ($row->role === ConversationMessageRole::User) {
            $text = (string) $row->content;
            $resolved = $row->metadata['resolved_standalone_question'] ?? null;
            if (is_string($resolved) && trim($resolved) !== '') {
                $text = $resolved;
            }

            return AiMessage::user($text);
        }

        if ($row->role === ConversationMessageRole::Assistant) {
            return AiMessage::assistant((string) $row->content);
        }

        if ($row->role === ConversationMessageRole::Tool) {
            $toolCallId = $row->metadata['provider_tool_call_id'] ?? '';

            $payload = json_decode((string) $row->content, true);
            if (! is_array($payload)) {
                $payload = ['raw' => (string) $row->content];
            }

            return AiMessage::toolResult(is_string($toolCallId) ? $toolCallId : 'unknown', $payload);
        }

        return null;
    }
}
