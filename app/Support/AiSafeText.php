<?php

namespace App\Support;

use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Exceptions\AiToolLoopException;

final class AiSafeText
{
    public static function forLoopException(AiToolLoopException $e): string
    {
        return match ($e->errorCode) {
            AiToolLoopException::CODE_MAX_ITERATIONS => 'I stopped because the tool-calling limit was reached. Please narrow your request or try again.',
            AiToolLoopException::CODE_MAX_TOOL_CALLS => 'I stopped because too many tools would be needed for one answer. Please simplify your question.',
            AiToolLoopException::CODE_REPEATED_SAME_CALLS => 'I detected repeated identical tool requests and stopped to avoid a loop. Please rephrase.',
            default => 'Your request could not be completed safely.',
        };
    }

    public static function forProviderException(AiProviderException $e): string
    {
        return match ($e->errorCode()) {
            AiProviderException::CODE_AUTHENTICATION => 'The AI service is not authenticated. Check server configuration.',
            AiProviderException::CODE_RATE_LIMIT => 'The AI service is temporarily rate-limited. Try again shortly.',
            AiProviderException::CODE_TIMEOUT => 'The AI request timed out. Try again.',
            AiProviderException::CODE_SERVER_ERROR => 'The AI service is temporarily unavailable. Try again later.',
            default => 'The AI request could not be completed.',
        };
    }
}
