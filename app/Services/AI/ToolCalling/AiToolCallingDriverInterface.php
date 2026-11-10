<?php

namespace App\Services\AI\ToolCalling;

use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\Services\AI\Tools\ToolExecutionResult;

/**
 * Provider-specific continuation logic for the tool-calling loop (Phase 6.3).
 * The orchestration service depends only on this contract, not on OpenAI or Anthropic details.
 */
interface AiToolCallingDriverInterface
{
    /**
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    public function buildFirstRequest(AiRequest $original, array $definitions): AiRequest;

    /**
     * @param  array<string, ToolExecutionResult>  $resultsByCallId
     * @param  list<\App\DTOs\AI\AiToolDefinition>  $definitions
     */
    public function buildFollowUpRequest(
        AiRequest $original,
        AiResponse $modelResponse,
        array $resultsByCallId,
        array $definitions,
    ): AiRequest;
}
