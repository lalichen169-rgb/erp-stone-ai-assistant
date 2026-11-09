<?php

namespace App\Support;

use App\Models\Product;

/**
 * Variety (color) names from the live product catalog — used for NLP matching, not hardcoded demo SKUs.
 */
final class ProductColorLexicon
{
    /** @var list<string>|null */
    private static ?array $cachedColors = null;

    public static function forgetCachedColors(): void
    {
        self::$cachedColors = null;
    }

    /**
     * @return list<string> longest names first so "Paris Grey" wins over "Grey" when both exist
     */
    public function distinctColors(): array
    {
        if (self::$cachedColors !== null) {
            return self::$cachedColors;
        }

        self::$cachedColors = Product::query()
            ->select('color')
            ->whereNotNull('color')
            ->where('color', '!=', '')
            ->distinct()
            ->pluck('color')
            ->map(fn (mixed $color) => $this->normalizeColorToken((string) $color))
            ->filter(fn (string $color) => $color !== '')
            ->unique(fn (string $color) => strtolower($color))
            ->sortByDesc(fn (string $color) => strlen($color))
            ->values()
            ->all();

        return self::$cachedColors;
    }

    public function matchInText(string $text): ?string
    {
        foreach ($this->distinctColors() as $color) {
            if ($this->textContainsColor($text, $color)) {
                return $color;
            }
        }

        if (preg_match('/\bParis\s+Grey\b/i', $text) === 1) {
            foreach ($this->distinctColors() as $color) {
                if (strcasecmp($color, 'Grey') === 0) {
                    return $color;
                }
            }

            return 'Grey';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function matchAllKnownInText(string $text): array
    {
        $found = [];
        foreach ($this->distinctColors() as $color) {
            if ($this->textContainsColor($text, $color)) {
                $found[] = $color;
            }
        }

        if (preg_match('/\bParis\s+Grey\b/i', $text) === 1) {
            $grey = collect($found)->first(fn (string $c) => strcasecmp($c, 'Grey') === 0);
            if ($grey === null) {
                $found[] = 'Grey';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Catalog match first; otherwise preserve an unknown variety token for ERP filtering (may yield zero rows).
     */
    public function resolveColorFilterForText(string $text): ?string
    {
        $known = $this->matchInText($text);
        if ($known !== null) {
            return $known;
        }

        return $this->extractPresumedVarietyFilter($text);
    }

    /**
     * Unknown variety mentioned in a stock-style question (not a catalog color yet).
     */
    public function extractPresumedVarietyFilter(string $text): ?string
    {
        if (preg_match('/\b(slabs?|stock|inventory|how many|available)\b/i', $text) !== 1) {
            return null;
        }

        if (preg_match('/\bfor\s+(?:all\s+)?(?:stone\s+)?variet(?:y|ies)\b/i', $text) === 1) {
            return null;
        }

        if (preg_match('/\b(?:stock|slabs?)\s+for\s+(?P<variety>[A-Za-z][A-Za-z0-9\s\-]{1,48}?)(?:\s+in\b|\s+at\b|\s+warehouse|\?|$)/iu', $text, $match) === 1) {
            $candidate = $this->normalizeColorToken($match['variety']);
            if ($candidate !== '' && ! $this->isStopWord($candidate) && ! $this->isStoneMaterialWord($candidate)) {
                return $candidate;
            }
        }

        if (preg_match('/\b(?P<variety>[A-Za-z][A-Za-z0-9]+(?:\s+[A-Za-z][A-Za-z0-9]+)?)\s+(?:slabs?|stock)\b/iu', $text, $match) === 1) {
            $candidate = $this->normalizeColorToken($match['variety']);
            if ($candidate !== '' && ! $this->isStopWord($candidate) && ! $this->isStoneMaterialWord($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function isKnownColor(string $value): bool
    {
        $needle = strtolower($this->normalizeColorToken($value));
        if ($needle === '') {
            return false;
        }

        foreach ($this->distinctColors() as $color) {
            if (strtolower($color) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function textMentionsStockContext(string $text): bool
    {
        return preg_match('/\b(slabs?|stock|inventory|how many|available)\b/i', $text) === 1
            || $this->matchInText($text) !== null
            || preg_match('/\b(quartz|marble|granite|travertine|porcelain)\b/i', $text) === 1;
    }

    private function textContainsColor(string $text, string $color): bool
    {
        return preg_match('/\b'.preg_quote($color, '/').'\b/i', $text) === 1;
    }

    private function normalizeColorToken(string $value): string
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $trimmed;
    }

    private function isStopWord(string $value): bool
    {
        static $stop = [
            'show', 'many', 'much', 'stone', 'all', 'the', 'our', 'your', 'me', 'for', 'and', 'with',
            'warehouse', 'available', 'how', 'what', 'about', 'same', 'product', 'products', 'varieties', 'variety',
        ];

        foreach (explode(' ', strtolower($value)) as $part) {
            if (in_array($part, $stop, true)) {
                return true;
            }
        }

        if (preg_match('/\b\d+(\.\d+)?\s*cm\b/i', $value) === 1) {
            return true;
        }

        return false;
    }

    private function isStoneMaterialWord(string $value): bool
    {
        return preg_match('/\b(quartz|marble|granite|travertine|porcelain|sintered)\b/i', $value) === 1;
    }
}
