<?php

namespace App\Services\AI;

use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\Services\AI\Exceptions\AiProviderException;

final class AiAgentService
{
    public function __construct(private readonly AiProviderFactory $providerFactory) {}

    /**
     * @throws AiProviderException
     */
    public function complete(AiRequest $request): AiResponse
    {
        return $this->providerFactory->make()->complete($request);
    }
}
