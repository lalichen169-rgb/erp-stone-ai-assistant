<?php

namespace App\Services\AI\Context;

use App\Models\Conversation;

final class ConversationThreadAnchorResolver
{
    public function __construct(
        private readonly ConversationTurnPairCollector $pairCollector,
        private readonly ConversationThreadClassifier $threadClassifier,
        private readonly DeterministicFollowUpResolver $followUpResolver,
    ) {}

    /**
     * @return array{question: string, answer: string, type: ?FollowUpThreadType}|null
     */
    public function resolveAnchor(Conversation $conversation, string $followUp): ?array
    {
        $pairs = $this->pairCollector->recentCompletedPairs($conversation);
        if ($pairs === []) {
            return null;
        }

        foreach ($pairs as $pair) {
            $type = $this->threadClassifier->classify($pair['question'], $pair['answer']);

            $expanded = $this->followUpResolver->tryExpand(
                $followUp,
                $pair['question'],
                $pair['answer'],
                $type,
            );
            if ($expanded !== null) {
                return [
                    'question' => $pair['question'],
                    'answer' => $pair['answer'],
                    'type' => $type,
                ];
            }
        }

        $first = $pairs[0];

        return [
            'question' => $first['question'],
            'answer' => $first['answer'],
            'type' => $this->threadClassifier->classify($first['question'], $first['answer']),
        ];
    }
}
