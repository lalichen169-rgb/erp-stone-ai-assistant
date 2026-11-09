<?php

namespace App\DTOs\AI;

final readonly class AiResponse
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  list<array<string, mixed>>|null  $claudeAssistantBlocks
     * @param  list<array<string, mixed>>|null  $claudeConversationExtension  Messages API history snapshot for tool-loop continuation (Claude only)
     * @param  array<string, mixed>|null  $ollamaAssistantMessage  Raw assistant message from Ollama /api/chat (tool_calls + content)
     * @param  list<array<string, mixed>>|null  $ollamaConversationExtension  Ollama chat history snapshot for tool-loop continuation
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
        public ?array $ollamaAssistantMessage = null,
        public ?array $ollamaConversationExtension = null,
    ) {}
}
