<?php

namespace App\Services\AI\Grounding;

use App\Enums\ConversationMessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;

/**
 * Inherits product filters (color, thickness, material) from earlier stock turns in the same conversation.
 */
final class StockThreadProductContextExtractor
{
    public function __construct(
        private readonly StockCountQuestionParser $parser,
    ) {}

    /**
     * @return array<string, mixed>|null  product filters only (color, thickness_cm, material, sku)
     */
    public function inheritProductFilters(Conversation $conversation, ConversationMessage $currentUserMessage): ?array
    {
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->where('role', ConversationMessageRole::User)
            ->where('sequence', '<', $currentUserMessage->sequence)
            ->orderByDesc('sequence')
            ->limit(8)
            ->get();

        foreach ($messages as $message) {
            $text = $this->questionText($message);
            if ($text === '') {
                continue;
            }

            $parsed = $this->parser->parse($text);
            if ($parsed === null) {
                continue;
            }

            $product = $parsed['product'];
            if (! isset($product['color']) && ! isset($product['sku'])) {
                continue;
            }

            unset($product['material']);

            return array_filter(
                $product,
                static fn ($value) => $value !== null && $value !== '',
            );
        }

        return null;
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
