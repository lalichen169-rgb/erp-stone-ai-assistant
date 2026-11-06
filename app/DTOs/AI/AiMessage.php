<?php

namespace App\DTOs\AI;

use InvalidArgumentException;

final readonly class AiMessage
{
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /**
     * @param  array<string, mixed>|null  $toolPayload  Structured tool result for serialization
     * @param  list<array<string, mixed>>|null  $claudeAssistantBlocks  Assistant message content blocks (tool_use + text)
     */
    public function __construct(
        public string $role,
        public string $content,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?array $toolPayload = null,
        public ?array $claudeAssistantBlocks = null,
    ) {
        if (trim($this->role) === '') {
            throw new InvalidArgumentException('AI message role is required.');
        }

        if (! in_array($this->role, [self::ROLE_SYSTEM, self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_TOOL], true)) {
            throw new InvalidArgumentException('AI message role is invalid.');
        }

        if ($this->role === self::ROLE_TOOL) {
            if ($this->toolCallId === null || trim($this->toolCallId) === '') {
                throw new InvalidArgumentException('Tool messages require a tool call id.');
            }

            if ($this->toolPayload === null && trim($this->content) === '') {
                throw new InvalidArgumentException('Tool messages require content or toolPayload.');
            }
        } elseif ($this->claudeAssistantBlocks !== null) {
            if ($this->role !== self::ROLE_ASSISTANT) {
                throw new InvalidArgumentException('Assistant content blocks require assistant role.');
            }

            if ($this->claudeAssistantBlocks === []) {
                throw new InvalidArgumentException('Assistant content blocks cannot be empty.');
            }
        } elseif (trim($this->content) === '') {
            throw new InvalidArgumentException('AI message content is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function toolResult(string $toolCallId, array $payload): self
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return new self(
            role: self::ROLE_TOOL,
            content: $json,
            toolCallId: $toolCallId,
            toolPayload: $payload,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function assistantWithClaudeBlocks(array $blocks): self
    {
        return new self(
            role: self::ROLE_ASSISTANT,
            content: '',
            claudeAssistantBlocks: $blocks,
        );
    }

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }
}
