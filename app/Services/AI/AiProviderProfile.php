<?php

namespace App\Services\AI;

final class AiProviderProfile
{
    public static function normalize(?string $provider = null): string
    {
        $value = strtolower(trim($provider ?? (string) config('ai.provider', 'openai')));

        return match ($value) {
            'anthropic' => 'claude',
            default => $value,
        };
    }

    /**
     * OpenAI and Claude use pre-Ollama prompts, tool schemas, and history mapping.
     */
    public static function usesLegacyOpenAiClaudePresentation(?string $provider = null): bool
    {
        return in_array(self::normalize($provider), ['openai', 'claude'], true);
    }

    public static function isOllama(?string $provider = null): bool
    {
        return self::normalize($provider) === 'ollama';
    }
}
