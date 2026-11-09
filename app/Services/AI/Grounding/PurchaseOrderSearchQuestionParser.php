<?php

namespace App\Services\AI\Grounding;

use App\Enums\PurchaseOrderStatus;
use App\Models\Supplier;

/**
 * Natural-language filters for purchase order search (ERP-backed, no hardcoded suppliers).
 */
final class PurchaseOrderSearchQuestionParser
{
    public function __construct(
        private readonly LandedCostQuestionParser $landedCostQuestionParser,
        private readonly LandedCostIntentDetector $landedCostIntent,
    ) {}

    public function isPurchaseOrderSearchIntent(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->landedCostIntent->isLandedCostQuestion($trimmed)) {
            return false;
        }

        if ($this->landedCostQuestionParser->extractPurchaseOrderId($trimmed) !== null) {
            return true;
        }

        return preg_match('/\b(find|list|show|search)\b.*\bpurchase\s+orders?\b/i', $trimmed) === 1
            || preg_match('/\bpurchase\s+orders?\b.*\b(status|ordered|received|draft|cancelled)\b/i', $trimmed) === 1
            || preg_match('/\bwhat\s+is\s+the\s+status\b.*\bpurchase\s+order\b/i', $trimmed) === 1;
    }

    /**
     * @return array{status?: string, supplier_name?: string, purchase_order_id?: int}
     */
    public function extractSearchFilters(string $text): array
    {
        $filters = [];

        $poId = $this->landedCostQuestionParser->extractPurchaseOrderId($text);
        if ($poId !== null) {
            $filters['purchase_order_id'] = $poId;
        }

        $status = $this->extractStatus($text);
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $supplier = $this->extractSupplierNameHint($text);
        if ($supplier !== null) {
            $filters['supplier_name'] = $supplier;
        }

        return $filters;
    }

    public function extractStatus(string $text): ?string
    {
        foreach (PurchaseOrderStatus::cases() as $status) {
            if (preg_match('/\b'.preg_quote($status->value, '/').'\b/i', $text) === 1) {
                return $status->value;
            }
        }

        return null;
    }

    public function extractSupplierNameHint(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match(
            '/\b(?:for|from)\s+(?:the\s+)?(?:supplier\s+)?(?P<hint>[A-Za-z0-9][A-Za-z0-9\s\-&—.,\']*?)(?:\?|$)/iu',
            $trimmed,
            $matches,
        ) === 1) {
            $hint = trim($matches['hint']);
            if ($hint !== '' && ! $this->landedCostQuestionParser->isPurchaseOrderReferenceHint($hint)) {
                return $hint;
            }
        }

        $needle = strtolower($trimmed);

        /** @var list<string> $names */
        $names = Supplier::query()
            ->orderByRaw('CHAR_LENGTH(name) DESC')
            ->pluck('name')
            ->map(fn (mixed $name) => is_string($name) ? trim($name) : '')
            ->filter(fn (string $name) => $name !== '')
            ->values()
            ->all();

        foreach ($names as $name) {
            if (str_contains($needle, strtolower($name))) {
                return $name;
            }

            $short = preg_replace('/^demo supplier\s*[—\-]\s*/iu', '', $name);
            if (is_string($short) && $short !== '' && str_contains($needle, strtolower($short))) {
                return $short;
            }
        }

        return null;
    }

    public function buildCanonicalSearchQuestion(array $filters, string $fallbackText): string
    {
        if (isset($filters['purchase_order_id'])) {
            return 'Show purchase order '.$filters['purchase_order_id'];
        }

        $status = isset($filters['status']) ? $filters['status'].' ' : '';
        $supplier = $filters['supplier_name'] ?? null;
        if (is_string($supplier) && $supplier !== '') {
            return 'List '.$status.'purchase orders for '.$supplier;
        }

        if ($status !== '') {
            return 'List '.$status.'purchase orders';
        }

        return trim($fallbackText) !== '' ? trim($fallbackText) : 'List purchase orders';
    }
}
