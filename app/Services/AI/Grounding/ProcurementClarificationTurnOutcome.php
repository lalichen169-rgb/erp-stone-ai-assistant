<?php

namespace App\Services\AI\Grounding;

/**
 * Result of deterministic procurement warehouse clarification handling for one assistant turn.
 */
final readonly class ProcurementClarificationTurnOutcome
{
    /**
     * @param  array<string, mixed>  $assistantMetadata
     * @param  array<string, mixed>|null  $proposal
     */
    public function __construct(
        public string $assistantText,
        public bool $clarificationRequired,
        public ?string $clarificationQuestion,
        public array $assistantMetadata = [],
        public ?array $proposal = null,
    ) {}
}
