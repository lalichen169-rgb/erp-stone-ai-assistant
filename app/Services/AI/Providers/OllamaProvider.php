<?php

namespace App\Services\AI\Providers;

use App\Contracts\AiProviderInterface;
use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\DTOs\AI\AiToolCall;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\Concerns\MapsHttpClientErrors;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class OllamaProvider implements AiProviderInterface
{
    use MapsHttpClientErrors;

    public function name(): string
    {
        return 'ollama';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->assertConfiguration();

        $model = $request->model ?? (string) config('ai.ollama.model');

        if ($model === '') {
            throw new AiProviderException(
                'Ollama model is not configured.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        $payload = $this->buildChatPayload($request, $model);

        try {
            $response = $this->httpClient()->post('/api/chat', $payload);
        } catch (Throwable $exception) {
            throw $this->mapHttpClientException($exception, 'Ollama');
        }

        if ($response->failed()) {
            throw $this->mapHttpResponseError($response, 'Ollama');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new AiProviderException(
                'Ollama returned an invalid JSON response.',
                AiProviderException::CODE_INVALID_RESPONSE
            );
        }

        if (isset($body['error']) && is_string($body['error']) && trim($body['error']) !== '') {
            throw new AiProviderException(
                $this->sanitizeProviderMessage($body['error']),
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        return $this->mapResponse($body, $model, $request);
    }

    public function assertConfiguration(): void
    {
        $baseUrl = config('ai.ollama.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new AiProviderException(
                'Ollama base URL is not configured.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new AiProviderException(
                'Ollama base URL is not valid.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }
    }

    private function httpClient(): PendingRequest
    {
        $baseUrl = rtrim((string) config('ai.ollama.base_url'), '/');
        $timeout = (int) config('ai.ollama.request_timeout', 120);
        $connectTimeout = (int) config('ai.ollama.connect_timeout', 10);

        return Http::baseUrl($baseUrl)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChatPayload(AiRequest $request, string $model): array
    {
        $messages = $this->resolveMessages($request);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        $keepAlive = config('ai.ollama.keep_alive');

        if (is_string($keepAlive) && trim($keepAlive) !== '') {
            $payload['keep_alive'] = $keepAlive;
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(
                fn (\App\DTOs\AI\AiToolDefinition $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->parameters,
                    ],
                ],
                $request->tools
            );
        }

        $chatOptions = config('ai.ollama.chat_options');

        if (is_array($chatOptions) && $chatOptions !== []) {
            $payload['options'] = $chatOptions;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveMessages(AiRequest $request): array
    {
        if ($request->ollamaMessages !== null) {
            return $request->ollamaMessages;
        }

        $messages = [];

        if ($request->systemInstruction !== null && trim($request->systemInstruction) !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemInstruction,
            ];
        }

        foreach ($request->messages as $message) {
            if ($message->role === AiMessage::ROLE_SYSTEM) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $message->content,
                ];

                continue;
            }

            if ($message->role === AiMessage::ROLE_TOOL) {
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
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function mapResponse(array $body, string $requestedModel, AiRequest $request): AiResponse
    {
        $message = $body['message'] ?? null;

        if (! is_array($message)) {
            throw new AiProviderException(
                'Ollama returned a chat response without a message object.',
                AiProviderException::CODE_INVALID_RESPONSE
            );
        }

        $text = isset($message['content']) ? (string) $message['content'] : '';
        $toolCalls = $this->parseToolCalls($message['tool_calls'] ?? []);

        $inputTokens = isset($body['prompt_eval_count']) ? (int) $body['prompt_eval_count'] : null;
        $outputTokens = isset($body['eval_count']) ? (int) $body['eval_count'] : null;
        $totalTokens = ($inputTokens !== null || $outputTokens !== null)
            ? (int) ($inputTokens ?? 0) + (int) ($outputTokens ?? 0)
            : null;

        return new AiResponse(
            text: $text,
            provider: $this->name(),
            model: (string) ($body['model'] ?? $requestedModel),
            providerResponseId: null,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            finishReason: isset($body['done_reason']) ? (string) $body['done_reason'] : null,
            toolCalls: $toolCalls,
            stopReason: isset($body['done_reason']) ? (string) $body['done_reason'] : null,
            ollamaAssistantMessage: $message,
            ollamaConversationExtension: $request->ollamaMessages,
        );
    }

    /**
     * @param  mixed  $rawToolCalls
     * @return list<AiToolCall>
     */
    private function parseToolCalls(mixed $rawToolCalls): array
    {
        if (! is_array($rawToolCalls)) {
            if ($rawToolCalls !== null) {
                throw new AiProviderException(
                    'Ollama returned tool calls in an invalid format.',
                    AiProviderException::CODE_INVALID_RESPONSE
                );
            }

            return [];
        }

        $parsed = [];

        foreach ($rawToolCalls as $index => $entry) {
            if (! is_array($entry)) {
                throw new AiProviderException(
                    'Ollama returned tool calls in an invalid format.',
                    AiProviderException::CODE_INVALID_RESPONSE
                );
            }

            $function = $entry['function'] ?? null;

            if (! is_array($function)) {
                throw new AiProviderException(
                    'Ollama tool call is missing a function object.',
                    AiProviderException::CODE_INVALID_RESPONSE
                );
            }

            $name = (string) ($function['name'] ?? '');

            if ($name === '') {
                throw new AiProviderException(
                    'Ollama tool call is missing a function name.',
                    AiProviderException::CODE_INVALID_RESPONSE
                );
            }

            $arguments = $function['arguments'] ?? [];

            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);

                if (! is_array($decoded)) {
                    throw new AiProviderException(
                        'Ollama tool call arguments are not valid JSON.',
                        AiProviderException::CODE_INVALID_RESPONSE
                    );
                }

                $arguments = $decoded;
            }

            if (! is_array($arguments)) {
                throw new AiProviderException(
                    'Ollama tool call arguments must be a JSON object.',
                    AiProviderException::CODE_INVALID_RESPONSE
                );
            }

            $id = (string) ($entry['id'] ?? '');

            if ($id === '') {
                $id = 'ollama_call_'.$index.'_'.Str::lower(Str::random(8));
            }

            $parsed[] = new AiToolCall(
                id: $id,
                name: $name,
                arguments: $arguments,
            );
        }

        return $parsed;
    }
}
