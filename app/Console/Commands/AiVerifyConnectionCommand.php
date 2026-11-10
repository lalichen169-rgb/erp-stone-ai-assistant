<?php

namespace App\Console\Commands;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Services\AI\AiAgentService;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\OllamaProvider;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiVerifyConnectionCommand extends Command
{
    protected $signature = 'ai:verify-connection';

    protected $description = 'Send one test prompt through AiAgentService to verify the configured AI provider (requires a local API key).';

    public function handle(AiAgentService $aiAgentService, OllamaProvider $ollamaProvider): int
    {
        $provider = strtolower((string) config('ai.provider'));

        $this->info('Provider: '.$provider);

        if ($provider === 'ollama') {
            $model = (string) config('ai.ollama.model');
            $this->line('Configured Ollama model: '.($model !== '' ? $model : '(not set)'));

            try {
                $this->verifyOllamaReachability($ollamaProvider);
            } catch (AiProviderException $exception) {
                $this->error($exception->getMessage());
                $this->line('App error code: '.$exception->errorCode());
                $this->comment('Check that AI_OLLAMA_BASE_URL is reachable from this machine, port 11434 is open, and the Ollama daemon is running.');

                return self::FAILURE;
            }
        }

        try {
            $response = $aiAgentService->complete(new AiRequest(
                messages: [
                    AiMessage::user('Reply with exactly: Stone ERP AI connection successful.'),
                ],
            ));
        } catch (AiProviderException $exception) {
            $this->error($exception->getMessage());
            $status = $exception->httpStatus();
            if ($status !== null) {
                $this->line('HTTP status: '.$status);
            }
            $this->line('App error code: '.$exception->errorCode());
            $type = $exception->providerErrorType();
            if ($type !== null && $type !== '') {
                $this->line('OpenAI error type: '.$type);
            }
            $code = $exception->providerErrorCode();
            if ($code !== null && $code !== '') {
                $this->line('OpenAI error code: '.(string) $code);
            }
            $rid = $exception->requestId();
            if ($rid !== null && $rid !== '') {
                $this->line('Request ID: '.$rid);
            }

            if ($provider === 'ollama') {
                $this->comment('Ollama chat verification failed. Confirm the model is pulled on the server and supports chat.');
            }

            return self::FAILURE;
        }

        $this->line('Model: '.$response->model);
        $this->line('Response ID: '.($response->providerResponseId ?? 'n/a'));
        $this->line('Text: '.$response->text);

        if ($response->inputTokens !== null || $response->outputTokens !== null) {
            $this->line(sprintf(
                'Tokens: input=%s output=%s total=%s',
                $response->inputTokens ?? 'n/a',
                $response->outputTokens ?? 'n/a',
                $response->totalTokens ?? 'n/a'
            ));
        } else {
            $this->line('Tokens: not reported by provider');
        }

        if ($provider === 'ollama') {
            $this->info('Ollama /api/chat verification succeeded.');
        }

        return self::SUCCESS;
    }

    private function verifyOllamaReachability(OllamaProvider $ollamaProvider): void
    {
        $ollamaProvider->assertConfiguration();

        $baseUrl = rtrim((string) config('ai.ollama.base_url'), '/');
        $connectTimeout = (int) config('ai.ollama.connect_timeout', 10);
        $timeout = (int) config('ai.ollama.request_timeout', 120);
        $model = (string) config('ai.ollama.model');

        try {
            $response = Http::baseUrl($baseUrl)
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->acceptJson()
                ->get('/api/tags');
        } catch (ConnectionException $exception) {
            throw new AiProviderException(
                'Could not connect to the Ollama server at the configured base URL.',
                AiProviderException::CODE_TIMEOUT,
                previous: $exception
            );
        } catch (Throwable $exception) {
            throw new AiProviderException(
                'Ollama tags request failed.',
                AiProviderException::CODE_PROVIDER_ERROR,
                previous: $exception
            );
        }

        if ($response->failed()) {
            throw new AiProviderException(
                'Ollama server responded with HTTP '.$response->status().'.',
                AiProviderException::CODE_PROVIDER_ERROR,
                $response->status()
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new AiProviderException(
                'Ollama tags endpoint returned invalid JSON.',
                AiProviderException::CODE_INVALID_RESPONSE
            );
        }

        if ($model === '') {
            throw new AiProviderException(
                'Ollama model is not configured.',
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        $names = [];

        foreach ($body['models'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $names[] = (string) $entry['name'];
            }
        }

        $found = in_array($model, $names, true)
            || $this->modelNameMatches($model, $names);

        if (! $found) {
            throw new AiProviderException(
                "Configured Ollama model [{$model}] was not found on the server. Pull or tag the model on the Ollama host.",
                AiProviderException::CODE_PROVIDER_ERROR
            );
        }

        $this->line('Ollama server reachable; model appears in /api/tags.');
    }

    /**
     * @param  list<string>  $available
     */
    private function modelNameMatches(string $configured, array $available): bool
    {
        foreach ($available as $name) {
            if ($name === $configured) {
                return true;
            }

            if (str_starts_with($name, $configured.':')) {
                return true;
            }

            if (str_starts_with($configured, $name.':')) {
                return true;
            }
        }

        return false;
    }
}
