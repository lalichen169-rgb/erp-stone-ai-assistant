<?php

namespace App\Services\AI\Context;

use App\Models\Warehouse;
use App\Services\AI\Grounding\ErpCrossDomainRequestDetector;
use App\Services\AI\Grounding\LandedCostQuestionParser;
use App\Services\AI\Grounding\PurchaseOrderCreationIntentDetector;
use App\Services\AI\Grounding\PurchaseOrderSearchQuestionParser;
use App\Support\ProductColorLexicon;

final class DeterministicFollowUpResolver
{
    public function __construct(
        private readonly LandedCostQuestionParser $landedCostQuestionParser,
        private readonly ProductColorLexicon $colorLexicon,
        private readonly PurchaseOrderSearchQuestionParser $purchaseOrderSearchQuestionParser,
        private readonly PurchaseOrderCreationIntentDetector $purchaseOrderCreationIntentDetector,
        private readonly ErpCrossDomainRequestDetector $crossDomainRequestDetector,
    ) {}
    public function tryExpand(
        string $followUp,
        string $anchorQuestion,
        string $anchorAnswer,
        ?FollowUpThreadType $threadType,
    ): ?ContextResolutionResult {
        if ($threadType === FollowUpThreadType::BankDetails || $this->isBankDetailsThread($anchorQuestion, $anchorAnswer)) {
            $expanded = $this->expandBankSupplierFollowUp($followUp);
            if ($expanded !== null) {
                return $this->result($expanded, 'deterministic_bank_supplier_follow_up');
            }
        }

        if ($threadType === FollowUpThreadType::LandedCost || $this->isLandedCostThread($anchorQuestion, $anchorAnswer)) {
            $expanded = $this->expandLandedCostFollowUp($followUp, $anchorQuestion);
            if ($expanded !== null) {
                return $this->result($expanded, 'deterministic_landed_cost_follow_up');
            }
        }

        if ($threadType === FollowUpThreadType::OverduePurchaseOrders || $this->isOverdueThread($anchorQuestion, $anchorAnswer)) {
            $expanded = $this->expandOverdueSupplierFollowUp($followUp, $anchorQuestion);
            if ($expanded !== null) {
                return $this->result($expanded, 'deterministic_overdue_supplier_follow_up');
            }
        }

        if ($threadType === FollowUpThreadType::PurchaseOrderSearch || $this->isPurchaseOrderSearchThread($anchorQuestion, $anchorAnswer)) {
            $expanded = $this->expandPurchaseOrderSearchFollowUp($followUp, $anchorQuestion);
            if ($expanded !== null) {
                return $this->result($expanded, 'deterministic_po_search_follow_up');
            }
        }

        $stockExpanded = $this->expandStockFollowUp($followUp, $anchorQuestion, $anchorAnswer);
        if ($stockExpanded !== null) {
            return $stockExpanded;
        }

        $supplierExpanded = $this->expandGenericSupplierSwap($followUp, $anchorQuestion);
        if ($supplierExpanded !== null) {
            return $supplierExpanded;
        }

        $poIdExpanded = $this->expandGenericPurchaseOrderIdSwap($followUp, $anchorQuestion);
        if ($poIdExpanded !== null) {
            return $poIdExpanded;
        }

        return null;
    }

    private function result(string $resolved, string $strategy): ContextResolutionResult
    {
        return new ContextResolutionResult(
            wasAlreadyStandalone: false,
            needsClarification: false,
            resolvedStandaloneQuestion: $resolved,
            clarificationQuestion: null,
            strategy: $strategy,
        );
    }

    private function expandBankSupplierFollowUp(string $followUp): ?string
    {
        $supplier = $this->extractNamedEntityFollowUp($followUp, includePoPattern: false);
        if ($supplier === null || $supplier === '') {
            return null;
        }

        return 'What are the bank details for '.$supplier.'?';
    }

    private function expandLandedCostFollowUp(string $followUp, string $anchorQuestion): ?string
    {
        $poId = $this->landedCostQuestionParser->extractPurchaseOrderId($followUp);
        if ($poId === null) {
            return null;
        }

        return 'What is the landed cost for purchase order '.$poId.'?';
    }

    private function expandOverdueSupplierFollowUp(string $followUp, string $anchorQuestion): ?string
    {
        if ($this->crossDomainRequestDetector->isExplicitIndependentDomainRequest($followUp)) {
            return null;
        }

        if ($this->landedCostQuestionParser->extractPurchaseOrderId($followUp) !== null) {
            return null;
        }

        $supplier = $this->extractNamedEntityFollowUp($followUp, includePoPattern: false);
        if ($supplier === null || $supplier === '') {
            return null;
        }

        if (preg_match('/\bsupplier\s+(?P<name>[A-Za-z0-9\s\-&—.,\']+)[\.\?]?/iu', $anchorQuestion, $matches) === 1) {
            $resolved = preg_replace(
                '/'.preg_quote(trim($matches['name']), '/').'/iu',
                $supplier,
                $anchorQuestion,
                1,
            );
            if (is_string($resolved) && $resolved !== $anchorQuestion) {
                return $resolved;
            }
        }

        return 'Which purchase orders are overdue for '.$supplier.'?';
    }

    private function expandPurchaseOrderSearchFollowUp(string $followUp, string $anchorQuestion): ?string
    {
        if ($this->shouldBlockCrossDomainPoSearchExpansion($followUp)) {
            return null;
        }

        if (preg_match('/\b(?:only\s+)?(?:the\s+)?overdue(?:\s+ones?)?\b/i', trim($followUp)) === 1) {
            return null;
        }

        if ($this->purchaseOrderCreationIntentDetector->isPurchaseOrderCreationRequest($followUp)) {
            return null;
        }

        $poId = $this->landedCostQuestionParser->extractPurchaseOrderId($followUp);
        if ($poId !== null) {
            $replaced = preg_replace('/\bPO\s*[-#]?\s*\d+/i', 'PO-'.$poId, $anchorQuestion, 1);
            if (is_string($replaced) && $replaced !== $anchorQuestion) {
                return $replaced;
            }

            return $this->purchaseOrderSearchQuestionParser->buildCanonicalSearchQuestion(
                ['purchase_order_id' => $poId],
                'What is the status of purchase order PO-'.$poId,
            );
        }

        $status = $this->purchaseOrderSearchQuestionParser->extractStatus($followUp);
        if ($status !== null && $this->isPurchaseOrderSearchThread($anchorQuestion, '')) {
            $supplier = $this->purchaseOrderSearchQuestionParser->extractSupplierNameHint($anchorQuestion);
            $filters = ['status' => $status];
            if (is_string($supplier) && $supplier !== '') {
                $filters['supplier_name'] = $supplier;
            }

            return $this->purchaseOrderSearchQuestionParser->buildCanonicalSearchQuestion($filters, $followUp);
        }

        if (preg_match('/\b(?:only\s+)?(?:from|for)\b/i', $followUp) === 1
            && $this->isPurchaseOrderSearchThread($anchorQuestion, '')) {
            $supplierHint = $this->purchaseOrderSearchQuestionParser->extractSupplierNameHint($followUp);
            if (is_string($supplierHint) && $supplierHint !== '') {
                $statusFromAnchor = $this->purchaseOrderSearchQuestionParser->extractStatus($anchorQuestion);
                $filters = ['supplier_name' => $supplierHint];
                if ($statusFromAnchor !== null) {
                    $filters['status'] = $statusFromAnchor;
                }

                return $this->purchaseOrderSearchQuestionParser->buildCanonicalSearchQuestion(
                    $filters,
                    'List purchase orders for '.$supplierHint,
                );
            }
        }

        $supplier = $this->extractNamedEntityFollowUp($followUp, includePoPattern: false);
        if ($supplier !== null && preg_match('/supplier/i', $anchorQuestion) === 1) {
            $resolved = preg_replace(
                '/Supplier\s+[A-Za-z0-9\s\-&—.,\']+/iu',
                'Supplier '.$supplier,
                $anchorQuestion,
                1,
            );

            return is_string($resolved) ? $resolved : null;
        }

        if ($supplier !== null && $this->isPurchaseOrderSearchThread($anchorQuestion, '')) {
            $status = $this->purchaseOrderSearchQuestionParser->extractStatus($anchorQuestion);
            $filters = ['supplier_name' => $supplier];
            if ($status !== null) {
                $filters['status'] = $status;
            }

            return $this->purchaseOrderSearchQuestionParser->buildCanonicalSearchQuestion(
                $filters,
                'List purchase orders for '.$supplier,
            );
        }

        return null;
    }

    private function expandStockFollowUp(
        string $followUp,
        string $previousQuestion,
        string $previousAnswer,
    ): ?ContextResolutionResult {
        if ($this->crossDomainRequestDetector->isExplicitBroadeningInstruction($followUp)) {
            return null;
        }

        if (preg_match('/^(?:what|how)\s+about\s+(?P<variety>[A-Za-z][A-Za-z0-9\s\-]+)\s*\??$/iu', trim($followUp), $matches) === 1) {
            $variety = trim($matches['variety']);
            if ($variety !== ''
                && $this->canonicalWarehouseName($variety) === null
                && ! preg_match('/^\d+(\.\d+)?\s*cm$/i', $variety)
                && preg_match('/\b(stock|slabs?|inventory|how many)\b/i', $previousQuestion) === 1) {
                $anchorColors = $this->colorLexicon->matchAllKnownInText($previousQuestion);
                $anchorColor = $anchorColors[0] ?? $this->colorLexicon->matchInText($previousAnswer);
                if ($anchorColor === null && preg_match('/\b([A-Za-z][A-Za-z0-9]+)\s+stock\b/i', $previousQuestion, $anchorMatch) === 1) {
                    $anchorColor = trim($anchorMatch[1]);
                }
                if ($anchorColor !== null && strcasecmp($anchorColor, $variety) !== 0) {
                    $resolved = preg_replace('/\b'.preg_quote($anchorColor, '/').'\b/iu', $variety, $previousQuestion, 1);
                    if (is_string($resolved) && $resolved !== $previousQuestion) {
                        return $this->result($resolved, 'deterministic_variety_swap');
                    }

                    $warehousePattern = $this->registeredWarehouseNamePattern();
                    if ($warehousePattern !== null && preg_match('/'.$warehousePattern.'/i', $previousQuestion, $warehouseMatch) === 1) {
                        $warehouse = trim($warehouseMatch[0]);

                        return $this->result(
                            "Show me {$variety} stock in {$warehouse}.",
                            'deterministic_variety_swap',
                        );
                    }
                }
            }
        }

        if (preg_match('/^(and\s+)?in\s+(?P<city>[A-Za-z][A-Za-z\s\-]+)\??$/i', $followUp, $matches) === 1) {
            $city = $this->canonicalWarehouseName(trim($matches['city']));
            if ($city === null || $city === '') {
                return null;
            }

            $contextBlob = $previousQuestion.' '.$previousAnswer;
            $isStockThread = $this->colorLexicon->textMentionsStockContext($contextBlob);

            $warehousePattern = $this->registeredWarehouseNamePattern();
            if ($warehousePattern !== null && preg_match('/'.$warehousePattern.'/i', $previousQuestion) === 1) {
                $resolved = preg_replace('/'.$warehousePattern.'/i', $city, $previousQuestion, 1);

                return $this->result((string) $resolved, 'deterministic_city_swap');
            }

            if ($isStockThread && preg_match('/\b(\d+(\.\d+)?)\s*cm\b/i', $previousQuestion, $thickness) === 1) {
                $thicknessValue = $thickness[1];
                $color = $this->extractVarietyColorFromStockContext($contextBlob) ?? 'the same product';
                $materialSuffix = '';
                if (preg_match('/\bmarble\b/i', $previousQuestion) === 1) {
                    $materialSuffix = ' Marble';
                } elseif (preg_match('/\bquartz\b/i', $previousQuestion) === 1) {
                    $materialSuffix = ' Quartz';
                }
                $resolved = "How many {$thicknessValue} cm {$color}{$materialSuffix} slabs do we have in {$city}?";

                return $this->result($resolved, 'deterministic_city_stock_follow_up');
            }
        }

        if (preg_match('/^(what\s+about|and\s+for)\s+(?P<thickness>\d+(\.\d+)?)\s*cm\??$/i', $followUp, $matches) === 1) {
            $thickness = $matches['thickness'];
            $resolved = preg_replace('/\b\d+(\.\d+)?\s*cm\b/i', $thickness.' cm', $previousQuestion, 1);

            return $this->result((string) $resolved, 'deterministic_thickness');
        }

        return null;
    }

    private function expandGenericSupplierSwap(string $followUp, string $previousQuestion): ?ContextResolutionResult
    {
        if ($this->crossDomainRequestDetector->isExplicitIndependentDomainRequest($followUp)) {
            return null;
        }

        if (preg_match('/^(what|how)\s+about\s+(?P<supplier>.+)\??$/iu', $followUp, $matches) === 1) {
            $supplier = trim($matches['supplier']);
            if ($supplier !== '' && preg_match('/supplier/i', $previousQuestion) === 1) {
                $resolved = preg_replace(
                    '/Supplier\s+[A-Za-z0-9\s\-&—.,\']+/iu',
                    'Supplier '.$supplier,
                    $previousQuestion,
                    1,
                );

                if (is_string($resolved) && $resolved !== $previousQuestion) {
                    return $this->result($resolved, 'deterministic_supplier_swap');
                }
            }
        }

        return null;
    }

    private function expandGenericPurchaseOrderIdSwap(string $followUp, string $previousQuestion): ?ContextResolutionResult
    {
        if (! preg_match('/\b(?:purchase\s+order|PO)\s*[-#]?\s*\d+\b/i', $previousQuestion)) {
            return null;
        }

        $poId = $this->landedCostQuestionParser->extractPurchaseOrderId($followUp);
        if ($poId === null) {
            return null;
        }

        if (preg_match('/\bPO\s*[-#]?\s*\d+/i', $previousQuestion)) {
            $resolved = preg_replace('/\bPO\s*[-#]?\s*\d+/i', 'PO-'.$poId, $previousQuestion, 1);
            if (is_string($resolved) && $resolved !== $previousQuestion) {
                return $this->result($resolved, 'deterministic_po_id_swap');
            }
        }

        if (preg_match('/\bpurchase\s+order\s+\d+/i', $previousQuestion)) {
            $resolved = preg_replace('/\bpurchase\s+order\s+\d+/i', 'purchase order '.$poId, $previousQuestion, 1);
            if (is_string($resolved) && $resolved !== $previousQuestion) {
                return $this->result($resolved, 'deterministic_po_id_swap');
            }
        }

        return null;
    }

    private function shouldBlockCrossDomainPoSearchExpansion(string $followUp): bool
    {
        if (! $this->crossDomainRequestDetector->isExplicitIndependentDomainRequest($followUp)) {
            return false;
        }

        if ($this->landedCostQuestionParser->extractPurchaseOrderId($followUp) === null) {
            return true;
        }

        if (preg_match('/\bwhat\s+is\s+the\s+status\b/i', $followUp) === 1) {
            return false;
        }

        if ($this->landedCostQuestionParser->isPurchaseOrderFollowUpPhrase($followUp)) {
            return false;
        }

        return true;
    }

    private function extractNamedEntityFollowUp(string $followUp, bool $includePoPattern): ?string
    {
        $trimmed = trim($followUp);
        if ($trimmed === '') {
            return null;
        }

        if ($this->crossDomainRequestDetector->isExplicitIndependentDomainRequest($trimmed)) {
            return null;
        }

        if ($this->landedCostQuestionParser->extractPurchaseOrderId($trimmed) !== null) {
            return null;
        }

        if (preg_match('/\b(show|list|find|search|give me|what are)\b/i', $trimmed) === 1
            && preg_match('/\b(products?|suppliers?|stock|slabs?|inventory|purchase\s+orders?|warehouses?)\b/i', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/^(?:and\s+)?for\s+(?P<entity>.+?)\??$/iu', $trimmed, $matches) === 1) {
            $entity = trim($matches['entity']);
            if ($entity !== '' && ! $this->landedCostQuestionParser->isPurchaseOrderReferenceHint($entity)) {
                return $entity;
            }

            return null;
        }

        if (preg_match('/^what\s+about\s+(?P<entity>.+?)\??$/iu', $trimmed, $matches) === 1) {
            return trim($matches['entity']);
        }

        if (preg_match('/^(?:and\s+)?in\s+[A-Za-z]/i', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/\b(how many|slabs?|\d+(\.\d+)?\s*cm)\b/i', $trimmed) === 1) {
            return null;
        }

        if (strlen($trimmed) > 80 || strlen($trimmed) < 3) {
            return null;
        }

        if (preg_match('/^(?P<entity>[A-Za-z][A-Za-z0-9\s\-&—.,\']+?)\??$/u', $trimmed, $matches) === 1) {
            return trim($matches['entity']);
        }

        return null;
    }

    private function isBankDetailsThread(string $question, string $answer): bool
    {
        if (preg_match('/\bBanking details for\b/i', $answer) === 1) {
            return true;
        }

        $blob = $question.' '.$answer;

        return preg_match('/\b(bank\s*details?|wire\s*(?:details?|instructions?))\b/i', $blob) === 1
            && preg_match('/\b(supplier|vendor)\b/i', $blob) === 1;
    }

    private function isLandedCostThread(string $question, string $answer): bool
    {
        $blob = $question.' '.$answer;

        return preg_match('/\b(landed\s*cost|landed\s*unit)\b/i', $blob) === 1;
    }

    private function isOverdueThread(string $question, string $answer): bool
    {
        return preg_match('/\boverdue\b/i', $question.' '.$answer) === 1;
    }

    private function isPurchaseOrderSearchThread(string $question, string $answer): bool
    {
        $blob = $question.' '.$answer;

        return preg_match('/\bpurchase\s+orders?\b/i', $blob) === 1
            && preg_match('/\b(search|list|show|status|ordered|received|draft)\b/i', $blob) === 1;
    }

    private function extractVarietyColorFromStockContext(string $contextBlob): ?string
    {
        return $this->colorLexicon->matchInText($contextBlob);
    }

    private function canonicalWarehouseName(string $city): ?string
    {
        if ($city === '') {
            return null;
        }

        $match = Warehouse::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($city)])
            ->first();

        return $match?->name;
    }

    private function registeredWarehouseNamePattern(): ?string
    {
        $names = Warehouse::query()->orderBy('name')->pluck('name')
            ->map(static fn ($name) => is_string($name) ? trim($name) : '')
            ->filter(static fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return null;
        }

        $quoted = array_map(static fn (string $name): string => preg_quote($name, '/'), $names);

        return implode('|', $quoted);
    }
}
