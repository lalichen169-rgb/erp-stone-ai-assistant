<?php

namespace App\Services\AI\Grounding;

/**
 * Detects user questions that ask for supplier banking / wire payment instructions.
 */
final class SupplierBankDetailsIntentDetector
{
    public function isSupplierBankDetailsRequest(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if (! $this->mentionsSensitiveBanking($trimmed)) {
            return false;
        }

        return $this->mentionsSupplierPaymentContext($trimmed);
    }

    private function mentionsSensitiveBanking(string $text): bool
    {
        return preg_match(
            '/\b('
            .'bank\s*details?'
            .'|bank\s*account(?:\s*details?)?'
            .'|wire\s*(?:details?|instructions?|info(?:rmation)?)'
            .'|routing\s*(?:number|#)?'
            .'|iban'
            .'|swift(?:\s*code)?'
            .'|account\s*number'
            .')\b/i',
            $text,
        ) === 1;
    }

    private function mentionsSupplierPaymentContext(string $text): bool
    {
        if (preg_match('/\b(supplier|suppliers|vendor|vendors)\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\bbank\s*details?\s+for\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\bwire\s+(?:details?|instructions?)\s+for\b/i', $text) === 1) {
            return true;
        }

        return preg_match('/\b(pay(?:ment)?\s+to|remit(?:tance)?\s+to)\b/i', $text) === 1;
    }
}
