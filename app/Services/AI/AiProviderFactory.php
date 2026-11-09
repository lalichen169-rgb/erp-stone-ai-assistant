<?php

namespace App\Services\AI;

use App\Contracts\AiProviderInterface;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Contracts\Foundation\Application;

class AiProviderFactory
{
    public function __construct(private readonly Application $app) {}

    public function make(?string $provider = null): AiProviderInterface
    {
        $provider = strtolower(trim($provider ?? (string) config('ai.provider')));

        return match ($provider) {
            'openai' => $this->app->make(OpenAiProvider::class),
            'claude', 'anthropic' => $this->app->make(ClaudeProvider::class),
            'ollama' => $this->app->make(OllamaProvider::class),
            'bedrock', 'gemini', 'groq' => throw new AiProviderException(
                "AI provider [{$provider}] is not implemented yet.",
                AiProviderException::CODE_UNSUPPORTED_PROVIDER
            ),
            default => throw new AiProviderException(
                "AI provider [{$provider}] is not supported.",
                AiProviderException::CODE_UNSUPPORTED_PROVIDER
            ),
        };
    }
}
