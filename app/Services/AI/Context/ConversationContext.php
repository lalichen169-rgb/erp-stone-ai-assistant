<?php

namespace App\Services\AI\Context;

use App\DTOs\AI\AiMessage;

/**
 * A bounded slice of conversation for model input (Phase 7).
 */
final readonly class ConversationContext
{
    /**
     * @param  list<AiMessage>  $aiMessages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $aiMessages,
        public ?string $latestSummaryText = null,
        public array $metadata = [],
    ) {}
}
