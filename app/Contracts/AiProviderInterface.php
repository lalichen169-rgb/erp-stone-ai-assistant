<?php

namespace App\Contracts;

use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;

interface AiProviderInterface
{
    /**
     * Provider identifier (e.g. openai, claude).
     */
    public function name(): string;

    /**
     * Send a normalized completion request to the provider.
     */
    public function complete(AiRequest $request): AiResponse;
}
