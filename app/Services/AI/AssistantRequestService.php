<?php

namespace App\Services\AI;

use App\DTOs\AI\AiToolCallingOutcome;
use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Enums\ConversationMessageRole;
use App\Enums\ConversationStatus;
use App\Enums\AssistantRunStatus;
use App\Models\AssistantRun;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Throwable;
use App\Services\AI\Context\ContextResolutionResult;
use App\Services\AI\Context\ConversationContextManager;
use App\Services\AI\Context\FollowUpQuestionResolver;
use App\Services\AI\Grounding\ProcurementClarificationTurnOutcome;
use App\Services\AI\Grounding\PurchasingBankDetailsReply;
use App\Services\AI\Grounding\PurchasingWarehouseDestinationClarification;
use App\Services\AI\Grounding\ErpDeterministicPreflight;
use App\Services\AI\Grounding\PurchasingLandedCostReply;
use App\Services\AI\Grounding\SalesBankDetailsRefusal;
use App\Services\AI\Grounding\SalesPurchaseOrderCreationRefusal;
use App\Services\AI\Grounding\SalesSupplierOverduePurchaseOrderAnalysisRefusal;
use App\Services\AI\Grounding\OverduePurchaseOrderSearchReply;
use App\Services\AI\Grounding\BroadProductAvailabilityReply;
use App\Services\AI\Grounding\ErpCrossDomainRequestDetector;
use App\Services\AI\Grounding\StockAcrossWarehousesReply;
use App\Services\AI\Grounding\PurchaseOrderSearchReply;
use App\Services\AI\Grounding\StockWarehouseCountReply;
use App\Services\AI\Grounding\StockCountIntentDetector;
use App\Services\AI\Grounding\StockThreadProductContextExtractor;
use App\Services\AI\Conversations\ConversationAccessException;
use App\Services\AI\Conversations\ConversationService;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Exceptions\AiToolLoopException;
use App\Services\AI\Exceptions\AssistantIdempotencyException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AssistantRequestService
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly FollowUpQuestionResolver $followUpResolver,
        private readonly ConversationContextManager $contextManager,
        private readonly AiToolCallingService $toolCallingService,
        private readonly AssistantSystemInstructionBuilder $systemInstructionBuilder,
        private readonly AssistantRunCoordinator $runCoordinator,
        private readonly StockCountIntentDetector $stockCountIntentDetector,
        private readonly StockThreadProductContextExtractor $stockThreadProductContext,
        private readonly SalesBankDetailsRefusal $salesBankDetailsRefusal,
        private readonly SalesPurchaseOrderCreationRefusal $salesPurchaseOrderCreationRefusal,
        private readonly SalesSupplierOverduePurchaseOrderAnalysisRefusal $salesSupplierOverdueAnalysisRefusal,
        private readonly OverduePurchaseOrderSearchReply $overduePurchaseOrderSearchReply,
        private readonly PurchasingBankDetailsReply $purchasingBankDetailsReply,
        private readonly PurchasingLandedCostReply $purchasingLandedCostReply,
        private readonly ErpDeterministicPreflight $erpDeterministicPreflight,
        private readonly StockAcrossWarehousesReply $stockAcrossWarehousesReply,
        private readonly StockWarehouseCountReply $stockWarehouseCountReply,
        private readonly PurchaseOrderSearchReply $purchaseOrderSearchReply,
        private readonly PurchasingWarehouseDestinationClarification $purchasingWarehouseDestinationClarification,
        private readonly BroadProductAvailabilityReply $broadProductAvailabilityReply,
        private readonly ErpCrossDomainRequestDetector $crossDomainRequestDetector,
    ) {}

    /**
     * @param  non-empty-string  $message  Trimmed user message
     */
    public function handle(User $user, ?string $conversationPublicId, string $message, ?string $clientIdempotencyKey = null): AssistantRequestResult
    {
        if ($clientIdempotencyKey !== null) {
            return $this->handleWithClientIdempotency($user, $conversationPublicId, $message, $clientIdempotencyKey);
        }

        return $this->handleWithoutClientIdempotency($user, $conversationPublicId, $message);
    }

    private function handleWithoutClientIdempotency(User $user, ?string $conversationPublicId, string $message): AssistantRequestResult
    {
        $conversation = $this->resolveConversation($user, $conversationPublicId);

        $this->assertConversationActive($conversation);

        $userMessage = DB::transaction(function () use ($user, $conversation, $message): ConversationMessage {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            return $this->conversationService->appendUserMessage($user, $conversation, $message);
        });

        return $this->orchestrateTurnAfterUserMessagePersisted($user, $conversation->fresh(), $userMessage, $message, null);
    }

    /**
     * Idempotency-Key header: requires conversation_id; holds a cache lock for the whole turn (including AI).
     */
    private function handleWithClientIdempotency(User $user, ?string $conversationPublicId, string $message, string $clientIdempotencyKey): AssistantRequestResult
    {
        if ($conversationPublicId === null || trim($conversationPublicId) === '') {
            throw new \InvalidArgumentException('conversation_id is required when using Idempotency-Key.');
        }

        $lockSeconds = max(30, (int) config('ai.assistant.idempotency_lock_seconds', 120));
        $lock = Cache::lock('assistant:idem:'.$user->id.':'.$clientIdempotencyKey, $lockSeconds);

        if (! $lock->block($lockSeconds)) {
            throw new AssistantIdempotencyException(
                'Could not acquire idempotency lock.',
                AssistantIdempotencyException::CODE_IN_PROGRESS,
            );
        }

        try {
            /** @var array{replay?: AssistantRequestResult, userMessage?: ConversationMessage, conversation?: Conversation} $tx */
            $tx = DB::transaction(function () use ($user, $conversationPublicId, $message, $clientIdempotencyKey): array {
                return $this->loadOrCreateIdempotentUserRow($user, $conversationPublicId, $message, $clientIdempotencyKey);
            });

            if (isset($tx['replay'])) {
                return $tx['replay'];
            }

            $pendingAsync = (bool) ($tx['pendingAsync'] ?? false);

            $result = $this->orchestrateTurnAfterUserMessagePersisted(
                $user,
                $tx['conversation']->fresh(),
                $tx['userMessage']->fresh(),
                $message,
                $clientIdempotencyKey,
                $pendingAsync,
            );

            if ($result->httpStatus === 200) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $tx['userMessage']->fresh(), $result);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{replay?: AssistantRequestResult, userMessage?: ConversationMessage, conversation?: Conversation}
     */
    private function loadOrCreateIdempotentUserRow(
        User $user,
        string $conversationPublicId,
        string $message,
        string $clientIdempotencyKey,
    ): array {
        $conversation = $this->conversationService->getOwnedByPublicId($user, $conversationPublicId);
        $this->assertConversationActive($conversation);

        Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

        $existing = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('client_idempotency_key', $clientIdempotencyKey)
            ->where('role', ConversationMessageRole::User)
            ->first();

        if ($existing !== null) {
            if ($existing->content !== $message) {
                throw new AssistantIdempotencyException(
                    'Idempotency-Key was already used with a different message.',
                    AssistantIdempotencyException::CODE_BODY_MISMATCH,
                );
            }

            $stored = $existing->idempotency_response;
            if (is_array($stored) && $stored !== []) {
                return ['replay' => AssistantRequestResult::fromStorageArray($stored)];
            }

            if ($this->usesAsyncAssistant()) {
                return [
                    'userMessage' => $existing,
                    'conversation' => $conversation,
                    'pendingAsync' => true,
                ];
            }

            throw new AssistantIdempotencyException(
                'A previous request with this Idempotency-Key did not finish storing a response.',
                AssistantIdempotencyException::CODE_IN_PROGRESS,
            );
        }

        try {
            $userMessage = $this->conversationService->appendUserMessage(
                $user,
                $conversation,
                $message,
                clientIdempotencyKey: $clientIdempotencyKey,
            );
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000' && ! str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }

            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_idempotency_key', $clientIdempotencyKey)
                ->where('role', ConversationMessageRole::User)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            if ($existing->content !== $message) {
                throw new AssistantIdempotencyException(
                    'Idempotency-Key was already used with a different message.',
                    AssistantIdempotencyException::CODE_BODY_MISMATCH,
                );
            }

            $stored = $existing->idempotency_response;
            if (is_array($stored) && $stored !== []) {
                return ['replay' => AssistantRequestResult::fromStorageArray($stored)];
            }

            if ($this->usesAsyncAssistant()) {
                return [
                    'userMessage' => $existing,
                    'conversation' => $conversation,
                    'pendingAsync' => true,
                ];
            }

            throw new AssistantIdempotencyException(
                'A previous request with this Idempotency-Key did not finish storing a response.',
                AssistantIdempotencyException::CODE_IN_PROGRESS,
            );
        }

        return [
            'userMessage' => $userMessage,
            'conversation' => $conversation,
        ];
    }

    public function processQueuedAssistantRun(AssistantRun $run): void
    {
        $claimed = DB::transaction(function () use ($run): bool {
            /** @var AssistantRun|null $locked */
            $locked = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isTerminal()) {
                return false;
            }

            if ($locked->status === AssistantRunStatus::Processing) {
                return false;
            }

            if ($locked->status !== AssistantRunStatus::Queued) {
                return false;
            }

            $locked->forceFill([
                'status' => AssistantRunStatus::Processing,
                'started_at' => now(),
            ])->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        $run->refresh();
        $user = User::query()->find($run->user_id);
        $conversation = Conversation::query()->find($run->conversation_id);
        $userMessage = ConversationMessage::query()->find($run->conversation_message_id);

        if ($user === null || $conversation === null || $userMessage === null) {
            $this->runCoordinator->markFailed($run->fresh(), 'assistant_execution_failed', 'The assistant could not complete this request.');

            return;
        }

        try {
            $originalMessage = $run->original_message;
            $result = $this->executeAiPhase($user, $conversation, $userMessage, $originalMessage);
            $this->runCoordinator->persistTerminalResult($run->fresh(), $result);

            if ($userMessage->client_idempotency_key !== null && trim((string) $userMessage->client_idempotency_key) !== '') {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }
        } catch (Throwable) {
            $this->runCoordinator->markFailed($run->fresh(), 'assistant_execution_failed', 'The assistant could not complete this request.');
        }
    }

    private function orchestrateTurnAfterUserMessagePersisted(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $message,
        ?string $clientIdempotencyKey,
        bool $pendingAsync = false,
    ): AssistantRequestResult {
        if ($pendingAsync) {
            $run = $this->runCoordinator->findForUserMessage($userMessage);
            if ($run === null) {
                throw new AssistantIdempotencyException(
                    'A previous request with this Idempotency-Key did not finish storing a response.',
                    AssistantIdempotencyException::CODE_IN_PROGRESS,
                );
            }

            return $this->runCoordinator->acceptanceResult($run, $conversation);
        }

        $procurementResume = $this->purchasingWarehouseDestinationClarification->tryResume(
            $user,
            $conversation,
            $userMessage,
            $message,
            $clientIdempotencyKey,
        );
        if ($procurementResume !== null) {
            $result = $this->finalizeProcurementClarificationTurn(
                $user,
                $conversation,
                $userMessage,
                $message,
                $procurementResume,
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $salesPoEarlyRefusal = $this->salesPurchaseOrderCreationRefusal->refusalMessageFor($user, $message, $message);
        if ($salesPoEarlyRefusal !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $message,
                $salesPoEarlyRefusal,
                [
                    'deterministic' => true,
                    'sales_role_restriction' => 'create_draft_purchase_orders',
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $salesOverdueAnalysisRefusal = $this->salesSupplierOverdueAnalysisRefusal->refusalMessageFor($user, $message, $message);
        if ($salesOverdueAnalysisRefusal !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $message,
                $salesOverdueAnalysisRefusal,
                [
                    'deterministic' => true,
                    'sales_role_restriction' => 'supplier_overdue_purchase_order_analysis',
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $resolution = $this->followUpResolver->resolve($message, $conversation);

        if ($resolution->needsClarification) {
            $preflightResolved = $this->erpDeterministicPreflight->preferredResolvedQuestion(
                $message,
                is_string($resolution->resolvedStandaloneQuestion) ? $resolution->resolvedStandaloneQuestion : $message,
            );
            if ($this->erpDeterministicPreflight->shouldBypassClarification($user, $message, $preflightResolved)) {
                $resolution = new ContextResolutionResult(
                    wasAlreadyStandalone: $preflightResolved === $message,
                    needsClarification: false,
                    resolvedStandaloneQuestion: $preflightResolved !== $message ? $preflightResolved : null,
                    clarificationQuestion: null,
                    strategy: 'erp_clarification_bypass',
                );
            }
        }

        if ($resolution->needsClarification) {
            $result = $this->finalizeClarification($user, $conversation, $userMessage, $message, $resolution);
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $resolvedForModel = $this->resolvedQuestionForModel($message, $resolution);

        DB::transaction(function () use ($user, $userMessage, $message, $resolvedForModel, $resolution): void {
            Conversation::query()->whereKey($userMessage->conversation_id)->lockForUpdate()->first();

            $this->conversationService->mergeUserMessageResolution(
                $user,
                $userMessage,
                ($resolvedForModel !== $message) ? $resolvedForModel : null,
                [
                    'strategy' => $resolution->strategy,
                    'was_already_standalone' => $resolution->wasAlreadyStandalone,
                ],
            );
        });

        $userMessage->refresh();
        $conversation->refresh();

        $procurementBegin = $this->purchasingWarehouseDestinationClarification->tryBegin(
            $user,
            $conversation,
            $userMessage,
            $message,
            $resolvedForModel,
            $clientIdempotencyKey,
        );
        if ($procurementBegin !== null) {
            $result = $this->finalizeProcurementClarificationTurn(
                $user,
                $conversation,
                $userMessage,
                $message,
                $procurementBegin,
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $overduePurchaseOrderSearchReply = $this->overduePurchaseOrderSearchReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($overduePurchaseOrderSearchReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $overduePurchaseOrderSearchReply,
                [
                    'deterministic' => true,
                    'overdue_purchase_order_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $purchaseOrderSearchReply = $this->purchaseOrderSearchReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($purchaseOrderSearchReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $purchaseOrderSearchReply,
                [
                    'deterministic' => true,
                    'purchase_order_search_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $broadProductAvailabilityReply = $this->broadProductAvailabilityReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($broadProductAvailabilityReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $broadProductAvailabilityReply,
                [
                    'deterministic' => true,
                    'broad_product_availability_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $stockAcrossWarehousesReply = $this->stockAcrossWarehousesReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($stockAcrossWarehousesReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $stockAcrossWarehousesReply,
                [
                    'deterministic' => true,
                    'stock_across_warehouses_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $stockWarehouseCountReply = $this->stockWarehouseCountReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($stockWarehouseCountReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $stockWarehouseCountReply,
                [
                    'deterministic' => true,
                    'stock_warehouse_count_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $salesBankRefusal = $this->salesBankDetailsRefusal->refusalMessageFor($user, $resolvedForModel, $message);
        if ($salesBankRefusal !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $salesBankRefusal,
                [
                    'deterministic' => true,
                    'sales_role_restriction' => 'supplier_bank_details',
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $salesPoCreationRefusal = $this->salesPurchaseOrderCreationRefusal->refusalMessageFor($user, $resolvedForModel, $message);
        if ($salesPoCreationRefusal !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $salesPoCreationRefusal,
                [
                    'deterministic' => true,
                    'sales_role_restriction' => 'create_draft_purchase_orders',
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $purchasingLandedReply = $this->purchasingLandedCostReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($purchasingLandedReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $purchasingLandedReply,
                [
                    'deterministic' => true,
                    'purchasing_landed_cost_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        $purchasingBankReply = $this->purchasingBankDetailsReply->tryBuildReply($user, $resolvedForModel, $message);
        if ($purchasingBankReply !== null) {
            $result = $this->finalizeDeterministicAssistantReply(
                $user,
                $conversation,
                $userMessage,
                $message,
                $resolvedForModel,
                $purchasingBankReply,
                [
                    'deterministic' => true,
                    'purchasing_bank_disclosure' => true,
                ],
            );
            if ($clientIdempotencyKey !== null) {
                $this->conversationService->saveUserMessageIdempotencyResponse($user, $userMessage->fresh(), $result);
            }

            return $result;
        }

        if ($this->usesAsyncAssistant()) {
            $existingRun = $this->runCoordinator->findForUserMessage($userMessage);
            if ($existingRun !== null) {
                return $this->runCoordinator->acceptanceResult($existingRun, $conversation);
            }

            $run = $this->runCoordinator->enqueueAfterUserMessage(
                $user,
                $conversation,
                $userMessage,
                $message,
                $clientIdempotencyKey,
            );

            return $this->runCoordinator->acceptanceResult($run, $conversation);
        }

        return $this->executeAiPhase($user, $conversation, $userMessage, $message);
    }

    private function executeAiPhase(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $originalMessage,
    ): AssistantRequestResult {
        $resolvedForModel = $this->resolvedQuestionFromUserMessage($userMessage, $originalMessage);

        $aiProvider = strtolower((string) config('ai.provider', 'openai'));

        $priorContext = $this->contextManager->buildAiMessages(
            $conversation,
            $user,
            excludeMessageIds: [(int) $userMessage->id],
            aiProvider: $aiProvider,
        );

        $resetStockInheritance = $this->crossDomainRequestDetector->shouldResetInheritedStockProductFilters(
            $originalMessage,
            $resolvedForModel,
        );

        $systemInstruction = $this->buildSystemInstruction($user);
        if ($resetStockInheritance) {
            $systemInstruction .= "\n\nThe latest user message is a new independent ERP request. "
                .'Do not reuse product color, thickness, material, SKU, or warehouse filters from earlier turns unless restated in this message.';
        }

        $messages = array_values(array_merge($priorContext, [AiMessage::user($resolvedForModel)]));

        $inheritedProductFilters = $resetStockInheritance
            ? null
            : $this->stockThreadProductContext->inheritProductFilters(
                $conversation,
                $userMessage,
            );
        $stockGrounding = $this->stockCountIntentDetector->requiresGrounding(
            $resolvedForModel,
            $originalMessage,
            $inheritedProductFilters,
        );

        $aiRequest = new AiRequest(
            messages: $messages,
            systemInstruction: $systemInstruction,
            stockCountGrounding: $stockGrounding,
            stockCountGroundingQuestion: $resolvedForModel,
            stockCountInheritedProductFilters: $inheritedProductFilters,
            stockCountRawUserMessage: $originalMessage,
        );

        $recording = new AiConversationRecording(
            $user,
            $conversation->fresh(),
            $userMessage->fresh(),
            $this->conversationService,
        );

        $outcome = $this->toolCallingService->run(
            $aiRequest,
            $user,
            null,
            $recording,
            null,
        );

        return $this->outcomeToResult($conversation, $originalMessage, $resolvedForModel, $outcome);
    }

    private function resolvedQuestionFromUserMessage(ConversationMessage $userMessage, string $fallback): string
    {
        $meta = $userMessage->metadata;
        if (is_array($meta)) {
            $resolved = $meta['resolved_standalone_question'] ?? null;
            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        return $fallback;
    }

    private function usesAsyncAssistant(): bool
    {
        return AiProviderProfile::isOllama();
    }

    private function resolveConversation(User $user, ?string $publicId): Conversation
    {
        if ($publicId === null || trim($publicId) === '') {
            return $this->conversationService->createForUser($user);
        }

        return $this->conversationService->getOwnedByPublicId($user, $publicId);
    }

    private function assertConversationActive(Conversation $conversation): void
    {
        if ($conversation->status !== ConversationStatus::Active) {
            throw new ConversationAccessException(
                'This conversation is no longer active.',
                ConversationAccessException::CODE_INACTIVE,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $assistantMetadata
     */
    private function finalizeDeterministicAssistantReply(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $originalMessage,
        string $resolvedForModel,
        string $assistantText,
        array $assistantMetadata = [],
    ): AssistantRequestResult {
        DB::transaction(function () use ($user, $conversation, $userMessage, $assistantText, $assistantMetadata): void {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            $this->conversationService->appendAssistantMessage(
                $user,
                $conversation,
                $assistantText,
                metadata: $assistantMetadata,
            );
        });

        $conversation->refresh();

        return new AssistantRequestResult(
            success: true,
            httpStatus: 200,
            errorCode: 'ok',
            message: '',
            data: [
                'conversation_id' => $conversation->public_id,
                'message' => $assistantText,
                'original_message' => $originalMessage,
                'resolved_question' => $resolvedForModel,
                'clarification_required' => false,
                'clarification_question' => null,
                'provider' => null,
                'model' => null,
                'usage' => [
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                ],
                'tool_calls' => 0,
                'tool_iterations' => 0,
                'completed' => true,
                'incomplete_reason' => null,
                'error_code' => null,
            ],
        );
    }

    private function finalizeProcurementClarificationTurn(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $originalMessage,
        ProcurementClarificationTurnOutcome $outcome,
    ): AssistantRequestResult {
        DB::transaction(function () use ($user, $conversation, $userMessage, $outcome): void {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            if ($outcome->clarificationRequired) {
                $this->conversationService->mergeUserMessageResolution(
                    $user,
                    $userMessage,
                    null,
                    [
                        'procurement_clarification' => 'warehouse_destination',
                        'needs_clarification' => true,
                    ],
                );
            }

            $metadata = $outcome->assistantMetadata;
            if ($outcome->proposal !== null) {
                $metadata['purchase_order_proposal'] = $outcome->proposal;
            }

            $this->conversationService->appendAssistantMessage(
                $user,
                $conversation,
                $outcome->assistantText,
                metadata: $metadata,
            );
        });

        $conversation->refresh();

        $data = [
            'conversation_id' => $conversation->public_id,
            'message' => $outcome->assistantText,
            'original_message' => $originalMessage,
            'resolved_question' => null,
            'clarification_required' => $outcome->clarificationRequired,
            'clarification_question' => $outcome->clarificationQuestion,
            'provider' => null,
            'model' => null,
            'usage' => [
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
            ],
            'tool_calls' => 0,
            'tool_iterations' => 0,
            'completed' => true,
            'incomplete_reason' => null,
            'error_code' => null,
        ];

        if ($outcome->proposal !== null) {
            $data['purchase_order_proposal'] = $outcome->proposal;
        }

        return new AssistantRequestResult(
            success: true,
            httpStatus: 200,
            errorCode: 'ok',
            message: '',
            data: $data,
        );
    }

    private function finalizeClarification(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $originalMessage,
        ContextResolutionResult $resolution,
    ): AssistantRequestResult {
        DB::transaction(function () use ($user, $conversation, $userMessage, $resolution): void {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            $this->conversationService->mergeUserMessageResolution(
                $user,
                $userMessage,
                null,
                [
                    'strategy' => $resolution->strategy,
                    'needs_clarification' => true,
                ],
            );

            $text = $resolution->clarificationQuestion ?? 'Could you provide more detail?';

            $this->conversationService->appendAssistantMessage(
                $user,
                $conversation,
                $text,
                metadata: [
                    'clarification' => true,
                ],
            );
        });

        $conversation->refresh();

        return new AssistantRequestResult(
            success: true,
            httpStatus: 200,
            errorCode: 'ok',
            message: '',
            data: [
                'conversation_id' => $conversation->public_id,
                'message' => $resolution->clarificationQuestion ?? 'Could you provide more detail?',
                'original_message' => $originalMessage,
                'resolved_question' => null,
                'clarification_required' => true,
                'clarification_question' => $resolution->clarificationQuestion,
                'provider' => null,
                'model' => null,
                'usage' => [
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                ],
                'tool_calls' => 0,
                'tool_iterations' => 0,
                'completed' => true,
                'incomplete_reason' => null,
                'error_code' => null,
            ],
        );
    }

    /**
     * @param  non-empty-string  $trimmedOriginal
     */
    private function resolvedQuestionForModel(string $trimmedOriginal, ContextResolutionResult $resolution): string
    {
        $resolved = $resolution->resolvedStandaloneQuestion;
        if (is_string($resolved) && trim($resolved) !== '') {
            return trim($resolved);
        }

        if ($resolution->wasAlreadyStandalone) {
            return $trimmedOriginal;
        }

        return $trimmedOriginal;
    }

    private function buildSystemInstruction(User $user): string
    {
        if (strtolower((string) config('ai.provider')) !== 'ollama') {
            return $this->systemInstructionBuilder->buildLegacy($user);
        }

        return $this->systemInstructionBuilder->build(
            $user,
            $this->useCompactSystemInstructions(),
        );
    }

    private function useCompactSystemInstructions(): bool
    {
        if (strtolower((string) config('ai.provider')) !== 'ollama') {
            return false;
        }

        return (bool) config('ai.assistant.compact_system_instructions', false);
    }

    private function outcomeToResult(
        Conversation $conversation,
        string $originalMessage,
        string $resolvedForModel,
        AiToolCallingOutcome $outcome,
    ): AssistantRequestResult {
        $data = [
            'conversation_id' => $conversation->public_id,
            'message' => $outcome->text,
            'original_message' => $originalMessage,
            'resolved_question' => $resolvedForModel,
            'clarification_required' => false,
            'clarification_question' => null,
            'provider' => $outcome->provider,
            'model' => $outcome->model,
            'usage' => [
                'input_tokens' => $outcome->inputTokens,
                'output_tokens' => $outcome->outputTokens,
                'total_tokens' => $outcome->totalTokens,
            ],
            'tool_calls' => $outcome->toolCallsExecuted,
            'tool_iterations' => $outcome->toolIterations,
            'completed' => $outcome->completed,
            'incomplete_reason' => $outcome->incompleteReason,
            'error_code' => $outcome->errorCode,
        ];

        if ($outcome->completed) {
            return new AssistantRequestResult(
                success: true,
                httpStatus: 200,
                errorCode: 'ok',
                message: '',
                data: $data,
            );
        }

        [$httpStatus, $code, $message] = $this->mapOutcomeFailure($outcome);

        return new AssistantRequestResult(
            success: false,
            httpStatus: $httpStatus,
            errorCode: $code,
            message: $message,
            data: $data,
        );
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function mapOutcomeFailure(AiToolCallingOutcome $outcome): array
    {
        if ($outcome->incompleteReason === 'max_tokens' || $outcome->errorCode === 'max_tokens') {
            return [200, 'assistant_response_incomplete', 'The model response was cut off before completion.'];
        }

        $c = $outcome->errorCode ?? '';

        return match ($c) {
            AiProviderException::CODE_AUTHENTICATION,
            AiProviderException::CODE_MISSING_API_KEY => [502, 'ai_authentication_failed', 'The AI service could not authenticate. Check server configuration.'],
            AiProviderException::CODE_RATE_LIMIT => [429, 'ai_rate_limit', 'The AI service is temporarily rate-limited. Try again shortly.'],
            AiProviderException::CODE_TIMEOUT => [504, 'ai_timeout', 'The AI request timed out. Try again.'],
            AiProviderException::CODE_SERVER_ERROR => [503, 'ai_server_error', 'The AI service is temporarily unavailable. Try again later.'],
            AiProviderException::CODE_INVALID_RESPONSE => [502, 'ai_invalid_response', 'The AI service returned an invalid response.'],
            AiProviderException::CODE_PROVIDER_ERROR,
            AiProviderException::CODE_UNSUPPORTED_PROVIDER => [502, 'ai_provider_error', 'The AI service could not complete this request. Try again.'],
            AiToolLoopException::CODE_MAX_ITERATIONS => [422, 'tool_loop_limit', 'The tool-calling limit was reached for this answer.'],
            AiToolLoopException::CODE_MAX_TOOL_CALLS => [422, 'tool_calls_limit', 'Too many tool calls were needed for one answer.'],
            AiToolLoopException::CODE_REPEATED_SAME_CALLS => [422, 'tool_loop_repeated_calls', 'Repeated identical tool calls were stopped to prevent a loop.'],
            default => [503, 'assistant_execution_failed', 'The assistant could not complete this request.'],
        };
    }
}
