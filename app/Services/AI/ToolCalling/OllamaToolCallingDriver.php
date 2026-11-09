<?php

namespace App\Services\AI\ToolCalling;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Tools\ToolExecutionResult;

final class OllamaToolCallingDriver implements AiToolCallingDriverInterface
{
    /**
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    public function buildFirstRequest(AiRequest $original, array $definitions): AiRequest
    {
        return new AiRequest(
            messages: $original->messages,
            systemInstruction: $original->systemInstruction,
            tools: $definitions,
            model: $original->model,
            metadata: $original->metadata,
            ollamaMessages: $this->buildInitialOllamaMessages($original),
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
        $history = $modelResponse->ollamaConversationExtension;

        if ($history === null) {
            throw new AiProviderException(
                'Ollama tool continuation requires conversation extension state from the provider.',
                AiProviderException::CODE_INVALID_RESPONSE,
            );
        }

        $assistantMessage = $modelResponse->ollamaAssistantMessage;

        if ($assistantMessage === null || $assistantMessage === []) {
            throw new AiProviderException(
                'Ollama returned tool calls without an assistant message.',
                AiProviderException::CODE_INVALID_RESPONSE,
            );
        }

        $messages = $history;

        $messages[] = $assistantMessage;

        foreach ($modelResponse->toolCalls as $call) {
            if (! isset($resultsByCallId[$call->id])) {
                throw new AiProviderException(
                    'Internal error: missing tool result for a model tool call.',
                    AiProviderException::CODE_INVALID_RESPONSE,
                );
            }

            $toolMessage = [
                'role' => 'tool',
                'content' => $this->encodeResult($resultsByCallId[$call->id]),
            ];

            if ($call->id !== '') {
                $toolMessage['tool_call_id'] = $call->id;
            }

            $toolMessage['tool_name'] = $call->name;

            $messages[] = $toolMessage;
        }

        return new AiRequest(
            messages: [],
            systemInstruction: $original->systemInstruction,
            tools: $definitions,
            model: $original->model,
            metadata: $original->metadata,
            ollamaMessages: $messages,
            stockCountGrounding: $original->stockCountGrounding,
            stockCountGroundingQuestion: $original->stockCountGroundingQuestion,
            stockCountInheritedProductFilters: $original->stockCountInheritedProductFilters,
            stockCountRawUserMessage: $original->stockCountRawUserMessage,
        );
    }

    /**
     * Map normalized conversation messages to Ollama /api/chat history, including persisted tool results.
     *
     * Stored turns are ordered user → tool result(s) → final assistant text (the model tool-call
     * assistant message is not persisted). Consecutive tool messages are rebuilt as one assistant
     * message with tool_calls followed by role:tool rows, matching same-turn {@see buildFollowUpRequest}.
     *
     * @return list<array<string, mixed>>
     */
    private function buildInitialOllamaMessages(AiRequest $request): array
    {
        $messages = [];

        if ($request->systemInstruction !== null && trim($request->systemInstruction) !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemInstruction,
            ];
        }

        $conversationMessages = $request->messages;
        $count = count($conversationMessages);

        for ($index = 0; $index < $count; $index++) {
            $message = $conversationMessages[$index];

            if ($message->role === AiMessage::ROLE_SYSTEM) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $message->content,
                ];

                continue;
            }

            if ($message->role === AiMessage::ROLE_TOOL) {
                /** @var list<AiMessage> $toolMessages */
                $toolMessages = [];
                while ($index < $count && $conversationMessages[$index]->role === AiMessage::ROLE_TOOL) {
                    $toolMessages[] = $conversationMessages[$index];
                    $index++;
                }
                $index--;

                $messages[] = $this->buildHistoricalAssistantToolCallMessage($toolMessages);

                foreach ($toolMessages as $toolMessage) {
                    $messages[] = $this->mapToolMessageToOllama($toolMessage);
                }

                continue;
            }

            $messages[] = [
                'role' => $message->role === AiMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $message->content,
            ];
        }

        if ($messages === []) {
            throw new AiProviderException(
                'Ollama request requires at least one user or assistant message.',
                AiProviderException::CODE_PROVIDER_ERROR,
            );
        }

        return $messages;
    }

    /**
     * @param  list<AiMessage>  $toolMessages
     * @return array<string, mixed>
     */
    private function buildHistoricalAssistantToolCallMessage(array $toolMessages): array
    {
        $toolCalls = [];

        foreach ($toolMessages as $i => $toolMessage) {
            $name = $toolMessage->toolName;
            if ($name === null || trim($name) === '') {
                $name = 'unknown_tool';
            }

            $id = $toolMessage->toolCallId ?? '';
            if (trim($id) === '' || $id === 'unknown') {
                $id = 'historical_call_'.$i;
            }

            $arguments = $toolMessage->toolCallArguments;
            if (! is_array($arguments)) {
                $arguments = [];
            }

            $toolCalls[] = [
                'id' => $id,
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ];
        }

        return [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapToolMessageToOllama(AiMessage $message): array
    {
        $content = $message->content;
        if ($message->toolPayload !== null) {
            $content = json_encode($message->toolPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $toolMessage = [
            'role' => 'tool',
            'content' => $content,
        ];

        $id = $message->toolCallId ?? '';
        if (trim($id) !== '' && $id !== 'unknown') {
            $toolMessage['tool_call_id'] = $id;
        }

        $name = $message->toolName;
        if (is_string($name) && trim($name) !== '') {
            $toolMessage['tool_name'] = $name;
        }

        return $toolMessage;
    }

    private function encodeResult(ToolExecutionResult $result): string
    {
        return json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
