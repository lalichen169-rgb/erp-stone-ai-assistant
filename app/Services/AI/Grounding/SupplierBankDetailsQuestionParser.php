<?php

namespace App\Services\AI\Grounding;

/**
 * Extracts a supplier name hint from natural-language bank-detail questions.
 */
final class SupplierBankDetailsQuestionParser
{
    public function extractSupplierNameHint(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        $patterns = [
            '/\bsupplier\s+bank\s*details?\s+(?:for|of)\s+(.+?)\??$/iu',
            '/\bbank\s*details?\s+(?:for|of)\s+(?:supplier\s+|the\s+supplier\s+)?(.+?)\??$/iu',
            '/\bbank\s*details?\b.*\bfor\s+(.+?)\??$/isu',
            '/\bbank\s*details?\b.*\bof\s+(.+?)\??$/isu',
            '/\bwire\s*(?:details?|instructions?)\s+(?:for|of)\s+(.+?)\??$/iu',
            '/\bbank\s*account\s*details?\s+(?:for|of)\s+(.+?)\??$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $trimmed, $matches) === 1) {
                $hint = trim($matches[1]);
                if ($hint !== '') {
                    return $hint;
                }
            }
        }

        return null;
    }
}
