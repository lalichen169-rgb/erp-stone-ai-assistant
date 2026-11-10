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
use Throwable;

final class ClaudeProvider implements AiProviderInterface
{
    use MapsHttpClientErrors;

    public function name(): string
    {
        return 'claude';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->assertApiKeyConfigured();

        $model = $request->model ?? (string) config('ai.claude.model');

        if ($model === '') {
            throw new AiProviderException(
                'Anthropic model is not configured.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        $payload = $this->buildMessagesPayload($request, $model);

        try {
            $response = $this->httpClient()->post('/v1/messages', $payload);
        } catch (Throwable $exception) {
            throw $this->mapHttpClientException($exception, 'Anthropic');
        }

        if ($response->failed()) {
            throw $this->mapHttpResponseError($response, 'Anthropic');
        }

        $body = $response->json();

        return $this->mapResponse(
            is_array($body) ? $body : [],
            $model,
            $request,
        );
    }

    private function assertApiKeyConfigured(): void
    {
        $apiKey = config('ai.claude.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new AiProviderException(
                'Anthropic API key is not configured.',
                AiProviderException::CODE_MISSING_API_KEY
            );
        }
    }

    private function httpClient(): PendingRequest
    {
        $baseUrl = rtrim((string) config('ai.claude.base_url'), '/');
        $timeout = (int) config('ai.claude.request_timeout', 30);

        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => (string) config('ai.claude.api_key'),
                'anthropic-version' => (string) config('ai.claude.api_version'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessagesPayload(AiRequest $request, string $model): array
    {
        $systemParts = [];

        if ($request->systemInstruction !== null && trim($request->systemInstruction) !== '') {
            $systemParts[] = $request->systemInstruction;
        }

        if ($request->claudeMessages !== null) {
            $messages = $request->claudeMessages;
        } else {
            $messages = [];

            foreach ($request->messages as $message) {
                if ($message->role === AiMessage::ROLE_SYSTEM) {
                    $systemParts[] = $message->content;

                    continue;
                }

                if ($message->role === AiMessage::ROLE_TOOL) {
                    continue;
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
                    'Anthropic request requires at least one user or assistant message.',
                    AiProviderException::CODE_PROVIDER_ERROR
                );
            }
        }

        $payload = [
            'model' => $model,
            'max_tokens' => (int) config('ai.claude.max_output_tokens', 1024),
            'messages' => $messages,
        ];

        if ($systemParts !== []) {
            $payload['system'] = implode("\n\n", $systemParts);
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(
                fn (\App\DTOs\AI\AiToolDefinition $tool): array => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'input_schema' => $tool->parameters,
                ],
                $request->tools
            );
        }

        if ($request->toolChoice !== null) {
            $payload['tool_choice'] = $request->toolChoice;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function mapResponse(array $body, string $requestedModel, AiRequest $request): AiResponse
    {
        if ($body === []) {
            throw new AiProviderException(
                'Anthropic returned an invalid response.',
                AiProviderException::CODE_INVALID_RESPONSE
            );
        }

        $textParts = [];
        $toolCalls = [];
        $assistantBlocks = [];

        foreach ($body['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $assistantBlocks[] = $block;

            if (($block['type'] ?? '') === 'text') {
                $textParts[] = (string) ($block['text'] ?? '');
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $input = $block['input'] ?? [];

                if (! is_array($input)) {
                    $input = [];
                }

                $toolCalls[] = new AiToolCall(
                    id: (string) ($block['id'] ?? ''),
                    name: (string) ($block['name'] ?? ''),
                    arguments: $input,
                );
            }
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        $conversationExtension = $request->claudeMessages;

        return new AiResponse(
            text: implode("\n", array_filter($textParts, fn (string $part) => $part !== '')),
            provider: $this->name(),
            model: (string) ($body['model'] ?? $requestedModel),
            providerResponseId: isset($body['id']) ? (string) $body['id'] : null,
            inputTokens: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            outputTokens: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            totalTokens: $this->sumTokens($usage),
            finishReason: null,
            toolCalls: $toolCalls,
            stopReason: isset($body['stop_reason']) ? (string) $body['stop_reason'] : null,
            claudeAssistantBlocks: $assistantBlocks,
            claudeConversationExtension: $conversationExtension,
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function sumTokens(array $usage): ?int
    {
        if (! isset($usage['input_tokens']) && ! isset($usage['output_tokens'])) {
            return null;
        }

        return (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
    }
}
