<?php

namespace App\DTOs\AI;

final readonly class AiResponse
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  list<array<string, mixed>>|null  $claudeAssistantBlocks
     * @param  list<array<string, mixed>>|null  $claudeConversationExtension  Messages API history snapshot for tool-loop continuation (Claude only)
     */
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?string $providerResponseId = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?string $finishReason = null,
        public array $toolCalls = [],
        public ?string $stopReason = null,
        public ?array $claudeAssistantBlocks = null,
        public ?array $claudeConversationExtension = null,
    ) {}
}
