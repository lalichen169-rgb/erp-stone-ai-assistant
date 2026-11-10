<?php

namespace App\Services\AI\Grounding;

final class LandedCostIntentDetector
{
    public function isLandedCostQuestion(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/\b(landed\s*cost|landed\s*unit\s*cost)\b/i', $trimmed) === 1
            && preg_match('/\b(purchase\s+order|PO\s*#?\s*\d+|\d{1,9})\b/i', $trimmed) === 1;
    }
}
