<?php

namespace App\Services\AI\Grounding;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Erp\PendingProcurementClarificationService;

/**
 * Deterministic Purchasing workflow for missing PO line warehouse destination.
 */
final class PurchasingWarehouseDestinationClarification
{
    public function __construct(
        private readonly DraftPurchaseOrderMessageParser $parser,
        private readonly PendingProcurementClarificationService $clarificationService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('ai.assistant.enforce_procurement_warehouse_clarification', true)
            && (bool) config('erp.procurement.require_line_destination_warehouse', true);
    }

    public function tryResume(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $rawMessage,
        ?string $clientIdempotencyKey,
    ): ?ProcurementClarificationTurnOutcome {
        if (! $this->isEnabled() || ! $user->isPurchasing()) {
            return null;
        }

        $conversationKey = $conversation->getProposalConversationKey();
        $idempotencyKey = $userMessage->turn_idempotency_key
            ?? ($clientIdempotencyKey !== null && trim($clientIdempotencyKey) !== '' ? trim($clientIdempotencyKey) : null);

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        $outcome = $this->clarificationService->tryResumeWarehouseDestination(
            $user,
            $conversation,
            $rawMessage,
            $conversationKey,
            $idempotencyKey,
        );

        $status = $outcome['status'] ?? 'no_pending';
        if ($status === 'no_pending') {
            return null;
        }

        if ($status === 'expired') {
            return new ProcurementClarificationTurnOutcome(
                assistantText: (string) ($outcome['message'] ?? 'The pending purchase order request expired.'),
                clarificationRequired: false,
                clarificationQuestion: null,
                assistantMetadata: [
                    'procurement_clarification' => 'expired',
                ],
            );
        }

        if ($status === 'needs_clarification') {
            $question = (string) ($outcome['clarification_question'] ?? 'Which warehouse should receive these slabs?');

            return new ProcurementClarificationTurnOutcome(
                assistantText: $question,
                clarificationRequired: true,
                clarificationQuestion: $question,
                assistantMetadata: [
                    'clarification' => true,
                    'procurement_clarification' => 'warehouse_destination',
                ],
            );
        }

        if ($status === 'proposal_failed') {
            return new ProcurementClarificationTurnOutcome(
                assistantText: (string) ($outcome['message'] ?? 'The proposal could not be created.'),
                clarificationRequired: false,
                clarificationQuestion: null,
                assistantMetadata: [
                    'procurement_clarification' => 'proposal_failed',
                ],
            );
        }

        if ($status === 'proposal_created') {
            $proposal = $outcome['proposal'] ?? [];
            $text = (string) ($outcome['message'] ?? 'Proposal created.');

            return new ProcurementClarificationTurnOutcome(
                assistantText: $text,
                clarificationRequired: false,
                clarificationQuestion: null,
                assistantMetadata: [
                    'deterministic' => true,
                    'procurement_clarification' => 'warehouse_resumed',
                ],
                proposal: is_array($proposal) ? $proposal : null,
            );
        }

        return null;
    }

    public function tryBegin(
        User $user,
        Conversation $conversation,
        string $rawMessage,
        string $resolvedForModel,
    ): ?ProcurementClarificationTurnOutcome {
        if (! $this->isEnabled() || ! $user->isPurchasing()) {
            return null;
        }

        $draft = $this->parser->extractDraftIntent($user, $resolvedForModel);
        if ($draft === null && $rawMessage !== $resolvedForModel) {
            $draft = $this->parser->extractDraftIntent($user, $rawMessage);
        }

        if ($draft === null) {
            return null;
        }

        $this->clarificationService->beginWarehouseDestination(
            $user,
            $conversation,
            $draft,
            $rawMessage,
        );

        $question = $this->clarificationService->buildWarehouseChoiceQuestion($user);

        return new ProcurementClarificationTurnOutcome(
            assistantText: $question,
            clarificationRequired: true,
            clarificationQuestion: $question,
            assistantMetadata: [
                'clarification' => true,
                'procurement_clarification' => 'warehouse_destination',
            ],
        );
    }
}
