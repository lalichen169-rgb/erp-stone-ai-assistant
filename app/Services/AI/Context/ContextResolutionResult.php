<?php

namespace App\Services\AI\Context;

final readonly class ContextResolutionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $wasAlreadyStandalone,
        public bool $needsClarification,
        public ?string $resolvedStandaloneQuestion,
        public ?string $clarificationQuestion,
        public string $strategy,
        public array $metadata = [],
    ) {}
}
