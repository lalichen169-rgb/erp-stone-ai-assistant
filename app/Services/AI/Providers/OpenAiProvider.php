<?php

namespace App\Services\AI\Providers;

use App\Contracts\AiProviderInterface;
use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\DTOs\AI\AiToolCall;
use App\Services\AI\Exceptions\AiProviderException;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Responses\Responses\Output\OutputFunctionToolCall;
use OpenAI\Responses\Responses\CreateResponse;
use Throwable;

final class OpenAiProvider implements AiProviderInterface
{
    public function __construct(
        private readonly ClientContract $client,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->assertApiKeyConfigured();

        $model = $request->model ?? (string) config('ai.openai.model');

        if ($model === '') {
            throw new AiProviderException(
                'OpenAI model is not configured.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        $parameters = $this->buildResponsesParameters($request, $model);

        try {
            $response = $this->client->responses()->create($parameters);
        } catch (ErrorException $exception) {
            throw $this->mapOpenAiError($exception);
        } catch (TransporterException $exception) {
            throw new AiProviderException(
                'OpenAI request timed out or could not connect.',
                AiProviderException::CODE_TIMEOUT,
                previous: $exception
            );
        } catch (Throwable $exception) {
            throw new AiProviderException(
                'OpenAI request failed.',
                AiProviderException::CODE_PROVIDER_ERROR,
                previous: $exception
            );
        }

        return $this->mapResponse($response, $model);
    }

    private function assertApiKeyConfigured(): void
    {
        $apiKey = config('ai.openai.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new AiProviderException(
                'OpenAI API key is not configured.',
                AiProviderException::CODE_MISSING_API_KEY
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponsesParameters(AiRequest $request, string $model): array
    {
        if ($request->openaiToolOutputInput !== null) {
            if ($request->openaiPreviousResponseId === null || trim($request->openaiPreviousResponseId) === '') {
                throw new AiProviderException(
                    'OpenAI tool output submission requires previous_response_id.',
                    AiProviderException::CODE_PROVIDER_ERROR,
                );
            }

            $parameters = [
                'model' => $model,
                'previous_response_id' => $request->openaiPreviousResponseId,
                'input' => array_map(
                    static fn (array $item): array => array_intersect_key(
                        $item,
                        array_flip(['type', 'call_id', 'output'])
                    ),
                    $request->openaiToolOutputInput,
                ),
            ];

            if ($request->tools !== []) {
                $parameters['tools'] = $this->mapTools($request->tools);
            }

            if ($request->toolChoice !== null) {
                $parameters['tool_choice'] = $this->normalizeOpenAiToolChoice($request->toolChoice);
            }

            return $parameters;
        }

        $instructions = $request->systemInstruction;
        $input = [];

        foreach ($request->messages as $message) {
            if ($message->role === AiMessage::ROLE_SYSTEM) {
                $instructions = $instructions === null
                    ? $message->content
                    : $instructions."\n\n".$message->content;

                continue;
            }

            if ($message->role === AiMessage::ROLE_TOOL) {
                continue;
            }

            $input[] = [
                'role' => $message->role === AiMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $message->content,
                    ],
                ],
            ];
        }

        if ($input === []) {
            throw new AiProviderException(
                'OpenAI request requires at least one user or assistant message.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        $parameters = [
            'model' => $model,
            'input' => $input,
        ];

        if ($instructions !== null && trim($instructions) !== '') {
            $parameters['instructions'] = $instructions;
        }

        if ($request->tools !== []) {
            $parameters['tools'] = $this->mapTools($request->tools);
        }

        if ($request->toolChoice !== null) {
            $parameters['tool_choice'] = $this->normalizeOpenAiToolChoice($request->toolChoice);
        }

        if ($request->metadata !== []) {
            $parameters['metadata'] = $request->metadata;
        }

        return $parameters;
    }

    /**
     * @param  string|array<string, mixed>  $toolChoice
     * @return string|array<string, mixed>
     */
    private function normalizeOpenAiToolChoice(string|array $toolChoice): string|array
    {
        if (is_array($toolChoice)) {
            return 'auto';
        }

        return $toolChoice;
    }

    /**
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $tools
     * @return list<array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        return array_map(
            fn (\App\DTOs\AI\AiToolDefinition $tool): array => [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters,
            ],
            $tools
        );
    }

    private function mapResponse(CreateResponse $response, string $requestedModel): AiResponse
    {
        if ($response->status === 'failed') {
            throw new AiProviderException(
                'OpenAI returned a failed response.',
                AiProviderException::CODE_INVALID_RESPONSE
            );
        }

        $text = $response->outputText ?? '';
        $toolCalls = [];

        foreach ($response->output as $item) {
            if ($item instanceof OutputFunctionToolCall) {
                $decoded = json_decode($item->arguments, true);
                $toolCalls[] = new AiToolCall(
                    id: $item->callId !== '' ? $item->callId : $item->id,
                    name: $item->name,
                    arguments: is_array($decoded) ? $decoded : ['raw' => $item->arguments],
                );
            }
        }

        $usage = $response->usage;

        return new AiResponse(
            text: $text,
            provider: $this->name(),
            model: $response->model !== '' ? $response->model : $requestedModel,
            providerResponseId: $response->id,
            inputTokens: $usage?->inputTokens,
            outputTokens: $usage?->outputTokens,
            totalTokens: $usage?->totalTokens,
            finishReason: $response->status,
            toolCalls: $toolCalls,
            stopReason: null,
            claudeAssistantBlocks: null,
            claudeConversationExtension: null,
        );
    }

    private function mapOpenAiError(ErrorException $exception): AiProviderException
    {
        $status = $exception->getStatusCode();
        $message = $this->sanitizeMessage($exception->getErrorMessage());

        return match (true) {
            $status === 401 || $status === 403 => new AiProviderException(
                'OpenAI authentication failed.',
                AiProviderException::CODE_AUTHENTICATION,
                $status,
                $exception
            ),
            $status === 429 => new AiProviderException(
                'OpenAI rate limit exceeded.',
                AiProviderException::CODE_RATE_LIMIT,
                $status,
                $exception
            ),
            $status >= 500 => new AiProviderException(
                'OpenAI service is temporarily unavailable.',
                AiProviderException::CODE_SERVER_ERROR,
                $status,
                $exception
            ),
            default => new AiProviderException(
                $message !== '' ? $message : 'OpenAI request failed.',
                AiProviderException::CODE_PROVIDER_ERROR,
                $status,
                $exception
            ),
        };
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/sk-[A-Za-z0-9_-]+/', '[redacted]', $message) ?? $message;

        return preg_replace('/Bearer\s+\S+/', 'Bearer [redacted]', $message) ?? $message;
    }
}
