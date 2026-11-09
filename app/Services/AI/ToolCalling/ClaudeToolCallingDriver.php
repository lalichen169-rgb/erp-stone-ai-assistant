<?php

namespace App\Services\AI\ToolCalling;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Tools\ToolExecutionResult;

final class ClaudeToolCallingDriver implements AiToolCallingDriverInterface
{
    /**
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    public function buildFirstRequest(AiRequest $original, array $definitions): AiRequest
    {
        return new AiRequest(
            messages: [],
            systemInstruction: $original->systemInstruction,
            tools: $definitions,
            model: $original->model,
            metadata: $original->metadata,
            claudeMessages: $this->buildInitialClaudeMessages($original),
            toolChoice: $definitions === [] ? null : ($original->toolChoice ?? ['type' => 'auto']),
            stockCountGrounding: $original->stockCountGrounding,
            stockCountGroundingQuestion: $original->stockCountGroundingQuestion,
            stockCountInheritedProductFilters: $original->stockCountInheritedProductFilters,
            stockCountRawUserMessage: $original->stockCountRawUserMessage,
        );
    }

    /**
     * @param  array<string, ToolExecutionResult>  $resultsByCallId
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    public function buildFollowUpRequest(
        AiRequest $original,
        AiResponse $modelResponse,
        array $resultsByCallId,
        array $definitions,
    ): AiRequest {
        $history = $modelResponse->claudeConversationExtension;

        if ($history === null) {
            throw new AiProviderException(
                'Claude tool continuation requires conversation extension state from the provider.',
                AiProviderException::CODE_INVALID_RESPONSE,
            );
        }

        $assistantBlocks = $modelResponse->claudeAssistantBlocks ?? [];

        if ($assistantBlocks === []) {
            throw new AiProviderException(
                'Claude returned tool calls without assistant content blocks.',
                AiProviderException::CODE_INVALID_RESPONSE,
            );
        }

        $messages = $history;

        $messages[] = [
            'role' => 'assistant',
            'content' => $assistantBlocks,
        ];

        $toolResultBlocks = [];

        foreach ($modelResponse->toolCalls as $call) {
            if (! isset($resultsByCallId[$call->id])) {
                throw new AiProviderException(
                    'Internal error: missing tool result for a model tool call.',
                    AiProviderException::CODE_INVALID_RESPONSE,
                );
            }

            $toolResultBlocks[] = [
                'type' => 'tool_result',
                'tool_use_id' => $call->id,
                'content' => $this->encodeResult($resultsByCallId[$call->id]),
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $toolResultBlocks,
        ];

        return new AiRequest(
            messages: [],
            systemInstruction: $original->systemInstruction,
            tools: $definitions,
            model: $original->model,
            metadata: $original->metadata,
            claudeMessages: $messages,
            toolChoice: $definitions === [] ? null : ['type' => 'auto'],
            stockCountGrounding: $original->stockCountGrounding,
            stockCountGroundingQuestion: $original->stockCountGroundingQuestion,
            stockCountInheritedProductFilters: $original->stockCountInheritedProductFilters,
            stockCountRawUserMessage: $original->stockCountRawUserMessage,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildInitialClaudeMessages(AiRequest $request): array
    {
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === AiMessage::ROLE_SYSTEM) {
                continue;
            }

            if ($message->role === AiMessage::ROLE_TOOL) {
                throw new AiProviderException(
                    'Initial Claude requests cannot include tool result messages.',
                    AiProviderException::CODE_INVALID_RESPONSE,
                );
            }

            if ($message->role === AiMessage::ROLE_ASSISTANT && $message->claudeAssistantBlocks !== null) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $message->claudeAssistantBlocks,
                ];

                continue;
            }

            $messages[] = [
                'role' => $message->role === AiMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $message->content,
            ];
        }

        if ($messages === []) {
            throw new AiProviderException(
                'Claude request requires at least one user or assistant message.',
                AiProviderException::CODE_PROVIDER_ERROR,
            );
        }

        return $messages;
    }

    private function encodeResult(ToolExecutionResult $result): string
    {
        return json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
