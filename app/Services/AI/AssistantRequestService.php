<?php

namespace App\Services\AI;

use App\DTOs\AI\AiToolCallingOutcome;
use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Enums\ConversationMessageRole;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\AI\Context\ContextResolutionResult;
use App\Services\AI\Context\ConversationContextManager;
use App\Services\AI\Context\FollowUpQuestionResolver;
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

        return $this->continueAfterUserMessagePersisted($user, $conversation->fresh(), $userMessage, $message, null);
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

            $result = $this->continueAfterUserMessagePersisted(
                $user,
                $tx['conversation']->fresh(),
                $tx['userMessage']->fresh(),
                $message,
                $clientIdempotencyKey,
            );

            $this->conversationService->saveUserMessageIdempotencyResponse($user, $tx['userMessage']->fresh(), $result);

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

    private function continueAfterUserMessagePersisted(
        User $user,
        Conversation $conversation,
        ConversationMessage $userMessage,
        string $message,
        ?string $clientIdempotencyKey,
    ): AssistantRequestResult {
        $resolution = $this->followUpResolver->resolve($message, $conversation);

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

        $priorContext = $this->contextManager->buildAiMessages(
            $conversation,
            $user,
            excludeMessageIds: [(int) $userMessage->id],
        );

        $systemInstruction = $this->buildSystemInstruction($user);

        $messages = array_values(array_merge($priorContext, [AiMessage::user($resolvedForModel)]));

        $aiRequest = new AiRequest(
            messages: $messages,
            systemInstruction: $systemInstruction,
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

        return $this->outcomeToResult($conversation, $message, $resolvedForModel, $outcome);
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
        if ($resolution->wasAlreadyStandalone) {
            return $trimmedOriginal;
        }

        $resolved = $resolution->resolvedStandaloneQuestion;

        return (is_string($resolved) && trim($resolved) !== '') ? trim($resolved) : $trimmedOriginal;
    }

    private function buildSystemInstruction(User $user): string
    {
        $roleLine = $user->isPurchasing()
            ? 'The user has the Purchasing role and may use cost-related tools where registered.'
            : 'The user has the Sales role. Do not surface unit costs, freight costs, landed costs, supplier bank details, or use cost-only tools. If the user asks for restricted data, explain briefly that it is not available to their role.';

        return <<<TXT
You are the Stone ERP AI assistant embedded in a B2B stone slab distributor application.

{$roleLine}

Rules:
- Use only the registered ERP tools for factual questions about inventory, purchase orders, overdue orders, landed cost (Purchasing only), and draft PO proposals (Purchasing only).
- Never invent stock quantities, prices, costs, suppliers, purchase orders, or proposal IDs. Ground factual answers in tool results only.
- Do not claim a tool succeeded if the tool returned an error.
- Do not claim a purchase order was created because a draft proposal was created. Draft proposals require explicit confirmation through the application's confirmation API.
- If tools return partial information, say so. Ask for clarification when filters are ambiguous and tools cannot be called safely.
- Respond concisely in plain language suitable for business users.
TXT;
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
            AiToolLoopException::CODE_MAX_ITERATIONS => [422, 'tool_loop_limit', 'The tool-calling limit was reached for this answer.'],
            AiToolLoopException::CODE_MAX_TOOL_CALLS => [422, 'tool_calls_limit', 'Too many tool calls were needed for one answer.'],
            AiToolLoopException::CODE_REPEATED_SAME_CALLS => [422, 'tool_loop_repeated_calls', 'Repeated identical tool calls were stopped to prevent a loop.'],
            default => [503, 'assistant_execution_failed', 'The assistant could not complete this request.'],
        };
    }
}
