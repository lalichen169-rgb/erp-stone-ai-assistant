<?php

namespace Tests\Feature\AI\Grounding;

use App\Services\AI\Context\DeterministicFollowUpResolver;
use App\Services\AI\Context\FollowUpThreadType;
use App\Services\AI\Grounding\ErpQuestionStandaloneHeuristic;
use App\Services\AI\Grounding\LandedCostQuestionParser;
use App\Services\AI\Grounding\PurchaseOrderSearchQuestionParser;
use Tests\TestCase;

/**
 * Phase 16.12 reproduction probes (no DB).
 */
class PoStatusStandaloneProbeTest extends TestCase
{
    public function test_po_id_extracted_from_status_question(): void
    {
        $parser = app(LandedCostQuestionParser::class);
        $q = 'What is the status of purchase order PO-507';

        $this->assertSame(507, $parser->extractPurchaseOrderId($q));
    }

    public function test_standalone_heuristic_marks_po_status_question_actionable(): void
    {
        $h = app(ErpQuestionStandaloneHeuristic::class);
        $q = 'What is the status of purchase order PO-507';

        $this->assertTrue($h->isActionableStandalone($q), 'Explicit PO ID status questions must not inherit prior thread context.');
    }

    public function test_follow_up_expansion_bug_when_anchor_has_no_po_number(): void
    {
        $resolver = app(DeterministicFollowUpResolver::class);
        $anchor = 'Show me all the purchase orders for Brazos Marble LLC';

        $result = $resolver->tryExpand(
            'What is the status of purchase order PO-507',
            $anchor,
            'Purchase orders (3):',
            FollowUpThreadType::PurchaseOrderSearch,
        );

        $this->assertNotNull($result);
        $resolved = (string) $result->resolvedStandaloneQuestion;
        $this->assertStringNotContainsString('Brazos Marble LLC', $resolved);
        $this->assertStringContainsString('507', $resolved);
    }
}
