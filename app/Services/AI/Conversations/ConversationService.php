<?php

namespace App\Services\AI\Conversations;

use App\DTOs\AI\AiToolCallingOutcome;
use App\Enums\ConversationMessageRole;
use App\Enums\ConversationStatus;
use App\Enums\ConversationToolCallStatus;
use App\Models\Conversation;
use App\Models\ConversationAiInteraction;
use App\Models\ConversationMessage;
use App\Models\ConversationToolCall;
use App\Models\User;
use App\Services\AI\AssistantRequestResult;
use App\Services\AI\Tools\ToolExecutionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConversationService
{
    public function __construct(
        private readonly ToolResultRedactor $redactor,
    ) {}

    public function createForUser(User $user, ?string $title = null): Conversation
    {
        $conversation = new Conversation([
            'user_id' => $user->id,
            'title' => $title,
            'status' => ConversationStatus::Active,
            'last_activity_at' => now(),
        ]);
        $conversation->save();

        return $conversation;
    }

    public function getOwnedByPublicId(User $user, string $publicId): Conversation
    {
        $candidate = Conversation::query()
            ->where('public_id', $publicId)
            ->first();

        if ($candidate === null) {
            throw new ConversationAccessException(
                'Conversation not found.',
                ConversationAccessException::CODE_NOT_FOUND,
            );
        }

        if ($candidate->user_id !== $user->id) {
            throw new ConversationAccessException(
                'Forbidden.',
                ConversationAccessException::CODE_FORBIDDEN,
            );
        }

        return $candidate;
    }

    public function assertOwned(User $user, Conversation $conversation): void
    {
        if ($conversation->user_id !== $user->id) {
            throw new ConversationAccessException(
                'Forbidden.',
                ConversationAccessException::CODE_FORBIDDEN,
            );
        }
    }

    public function archive(User $user, Conversation $conversation): void
    {
        $this->assertOwned($user, $conversation);
        $conversation->status = ConversationStatus::Archived;
        $conversation->save();
    }

    /**
     * Merge resolution metadata into an existing user message (same turn).
     *
     * @param  array<string, mixed>  $resolutionMetadata
     */
    public function mergeUserMessageResolution(
        User $user,
        ConversationMessage $message,
        ?string $resolvedStandaloneQuestion,
        array $resolutionMetadata,
    ): void {
        $conversation = Conversation::query()->find($message->conversation_id);
        if ($conversation === null) {
            throw new ConversationAccessException(
                'Conversation not found.',
                ConversationAccessException::CODE_NOT_FOUND,
            );
        }

        $this->assertOwned($user, $conversation);

        $meta = $message->metadata ?? [];
        if ($resolvedStandaloneQuestion !== null) {
            $meta['resolved_standalone_question'] = $resolvedStandaloneQuestion;
        }
        $meta['resolution'] = array_merge($meta['resolution'] ?? [], $resolutionMetadata);

        $message->forceFill(['metadata' => $meta === [] ? null : $meta])->save();
        $this->touchActivity($conversation);
    }

    /**
     * Persist replay payload for Idempotency-Key (HTTP) on the user turn row.
     */
    public function saveUserMessageIdempotencyResponse(
        User $user,
        ConversationMessage $userMessage,
        AssistantRequestResult $result,
    ): void {
        $conversation = Conversation::query()->find($userMessage->conversation_id);
        if ($conversation === null) {
            return;
        }

        $this->assertOwned($user, $conversation);

        $userMessage->forceFill([
            'idempotency_response' => $result->toStorageArray(),
        ])->save();
    }

    /**
     * Append the user turn before the model run. Original text is always stored in `content`.
     *
     * @param  array<string, mixed>|null  $resolutionMetadata
     */
    public function appendUserMessage(
        User $user,
        Conversation $conversation,
        string $originalText,
        ?string $resolvedStandaloneQuestion = null,
        ?array $resolutionMetadata = null,
        ?string $clientIdempotencyKey = null,
    ): ConversationMessage {
        $this->assertOwned($user, $conversation);

        $turnKey = (string) Str::uuid();
        $seq = $this->nextSequence($conversation);

        $metadata = [];
        if ($resolvedStandaloneQuestion !== null) {
            $metadata['resolved_standalone_question'] = $resolvedStandaloneQuestion;
        }
        if ($resolutionMetadata !== null) {
            $metadata['resolution'] = $resolutionMetadata;
        }

        $message = ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sequence' => $seq,
            'role' => ConversationMessageRole::User,
            'content' => $originalText,
            'metadata' => $metadata === [] ? null : $metadata,
            'is_internal' => false,
            'turn_idempotency_key' => $turnKey,
            'client_idempotency_key' => $clientIdempotencyKey,
        ]);

        $this->touchActivity($conversation);

        return $message;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function appendAssistantMessage(
        User $user,
        Conversation $conversation,
        string $text,
        ?array $metadata = null,
    ): ConversationMessage {
        $this->assertOwned($user, $conversation);

        return DB::transaction(function () use ($user, $conversation, $text, $metadata): ConversationMessage {
            $msg = ConversationMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sequence' => $this->nextSequence($conversation),
                'role' => ConversationMessageRole::Assistant,
                'content' => $text,
                'metadata' => $metadata,
                'is_internal' => false,
            ]);
            $this->touchActivity($conversation);

            return $msg;
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function appendInternalAssistantNote(
        User $user,
        Conversation $conversation,
        string $text,
        ?array $metadata = null,
    ): ConversationMessage {
        $this->assertOwned($user, $conversation);

        return DB::transaction(function () use ($user, $conversation, $text, $metadata): ConversationMessage {
            $msg = ConversationMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sequence' => $this->nextSequence($conversation),
                'role' => ConversationMessageRole::Assistant,
                'content' => $text,
                'metadata' => $metadata,
                'is_internal' => true,
            ]);
            $this->touchActivity($conversation);

            return $msg;
        });
    }

    public function persistToolTraces(User $user, ConversationTurnRecorder $recorder): void
    {
        $this->assertOwned($user, $recorder->conversation);

        if ($recorder->traces === []) {
            return;
        }

        DB::transaction(function () use ($recorder): void {
            $order = 0;
            foreach ($recorder->traces as $trace) {
                $order++;
                $call = $trace['call'];
                $result = $this->redactor->redactExecutionResult($trace['result']);

                $toolMsg = ConversationMessage::query()->create([
                    'conversation_id' => $recorder->conversation->id,
                    'sequence' => $this->nextSequence($recorder->conversation),
                    'role' => ConversationMessageRole::Tool,
                    'content' => json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'metadata' => [
                        'provider_tool_call_id' => $call->id,
                        'tool_name' => $call->name,
                    ],
                    'is_internal' => false,
                ]);

                ConversationToolCall::query()->create([
                    'conversation_id' => $recorder->conversation->id,
                    'user_message_id' => $recorder->userMessage->id,
                    'tool_result_message_id' => $toolMsg->id,
                    'execution_order' => $order,
                    'provider_tool_call_id' => $call->id,
                    'tool_name' => $call->name,
                    'arguments' => $call->arguments,
                    'status' => $result->success ? ConversationToolCallStatus::Completed : ConversationToolCallStatus::Failed,
                    'safe_result' => $result->toArray(),
                    'error_code' => $result->errorCode,
                ]);

                $this->touchActivity($recorder->conversation);
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $extraMetadata
     */
    public function recordAiInteraction(
        User $user,
        Conversation $conversation,
        ?ConversationMessage $userMessage,
        AiToolCallingOutcome $outcome,
        ?int $durationMs = null,
        ?string $errorCode = null,
        ?array $extraMetadata = null,
    ): ConversationAiInteraction {
        $this->assertOwned($user, $conversation);

        return ConversationAiInteraction::query()->create([
            'conversation_id' => $conversation->id,
            'user_message_id' => $userMessage?->id,
            'provider' => $outcome->provider,
            'model' => $outcome->model,
            'input_tokens' => $outcome->inputTokens,
            'output_tokens' => $outcome->outputTokens,
            'total_tokens' => $outcome->totalTokens,
            'tool_iterations' => $outcome->toolIterations,
            'tool_calls_executed' => $outcome->toolCallsExecuted,
            'duration_ms' => $durationMs,
            'completed' => $outcome->completed,
            'error_code' => $errorCode,
            'metadata' => $extraMetadata,
        ]);
    }

    public function chronologicalMessages(Conversation $conversation): \Illuminate\Database\Eloquent\Collection
    {
        return $conversation->messages()->orderBy('sequence')->get();
    }

    private function nextSequence(Conversation $conversation): int
    {
        $max = (int) ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->max('sequence');

        return $max + 1;
    }

    private function touchActivity(Conversation $conversation): void
    {
        $conversation->forceFill(['last_activity_at' => now()])->save();
    }
}
