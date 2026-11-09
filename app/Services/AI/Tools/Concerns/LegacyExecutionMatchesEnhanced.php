<?php

namespace App\Services\AI\Tools\Concerns;

use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;

/**
 * Pre-Ollama execution matches enhanced {@see execute()} for tools with no provider-specific run path.
 */
trait LegacyExecutionMatchesEnhanced
{
    public function executeLegacy(ToolExecutionContext $context, array $arguments): ToolExecutionResult
    {
        return $this->execute($context, $arguments);
    }
}
