<?php

namespace App\Services\AI\Tools\Concerns;

use App\Support\ThicknessCmNormalizer;

trait NormalizesThicknessCmArgument
{
    private function normalizeThicknessCm(mixed $value): string|\App\Services\AI\Tools\ToolExecutionResult|null
    {
        return ThicknessCmNormalizer::normalize($value);
    }
}
