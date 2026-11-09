<?php

namespace App\DTOs\AI;

final readonly class AiToolCallingOutcome
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?string $providerResponseId = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public int $toolIterations = 0,
        public int $toolCallsExecuted = 0,
        public bool $completed = true,
        public ?string $incompleteReason = null,
        /**
         * Provider or application error code when {@see $completed} is false (e.g. rate_limit, max_tokens).
         */
        public ?string $errorCode = null,
    ) {}
}
