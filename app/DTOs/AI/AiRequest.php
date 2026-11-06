<?php

namespace App\DTOs\AI;

use InvalidArgumentException;

final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  list<AiToolDefinition>  $tools
     * @param  array<string, string>  $metadata
     * @param  list<array<string, mixed>>|null  $claudeMessages  Full Messages API history (bypasses normalized messages when set)
     * @param  list<array<string, mixed>>|null  $openaiToolOutputInput  Raw input items for Responses API continuation
     * @param  string|array<string, mixed>|null  $toolChoice  OpenAI: string (e.g. auto). Claude: array (e.g. {"type":"auto"})
     */
    public function __construct(
        public array $messages,
        public ?string $systemInstruction = null,
        public array $tools = [],
        public ?string $model = null,
        public array $metadata = [],
        public ?string $openaiPreviousResponseId = null,
        public ?array $openaiToolOutputInput = null,
        public ?array $claudeMessages = null,
        public string|array|null $toolChoice = null,
    ) {
        $hasOpenAiContinuation = $openaiPreviousResponseId !== null && $openaiToolOutputInput !== null;
        $hasClaudeConversation = $claudeMessages !== null;

        if ($messages === [] && ! $hasOpenAiContinuation && ! $hasClaudeConversation) {
            throw new InvalidArgumentException('At least one AI message is required.');
        }

        if ($openaiPreviousResponseId !== null || $openaiToolOutputInput !== null) {
            if ($openaiPreviousResponseId === null || trim($openaiPreviousResponseId) === '' || $openaiToolOutputInput === null) {
                throw new InvalidArgumentException('OpenAI tool continuation requires both previous response id and tool output items.');
            }
        }

        foreach ($messages as $message) {
            if (! $message instanceof AiMessage) {
                throw new InvalidArgumentException('Each message must be an AiMessage instance.');
            }
        }

        foreach ($tools as $tool) {
            if (! $tool instanceof AiToolDefinition) {
                throw new InvalidArgumentException('Each tool must be an AiToolDefinition instance.');
            }
        }

        if ($systemInstruction !== null && trim($systemInstruction) === '') {
            throw new InvalidArgumentException('System instruction cannot be empty when provided.');
        }
    }
}
