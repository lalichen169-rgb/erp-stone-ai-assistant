<?php

namespace App\Services\AI\ToolCalling;

use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Contracts\Foundation\Application;

final class AiToolCallingDriverFactory
{
    public function __construct(private readonly Application $app) {}

    public function make(?string $provider = null): AiToolCallingDriverInterface
    {
        $provider = strtolower(trim($provider ?? (string) config('ai.provider')));

        if ($provider === 'anthropic') {
            $provider = 'claude';
        }

        return match ($provider) {
            'openai' => $this->app->make(OpenAiToolCallingDriver::class),
            'claude' => $this->app->make(ClaudeToolCallingDriver::class),
            default => throw new AiProviderException(
                "AI provider [{$provider}] does not support the tool-calling orchestration driver.",
                AiProviderException::CODE_UNSUPPORTED_PROVIDER,
            ),
        };
    }
}
