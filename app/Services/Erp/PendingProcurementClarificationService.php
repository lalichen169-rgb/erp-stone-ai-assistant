<?php

namespace App\Services\Erp;

use App\Enums\PendingProcurementClarificationType;
use App\Models\Conversation;
use App\Models\PendingProcurementClarification;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AI\Grounding\DraftPurchaseOrderMessageParser;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PendingProcurementClarificationService
{
    public function __construct(
        private readonly DraftPurchaseOrderMessageParser $draftPurchaseOrderMessageParser,
        private readonly WarehouseLookupService $warehouseLookupService,
        private readonly DraftPurchaseOrderService $draftPurchaseOrderService,
    ) {}

    public function activeFor(User $user, Conversation $conversation): ?PendingProcurementClarification
    {
        $row = PendingProcurementClarification::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->isExpired()) {
            $row->delete();

            return null;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $draftInput
     */
    public function beginSupplierSelection(
        User $user,
        Conversation $conversation,
        array $draftInput,
        int $productId,
        int $quantity,
        string $originalUserMessage,
    ): PendingProcurementClarification {
        $ttlMinutes = (int) config('erp.procurement.pending_clarification_ttl_minutes', 30);
        $expiresAt = Carbon::now()->addMinutes(max($ttlMinutes, 1));

        $payload = [
            'draft_input' => $draftInput,
            'product_id' => $productId,
            'quantity' => $quantity,
            'original_user_message' => $originalUserMessage,
            'awaiting' => 'supplier',
        ];

        return PendingProcurementClarification::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'user_id' => $user->id,
                'type' => PendingProcurementClarificationType::SupplierSelection,
                'payload' => $payload,
                'expires_at' => $expiresAt,
            ],
        );
    }

    public function beginWarehouseDestination(
        User $user,
        Conversation $conversation,
        array $draftInput,
        string $originalUserMessage,
    ): PendingProcurementClarification {
        $ttlMinutes = (int) config('erp.procurement.pending_clarification_ttl_minutes', 30);
        $expiresAt = Carbon::now()->addMinutes(max($ttlMinutes, 1));

        $payload = [
            'draft_input' => $draftInput,
            'product_id' => (int) ($draftInput['lines'][0]['product_id'] ?? 0),
            'supplier_id' => (int) ($draftInput['supplier_id'] ?? 0),
            'quantity' => (int) ($draftInput['lines'][0]['quantity'] ?? 0),
            'original_user_message' => $originalUserMessage,
        ];

        return PendingProcurementClarification::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'user_id' => $user->id,
                'type' => PendingProcurementClarificationType::WarehouseDestination,
                'payload' => $payload,
                'expires_at' => $expiresAt,
            ],
        );
    }

    public function clear(PendingProcurementClarification $row): void
    {
        $row->delete();
    }

    /**
     * @return array{
     *     status: string,
     *     clarification_question?: string,
     *     proposal?: array<string, mixed>,
     *     message?: string
     * }
     */
    public function tryResumeWarehouseDestination(
        User $user,
        Conversation $conversation,
        string $replyText,
        string $conversationKey,
        string $idempotencyKey,
    ): array {
        return $this->tryResumePendingProcurement($user, $conversation, $replyText, $conversationKey, $idempotencyKey);
    }

    /**
     * @return array{
     *     status: string,
     *     clarification_question?: string,
     *     proposal?: array<string, mixed>,
     *     message?: string
     * }
     */
    public function tryResumePendingProcurement(
        User $user,
        Conversation $conversation,
        string $replyText,
        string $conversationKey,
        string $idempotencyKey,
    ): array {
        if ($this->draftPurchaseOrderMessageParser->looksLikeUnrelatedStandaloneQuestion($replyText)) {
            return ['status' => 'no_pending'];
        }

        return DB::transaction(function () use ($user, $conversation, $replyText, $conversationKey, $idempotencyKey): array {
            $pending = PendingProcurementClarification::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($pending === null) {
                return ['status' => 'no_pending'];
            }

            if ($pending->isExpired()) {
                $pending->delete();

                if ($this->replyLooksLikeWarehouseSelection($user, $replyText)
                    || $this->replyLooksLikeSupplierSelection($replyText)) {
                    return [
                        'status' => 'expired',
                        'message' => 'The pending purchase order request expired. Please send the full draft purchase order request again.',
                    ];
                }

                return ['status' => 'no_pending'];
            }

            if ($pending->type === PendingProcurementClarificationType::SupplierSelection) {
                return $this->resumeLockedSupplierSelection($user, $conversation, $pending, $replyText, $conversationKey, $idempotencyKey);
            }

            if ($pending->type === PendingProcurementClarificationType::WarehouseDestination) {
                return $this->resumeLockedPending($user, $conversation, $pending, $replyText, $conversationKey, $idempotencyKey);
            }

            return ['status' => 'no_pending'];
        });
    }

    private function replyLooksLikeSupplierSelection(string $replyText): bool
    {
        $trimmed = trim($replyText);
        if ($trimmed === '') {
            return false;
        }

        return ! preg_match('/\b(create|purchase\s+order|draft\s+po)\b/i', $trimmed);
    }

    private function replyLooksLikeWarehouseSelection(User $user, string $replyText): bool
    {
        $trimmed = trim($replyText);
        if ($trimmed === '') {
            return false;
        }

        if ($this->draftPurchaseOrderMessageParser->extractDraftIntent($user, $trimmed) !== null) {
            return false;
        }

        $hint = $this->draftPurchaseOrderMessageParser->extractWarehouseNameHint($trimmed);

        return $hint !== null && $hint !== '' && ! preg_match('/\b(create|purchase\s+order|draft\s+po)\b/i', $trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function resumeLockedSupplierSelection(
        User $user,
        Conversation $conversation,
        PendingProcurementClarification $pending,
        string $replyText,
        string $conversationKey,
        string $idempotencyKey,
    ): array {
        $draftInput = $pending->payload['draft_input'] ?? null;
        if (! is_array($draftInput)) {
            $this->clear($pending);

            return ['status' => 'corrupt_pending'];
        }

        $productId = (int) ($pending->payload['product_id'] ?? 0);
        $replacementIntent = $this->draftPurchaseOrderMessageParser->extractDraftIntent($user, $replyText);
        if ($replacementIntent !== null) {
            $newProductId = (int) ($replacementIntent['lines'][0]['product_id'] ?? 0);
            if ($newProductId > 0 && $newProductId !== $productId) {
                $this->beginSupplierSelection(
                    $user,
                    $conversation,
                    $replacementIntent,
                    $newProductId,
                    (int) ($replacementIntent['lines'][0]['quantity'] ?? 0),
                    $replyText,
                );

                return [
                    'status' => 'needs_clarification',
                    'clarification_question' => $this->buildSupplierChoiceQuestion(
                        $user,
                        $newProductId,
                        (int) ($replacementIntent['lines'][0]['quantity'] ?? 0),
                    ),
                ];
            }
        }

        $supplier = $this->draftPurchaseOrderMessageParser->resolveSupplierByFreeTextHint($replyText);
        if ($supplier === null) {
            return [
                'status' => 'needs_clarification',
                'clarification_question' => 'That supplier was not found. '.$this->buildSupplierChoiceQuestion(
                    $user,
                    $productId,
                    (int) ($pending->payload['quantity'] ?? 0),
                ),
            ];
        }

        $lineProductId = (int) ($draftInput['lines'][0]['product_id'] ?? 0);
        $draftInput['supplier_id'] = $supplier->id;
        $draftInput['lines'][0]['unit_cost'] = $this->draftPurchaseOrderMessageParser->defaultUnitCostForLine(
            $supplier->id,
            $lineProductId,
        );

        if (! empty($draftInput['lines'][0]['warehouse_id'])) {
            try {
                $proposal = $this->draftPurchaseOrderService->createProposal(
                    $user,
                    $conversationKey,
                    $idempotencyKey,
                    $draftInput,
                );
            } catch (PurchaseOrderProposalException $exception) {
                return [
                    'status' => 'proposal_failed',
                    'message' => $exception->getMessage(),
                ];
            }

            $this->clear($pending);

            return [
                'status' => 'proposal_created',
                'proposal' => $proposal,
                'message' => $this->formatProposalCreatedMessage($proposal),
            ];
        }

        $this->beginWarehouseDestination($user, $conversation, $draftInput, (string) ($pending->payload['original_user_message'] ?? $replyText));

        return [
            'status' => 'needs_clarification',
            'clarification_question' => $this->buildContextualWarehouseChoiceQuestion(
                $user,
                $lineProductId,
                (int) ($draftInput['lines'][0]['quantity'] ?? 0),
            ),
        ];
    }

    private function resumeLockedPending(
        User $user,
        Conversation $conversation,
        PendingProcurementClarification $pending,
        string $replyText,
        string $conversationKey,
        string $idempotencyKey,
    ): array {

        $parsed = $this->draftPurchaseOrderMessageParser->parseWarehouseClarificationReply($replyText);

        $draftInput = $pending->payload['draft_input'] ?? null;
        if (! is_array($draftInput)) {
            $this->clear($pending);

            return ['status' => 'corrupt_pending'];
        }

        $replacementIntent = $this->draftPurchaseOrderMessageParser->extractDraftIntent($user, $replyText);
        if ($replacementIntent !== null) {
            $newProductId = (int) ($replacementIntent['lines'][0]['product_id'] ?? 0);
            $priorProductId = (int) ($pending->payload['product_id'] ?? 0);
            if ($newProductId > 0 && $newProductId !== $priorProductId) {
                $this->beginWarehouseDestination($user, $conversation, $replacementIntent, $replyText);

                return [
                    'status' => 'needs_clarification',
                    'clarification_question' => $this->buildWarehouseChoiceQuestion($user),
                ];
            }
        }

        if ($parsed['quantity'] !== null) {
            $draftInput['lines'][0]['quantity'] = $parsed['quantity'];
        }

        $hint = $parsed['warehouse_name_hint'] ?? trim($replyText);
        if ($hint === null || trim((string) $hint) === '') {
            return [
                'status' => 'needs_clarification',
                'clarification_question' => $this->buildWarehouseChoiceQuestion($user),
            ];
        }

        $resolution = $this->warehouseLookupService->resolve($user, ['name' => trim((string) $hint)]);
        $status = (string) ($resolution['status'] ?? '');

        if ($status === 'ambiguous_warehouse') {
            $names = collect($resolution['alternatives'] ?? [])
                ->pluck('name')
                ->filter()
                ->take(5)
                ->implode(', ');

            return [
                'status' => 'needs_clarification',
                'clarification_question' => $names !== ''
                    ? 'Multiple warehouses matched. Please choose one: '.$names.'.'
                    : $this->buildWarehouseChoiceQuestion($user),
            ];
        }

        if ($status !== 'unique_match' || empty($resolution['warehouse_id'])) {
            return [
                'status' => 'needs_clarification',
                'clarification_question' => 'That warehouse was not found. '.$this->buildWarehouseChoiceQuestion($user),
            ];
        }

        $warehouseId = (int) $resolution['warehouse_id'];
        $draftInput['lines'][0]['warehouse_id'] = $warehouseId;

        try {
            $proposal = $this->draftPurchaseOrderService->createProposal(
                $user,
                $conversationKey,
                $idempotencyKey,
                $draftInput,
            );
        } catch (PurchaseOrderProposalException $exception) {
            return [
                'status' => 'proposal_failed',
                'message' => $exception->getMessage(),
            ];
        }

        $this->clear($pending);

        return [
            'status' => 'proposal_created',
            'proposal' => $proposal,
            'message' => $this->formatProposalCreatedMessage($proposal),
        ];
    }

    /**
     * @param  array<string, mixed>  $draftInput
     * @return array{
     *     status: string,
     *     proposal?: array<string, mixed>,
     *     message?: string
     * }
     */
    public function tryCreateProposalFromDraftInput(
        User $user,
        Conversation $conversation,
        array $draftInput,
        string $conversationKey,
        string $idempotencyKey,
    ): array {
        if (empty($draftInput['lines'][0]['warehouse_id'])) {
            return ['status' => 'incomplete_draft'];
        }

        try {
            $proposal = $this->draftPurchaseOrderService->createProposal(
                $user,
                $conversationKey,
                $idempotencyKey,
                $draftInput,
            );
        } catch (PurchaseOrderProposalException $exception) {
            return [
                'status' => 'proposal_failed',
                'message' => $exception->getMessage(),
            ];
        }

        PendingProcurementClarification::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->delete();

        return [
            'status' => 'proposal_created',
            'proposal' => $proposal,
            'message' => $this->formatProposalCreatedMessage($proposal),
        ];
    }

    public function buildWarehouseChoiceQuestion(User $user): string
    {
        $warehouses = $this->warehouseLookupService->listReceivingWarehouses($user, 25);
        $labels = collect($warehouses)->pluck('name')->filter()->values()->all();

        if ($labels === []) {
            return 'Which warehouse should receive these slabs? No warehouses are configured in the catalogue.';
        }

        return 'Which warehouse should receive these slabs: '.implode(', ', $labels).'?';
    }

    public function buildContextualWarehouseChoiceQuestion(User $user, int $productId, int $quantity): string
    {
        $context = $this->draftPurchaseOrderMessageParser->describeProductLineForClarification($productId, $quantity);
        $base = $this->buildWarehouseChoiceQuestion($user);

        return 'For '.$context.', '.$base;
    }

    public function buildSupplierChoiceQuestion(User $user, int $productId, int $quantity): string
    {
        $context = $this->draftPurchaseOrderMessageParser->describeProductLineForClarification($productId, $quantity);
        $names = Supplier::query()->orderBy('name')->limit(25)->pluck('name')
            ->map(static fn ($name) => is_string($name) ? trim($name) : '')
            ->filter(static fn (string $name) => $name !== '')
            ->values()
            ->all();

        if ($names === []) {
            return 'For '.$context.', which supplier should supply this draft purchase order? No suppliers are configured in the catalogue.';
        }

        return 'For '.$context.', which supplier should supply this draft purchase order: '.implode(', ', $names).'?';
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    public function formatProposalCreatedMessage(array $proposal): string
    {
        $proposalId = (string) ($proposal['proposal_id'] ?? '');
        $lines = $proposal['lines'] ?? [];
        $first = is_array($lines) && isset($lines[0]) && is_array($lines[0]) ? $lines[0] : [];
        $qty = $first['quantity'] ?? '?';
        $dest = $first['warehouse_name'] ?? 'the selected warehouse';

        return 'A pending draft purchase-order proposal was created for '.$qty.' slabs to '.$dest
            .'. Pending proposal id: '.$proposalId.' — review the preview and confirm via the app when ready.';
    }
}
