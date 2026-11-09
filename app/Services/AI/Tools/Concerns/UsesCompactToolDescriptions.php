<?php

namespace App\Services\AI\Tools\Concerns;

trait UsesCompactToolDescriptions
{
    protected function useCompactToolDescriptions(): bool
    {
        if (strtolower((string) config('ai.provider')) !== 'ollama') {
            return false;
        }

        return (bool) config('ai.assistant.compact_tool_descriptions', false);
    }
}
