<?php

namespace App\Services\AI\ToolCalling;

use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Tools\ToolExecutionResult;

final class OpenAiToolCallingDriver implements AiToolCallingDriverInterface
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
            toolChoice: $this->resolveToolChoice($original, $definitions),
            stockCountGrounding: $original->stockCountGrounding,
            stockCountGroundingQuestion: $original->stockCountGroundingQuestion,
            stockCountInheritedProductFilters: $original->stockCountInheritedProductFilters,
            stockCountRawUserMessage: $original->stockCountRawUserMessage,
        );
    }

    /**
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    private function resolveToolChoice(AiRequest $original, array $definitions): string|array|null
    {
        if ($definitions === []) {
            return null;
        }

        return $original->toolChoice ?? 'auto';
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
        $previousId = $modelResponse->providerResponseId;

        if ($previousId === null || trim($previousId) === '') {
            throw new AiProviderException(
                'OpenAI response did not include an id required for tool continuation.',
                AiProviderException::CODE_INVALID_RESPONSE,
            );
        }

        $input = [];

        foreach ($modelResponse->toolCalls as $call) {
            if (! isset($resultsByCallId[$call->id])) {
                throw new AiProviderException(
                    'Internal error: missing tool result for a model tool call.',
                    AiProviderException::CODE_INVALID_RESPONSE,
                );
            }

            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $call->id,
                'output' => $this->encodeResult($resultsByCallId[$call->id]),
            ];
        }

        return new AiRequest(
            messages: [],
            systemInstruction: $original->systemInstruction,
            tools: $definitions,
            model: $original->model,
            metadata: $original->metadata,
            openaiPreviousResponseId: $previousId,
            openaiToolOutputInput: $input,
            toolChoice: 'auto',
            stockCountGrounding: $original->stockCountGrounding,
            stockCountGroundingQuestion: $original->stockCountGroundingQuestion,
            stockCountInheritedProductFilters: $original->stockCountInheritedProductFilters,
            stockCountRawUserMessage: $original->stockCountRawUserMessage,
        );
    }

    private function encodeResult(ToolExecutionResult $result): string
    {
        return json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
