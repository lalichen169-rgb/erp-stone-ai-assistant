<?php

namespace App\Services\AI\Context;

use App\Support\ProductColorLexicon;

final class ConversationThreadClassifier
{
    public function __construct(
        private readonly ProductColorLexicon $colorLexicon,
    ) {}

    public function classify(string $question, string $answer): ?FollowUpThreadType
    {
        $blob = $question.' '.$answer;

        if (preg_match('/\bBanking details for\b/i', $answer) === 1
            || (preg_match('/\b(bank\s*details?|wire\s*(?:details?|instructions?))\b/i', $blob) === 1
                && preg_match('/\b(supplier|vendor)\b/i', $blob) === 1)) {
            return FollowUpThreadType::BankDetails;
        }

        if (preg_match('/\b(landed\s*cost|landed\s*unit)\b/i', $blob) === 1
            || (preg_match('/\bpurchase\s+order\b/i', $question) === 1 && preg_match('/\bPO\s*#?\s*\d+/i', $blob) === 1)) {
            return FollowUpThreadType::LandedCost;
        }

        if (preg_match('/\boverdue\b/i', $blob) === 1) {
            return FollowUpThreadType::OverduePurchaseOrders;
        }

        if (preg_match('/\b(search|list|show|find)\b.*\bpurchase\s+orders?\b/i', $question) === 1
            || preg_match('/\bpurchase\s+orders?\b.*\b(status|ordered|received|draft)\b/i', $blob) === 1) {
            return FollowUpThreadType::PurchaseOrderSearch;
        }

        if ($this->colorLexicon->textMentionsStockContext($blob)) {
            return FollowUpThreadType::Stock;
        }

        if (preg_match('/\bsupplier\b/i', $question) === 1) {
            return FollowUpThreadType::SupplierOriented;
        }

        return null;
    }
}
