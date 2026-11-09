<?php

namespace App\Support;

use App\Services\AI\Tools\ToolExecutionResult;

final class ThicknessCmNormalizer
{
    public static function normalize(mixed $value): string|ToolExecutionResult|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^(\d+(?:\.\d+)?)\s*cm$/i', $trimmed, $matches) === 1) {
                $value = $matches[1];
            }
        }

        if (! is_numeric($value)) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: 'thickness_cm must be a numeric centimeter value (e.g. 3.00 or 3cm).',
            );
        }

        return number_format((float) $value, 2, '.', '');
    }

    public static function normalizeOrNull(mixed $value): ?string
    {
        $result = self::normalize($value);

        return $result instanceof ToolExecutionResult ? null : $result;
    }
}
