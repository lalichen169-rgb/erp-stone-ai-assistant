<?php

namespace App\Services\AI\Context;

use App\Enums\ConversationMessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;

/**
 * @phpstan-type TurnPair array{user: ConversationMessage, assistant: ConversationMessage, question: string, answer: string}
 */
final class ConversationTurnPairCollector
{
    /**
     * @return list<TurnPair>
     */
    public function recentCompletedPairs(Conversation $conversation, int $limit = 6): array
    {
        $users = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->where('role', ConversationMessageRole::User)
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();

        $pairs = [];
        foreach ($users as $userMessage) {
            $assistant = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('is_internal', false)
                ->where('role', ConversationMessageRole::Assistant)
                ->where('sequence', '>', $userMessage->sequence)
                ->orderBy('sequence')
                ->first();

            if ($assistant === null) {
                continue;
            }

            $pairs[] = [
                'user' => $userMessage,
                'assistant' => $assistant,
                'question' => $this->questionText($userMessage),
                'answer' => (string) $assistant->content,
            ];
        }

        return $pairs;
    }

    private function questionText(ConversationMessage $message): string
    {
        $meta = $message->metadata;
        if (is_array($meta)) {
            $resolved = $meta['resolved_standalone_question'] ?? null;
            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        return trim((string) $message->content);
    }
}
