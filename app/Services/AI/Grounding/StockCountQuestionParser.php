<?php

namespace App\Services\AI\Grounding;

use App\Models\Warehouse;
use App\Support\ProductColorLexicon;

/**
 * Deterministic filter extraction for warehouse stock-count questions (ERP lookup bypass).
 */
final class StockCountQuestionParser
{
    public function __construct(
        private readonly ProductColorLexicon $colorLexicon,
    ) {}

    /**
     * @param  array<string, mixed>|null  $inheritedProductFilters
     * @return array{product: array<string, mixed>, warehouse: array<string, string>}|null
     */
    public function parse(string $question, ?array $inheritedProductFilters = null): ?array
    {
        $trimmed = trim($question);
        if ($trimmed === '') {
            return null;
        }

        $warehouseName = $this->resolveWarehouseName($trimmed);
        if ($warehouseName === null && $this->isWarehouseOnlyFollowUp($trimmed)) {
            $warehouseName = $this->resolveWarehouseFromFollowUp($trimmed);
        }

        if ($warehouseName === null) {
            return null;
        }

        if ($this->isWarehouseOnlyFollowUp($trimmed)) {
            if ($inheritedProductFilters === null || $inheritedProductFilters === []) {
                return null;
            }

            return [
                'product' => $this->normalizedProductFilters($inheritedProductFilters),
                'warehouse' => ['name' => $warehouseName],
            ];
        }

        $productFilters = $this->productFiltersFromQuestion($trimmed, defaultMaterialToQuartz: false);

        if ($inheritedProductFilters !== null) {
            $carry = $this->inheritableProductFilters($inheritedProductFilters);
            $productFilters = array_merge($carry, $productFilters);
        }

        $productFilters = $this->normalizedProductFilters($productFilters);

        if (! $this->hasMinimumProductIdentity($productFilters)) {
            if ($inheritedProductFilters !== null) {
                $carry = $this->inheritableProductFilters($inheritedProductFilters);
                $productFilters = $this->normalizedProductFilters(array_merge($carry, $productFilters));
            }
        }

        if (! $this->questionMentionsMaterial($trimmed)) {
            unset($productFilters['material']);
        }

        if (! $this->hasMinimumProductIdentity($productFilters)) {
            return null;
        }

        return [
            'product' => $productFilters,
            'warehouse' => ['name' => $warehouseName],
        ];
    }

    /**
     * @param  array<string, mixed>  $productFilters
     */
    private function hasMinimumProductIdentity(array $productFilters): bool
    {
        if (! empty($productFilters['sku'])) {
            return true;
        }

        if (! empty($productFilters['color']) && ! empty($productFilters['thickness_cm'])) {
            return true;
        }

        return false;
    }

    /**
     * Product filters for ERP stock lookup (optional default Quartz when variety is named).
     *
     * @return array<string, mixed>
     */
    public function productFiltersFromQuestion(string $question, bool $defaultMaterialToQuartz = true): array
    {
        $filters = $this->extractProductFilters(trim($question));

        if (! $defaultMaterialToQuartz && ! $this->questionMentionsMaterial($question)) {
            unset($filters['material']);
        }

        return $this->normalizedProductFilters($filters);
    }

    public function questionMentionsMaterial(string $text): bool
    {
        return preg_match('/\b(quartz|marble|granite|travertine)\b/i', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $productFilters
     * @return array<string, mixed>
     */
    private function inheritableProductFilters(array $productFilters): array
    {
        return array_intersect_key(
            $productFilters,
            array_flip(['sku', 'color', 'thickness_cm']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractProductFilters(string $trimmed): array
    {
        $productFilters = [];

        if (preg_match('/\b(\d+(?:\.\d+)?)\s*cm\b/i', $trimmed, $th) === 1) {
            $productFilters['thickness_cm'] = number_format((float) $th[1], 2, '.', '');
        }

        if (preg_match('/\bSKU\s+([A-Za-z0-9\-]+)/i', $trimmed, $sku) === 1) {
            $productFilters['sku'] = trim($sku[1]);
        }

        $matchedColors = $this->colorLexicon->matchAllKnownInText($trimmed);
        if (count($matchedColors) > 1) {
            $productFilters['colors'] = $matchedColors;
        } elseif (count($matchedColors) === 1) {
            $productFilters['color'] = $matchedColors[0];
        } else {
            $presumed = $this->colorLexicon->resolveColorFilterForText($trimmed);
            if ($presumed !== null) {
                $productFilters['color'] = $presumed;
            }
        }

        if (preg_match('/\b(quartz|marble|granite|travertine)\b/i', $trimmed, $mat) === 1) {
            $productFilters['material'] = ucfirst(strtolower($mat[1]));
        }

        return $productFilters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizedProductFilters(array $filters): array
    {
        $out = [];

        foreach (['sku', 'color', 'material', 'thickness_cm'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== null) {
                $out[$key] = is_string($filters[$key]) ? trim($filters[$key]) : $filters[$key];
            }
        }

        if (isset($out['thickness_cm'])) {
            $out['thickness_cm'] = number_format((float) $out['thickness_cm'], 2, '.', '');
        }

        return $out;
    }

    private function isWarehouseOnlyFollowUp(string $text): bool
    {
        return preg_match('/^(and\s+)?in\s+[A-Za-z][A-Za-z\s\-]+\??$/i', trim($text)) === 1;
    }

    private function resolveWarehouseFromFollowUp(string $text): ?string
    {
        if (preg_match('/^(?:and\s+)?in\s+(?P<city>[A-Za-z][A-Za-z\s\-]+)\??$/i', trim($text), $m) !== 1) {
            return null;
        }

        $city = trim($m['city']);
        if ($city === '') {
            return null;
        }

        $match = Warehouse::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($city)])
            ->first();

        return $match?->name;
    }

    private function resolveWarehouseName(string $text): ?string
    {
        /** @var list<string> $names */
        $names = Warehouse::query()->orderBy('name')->pluck('name')
            ->map(static fn ($name) => is_string($name) ? trim($name) : '')
            ->filter(static fn (string $name) => $name !== '')
            ->all();

        foreach ($names as $name) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/i', $text) === 1) {
                return $name;
            }
        }

        return null;
    }
}
