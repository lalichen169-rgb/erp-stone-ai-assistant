<?php

namespace App\Services\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\DTOs\AI\AiToolCall;
use App\DTOs\AI\AiToolCallingOutcome;
use App\Models\User;
use App\Support\AiSafeText;
use App\Services\AI\Conversations\ConversationSummaryService;
use App\Services\AI\Conversations\ConversationTurnRecorder;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Exceptions\AiToolLoopException;
use App\Services\AI\ToolCalling\AiToolCallingDriverFactory;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;

final class AiToolCallingService
{
    public function __construct(
        private readonly AiAgentService $agentService,
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolExecutor $toolExecutor,
        private readonly AiToolCallingDriverFactory $driverFactory,
        private readonly ConversationSummaryService $summaryService,
    ) {}

    public function run(
        AiRequest $request,
        User $user,
        ?ToolExecutionContext $executionContext = null,
        ?AiConversationRecording $conversationRecording = null,
        ?string $systemInstruction = null,
    ): AiToolCallingOutcome {
        $startedAt = microtime(true);

        $turnRecorder = null;
        if ($conversationRecording !== null) {
            $turnRecorder = new ConversationTurnRecorder(
                $conversationRecording->conversation,
                $conversationRecording->userMessage,
            );
        }

        $trustedKey = $executionContext?->conversationKey;
        if ($trustedKey === null && $conversationRecording !== null) {
            $trustedKey = $conversationRecording->conversation->getProposalConversationKey();
        }

        $trustedIdem = $executionContext?->idempotencyKey;
        if ($trustedIdem === null && $conversationRecording !== null) {
            $trustedIdem = $conversationRecording->userMessage->turn_idempotency_key;
        }

        $context = new ToolExecutionContext(
            $user,
            $trustedKey,
            $trustedIdem,
            $turnRecorder,
        );

        $messages = $request->messages;

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $messages = array_values(array_merge([AiMessage::system($systemInstruction)], $messages));
        }

        $request = new AiRequest(
            messages: $messages,
            systemInstruction: $request->systemInstruction,
            tools: $request->tools,
            model: $request->model,
            metadata: $request->metadata,
            openaiPreviousResponseId: $request->openaiPreviousResponseId,
            openaiToolOutputInput: $request->openaiToolOutputInput,
            claudeMessages: $request->claudeMessages,
            toolChoice: $request->toolChoice,
        );

        $definitions = $this->toolRegistry->definitionsFor($user);

        $driver = $this->driverFactory->make();

        $aggregatedInput = 0;
        $aggregatedOutput = 0;
        $aggregatedTotal = 0;
        $toolIterations = 0;
        $toolCallsExecuted = 0;

        $maxIterations = (int) config('ai.tool_loop.max_iterations', 8);
        $maxCalls = (int) config('ai.tool_loop.max_tool_calls', 32);

        $lastBatchFingerprint = null;

        $iterationRequest = $driver->buildFirstRequest($request, $definitions);

        try {
            while (true) {
                $response = $this->agentService->complete($iterationRequest);

                [$aggregatedInput, $aggregatedOutput, $aggregatedTotal] = $this->mergeUsage(
                    $aggregatedInput,
                    $aggregatedOutput,
                    $aggregatedTotal,
                    $response,
                );

                if ($response->toolCalls === []) {
                    $completed = $this->isFinalResponseCompleted($response);

                    $incompleteReason = $completed ? null : $this->incompleteReason($response);

                    $outcome = new AiToolCallingOutcome(
                        text: $response->text,
                        provider: $response->provider,
                        model: $response->model,
                        providerResponseId: $response->providerResponseId,
                        inputTokens: $this->nullableInt($aggregatedInput),
                        outputTokens: $this->nullableInt($aggregatedOutput),
                        totalTokens: $this->nullableInt($aggregatedTotal),
                        toolIterations: $toolIterations,
                        toolCallsExecuted: $toolCallsExecuted,
                        completed: $completed,
                        incompleteReason: $incompleteReason,
                        errorCode: $completed ? null : ($incompleteReason === 'max_tokens' ? 'max_tokens' : null),
                    );

                    $this->finalizeConversationRecording(
                        $conversationRecording,
                        $user,
                        $turnRecorder,
                        $outcome,
                        $startedAt,
                        null,
                    );

                    return $outcome;
                }

                $toolIterations++;
                if ($toolIterations > $maxIterations) {
                    throw new AiToolLoopException(
                        'Maximum AI tool iterations reached.',
                        AiToolLoopException::CODE_MAX_ITERATIONS,
                    );
                }

                $fingerprint = $this->fingerprintToolCalls($response->toolCalls);
                if ($lastBatchFingerprint !== null && $fingerprint === $lastBatchFingerprint) {
                    throw new AiToolLoopException(
                        'The model repeated identical tool calls; stopping to prevent a loop.',
                        AiToolLoopException::CODE_REPEATED_SAME_CALLS,
                    );
                }
                $lastBatchFingerprint = $fingerprint;

                if ($toolCallsExecuted + count($response->toolCalls) > $maxCalls) {
                    throw new AiToolLoopException(
                        'Maximum total tool calls exceeded.',
                        AiToolLoopException::CODE_MAX_TOOL_CALLS,
                    );
                }

                /** @var array<string, ToolExecutionResult> $resultsById */
                $resultsById = [];

                foreach ($response->toolCalls as $call) {
                    $result = $this->toolExecutor->execute($context, $call);
                    $toolCallsExecuted++;
                    $resultsById[$call->id] = $result;
                    $turnRecorder?->add($call, $result);
                }

                $iterationRequest = $driver->buildFollowUpRequest($request, $response, $resultsById, $definitions);
            }
        } catch (AiToolLoopException $exception) {
                $outcome = $this->failureOutcome(
                    AiSafeText::forLoopException($exception),
                    $aggregatedInput,
                    $aggregatedOutput,
                    $aggregatedTotal,
                    $toolIterations,
                    $toolCallsExecuted,
                    $exception->errorCode,
                );

            $this->finalizeConversationRecording(
                $conversationRecording,
                $user,
                $turnRecorder,
                $outcome,
                $startedAt,
                $exception->errorCode,
            );

            return $outcome;
        } catch (AiProviderException $exception) {
            $outcome = $this->failureOutcome(
                AiSafeText::forProviderException($exception),
                $aggregatedInput,
                $aggregatedOutput,
                $aggregatedTotal,
                $toolIterations,
                $toolCallsExecuted,
                $exception->errorCode(),
            );

            $this->finalizeConversationRecording(
                $conversationRecording,
                $user,
                $turnRecorder,
                $outcome,
                $startedAt,
                $exception->errorCode(),
            );

            return $outcome;
        }
    }

    /**
     * @param  0|positive-int  $aggregatedInput
     */
    private function failureOutcome(
        string $text,
        int $aggregatedInput,
        int $aggregatedOutput,
        int $aggregatedTotal,
        int $toolIterations,
        int $toolCallsExecuted,
        ?string $errorCode,
    ): AiToolCallingOutcome {
        $provider = strtolower(trim((string) config('ai.provider')));
        if ($provider === 'anthropic') {
            $provider = 'claude';
        }

        $model = $provider === 'claude'
            ? (string) config('ai.claude.model', 'claude')
            : (string) config('ai.openai.model', 'gpt-4o-mini');

        return new AiToolCallingOutcome(
            text: $text,
            provider: $provider === 'claude' ? 'claude' : 'openai',
            model: $model,
            providerResponseId: null,
            inputTokens: $this->nullableInt($aggregatedInput),
            outputTokens: $this->nullableInt($aggregatedOutput),
            totalTokens: $this->nullableInt($aggregatedTotal),
            toolIterations: $toolIterations,
            toolCallsExecuted: $toolCallsExecuted,
            completed: false,
            incompleteReason: 'error',
            errorCode: $errorCode,
        );
    }

    private function finalizeConversationRecording(
        ?AiConversationRecording $recording,
        User $user,
        ?ConversationTurnRecorder $turnRecorder,
        AiToolCallingOutcome $outcome,
        float $startedAt,
        ?string $errorCode,
    ): void {
        if ($recording === null) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($turnRecorder !== null && $turnRecorder->traces !== []) {
            $recording->conversationService->persistToolTraces($user, $turnRecorder);
        }

        $recording->conversationService->appendAssistantMessage(
            $user,
            $recording->conversation,
            $outcome->text,
            metadata: [
                'provider' => $outcome->provider,
                'model' => $outcome->model,
                'incomplete_reason' => $outcome->incompleteReason,
            ],
        );

        $recording->conversationService->recordAiInteraction(
            $user,
            $recording->conversation,
            $recording->userMessage,
            $outcome,
            $durationMs,
            $errorCode ?? $outcome->errorCode,
        );

        $this->summaryService->maybeRefreshAfterTurn($recording->conversation);
    }

    private function isFinalResponseCompleted(AiResponse $response): bool
    {
        if ($response->provider === 'claude') {
            return ($response->stopReason ?? '') !== 'max_tokens';
        }

        return true;
    }

    private function incompleteReason(AiResponse $response): ?string
    {
        if ($response->provider === 'claude' && ($response->stopReason ?? '') === 'max_tokens') {
            return 'max_tokens';
        }

        return null;
    }

    /**
     * @param  list<AiToolCall>  $calls
     */
    private function fingerprintToolCalls(array $calls): string
    {
        $parts = [];

        foreach ($calls as $call) {
            $args = $call->arguments;
            ksort($args);
            $parts[] = $call->name.json_encode($args, JSON_THROW_ON_ERROR);
        }

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function mergeUsage(
        int $in,
        int $out,
        int $tot,
        AiResponse $response,
    ): array {
        if ($response->inputTokens !== null) {
            $in += $response->inputTokens;
        }

        if ($response->outputTokens !== null) {
            $out += $response->outputTokens;
        }

        if ($response->totalTokens !== null) {
            $tot += $response->totalTokens;
        }

        return [$in, $out, $tot];
    }

    private function nullableInt(int $value): ?int
    {
        return $value > 0 ? $value : null;
    }
}
