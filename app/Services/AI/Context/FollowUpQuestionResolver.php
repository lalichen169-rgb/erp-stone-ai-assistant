<?php

namespace App\Services\AI\Context;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AI\AiAgentService;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Grounding\ErpQuestionStandaloneHeuristic;

final class FollowUpQuestionResolver
{
    public function __construct(
        private readonly AiAgentService $agentService,
        private readonly ConversationThreadAnchorResolver $threadAnchorResolver,
        private readonly DeterministicFollowUpResolver $deterministicFollowUpResolver,
        private readonly ConversationTurnPairCollector $pairCollector,
        private readonly ErpQuestionStandaloneHeuristic $standaloneHeuristic,
    ) {}

    public function resolve(string $newUserText, Conversation $conversation): ContextResolutionResult
    {
        $trimmed = trim($newUserText);
        if ($trimmed === '') {
            return new ContextResolutionResult(
                wasAlreadyStandalone: false,
                needsClarification: true,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: 'Please ask a specific question.',
                strategy: 'empty',
            );
        }

        if ($this->standaloneHeuristic->isActionableStandalone($trimmed)) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: true,
                needsClarification: false,
                resolvedStandaloneQuestion: $this->standaloneHeuristic->canonicalQuestion($trimmed) ?? $trimmed,
                clarificationQuestion: null,
                strategy: 'erp_actionable_standalone',
            );
        }

        if ($this->looksClearlyStandalone($trimmed)) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: true,
                needsClarification: false,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: null,
                strategy: 'standalone_heuristic',
            );
        }

        $pairs = $this->pairCollector->recentCompletedPairs($conversation, 8);
        if ($pairs === []) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: true,
                needsClarification: false,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: null,
                strategy: 'no_prior_turn',
            );
        }

        $anchor = $this->threadAnchorResolver->resolveAnchor($conversation, $trimmed);
        $anchorQuestion = $anchor['question'] ?? $pairs[0]['question'];
        $anchorAnswer = $anchor['answer'] ?? $pairs[0]['answer'];
        $threadType = $anchor['type'] ?? null;

        $deterministic = $this->deterministicFollowUpResolver->tryExpand(
            $trimmed,
            $anchorQuestion,
            $anchorAnswer,
            $threadType,
        );
        if ($deterministic !== null) {
            return $deterministic;
        }

        if (! (bool) config('ai.conversation.follow_up.use_ai_when_ambiguous', true)) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: false,
                needsClarification: true,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: 'Could you rephrase your question as a full sentence, including product and location or supplier names?',
                strategy: 'ambiguous_no_ai',
            );
        }

        try {
            return $this->resolveWithAi($trimmed, $anchorQuestion, $anchorAnswer);
        } catch (AiProviderException|\Throwable) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: false,
                needsClarification: true,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: 'I could not safely expand that follow-up. Please ask a standalone question.',
                strategy: 'ai_resolver_failed',
                metadata: ['safe_fallback' => true],
            );
        }
    }

    private function looksClearlyStandalone(string $text): bool
    {
        if (strlen($text) >= (int) config('ai.conversation.follow_up.long_question_min_chars', 90)) {
            return true;
        }

        if (preg_match('/\b(how many|show|list|find|search|what are|give me)\b/i', $text) === 1) {
            if (strlen($text) >= 40) {
                return true;
            }
        }

        return false;
    }

    private function resolveWithAi(string $followUp, string $previousQuestion, string $previousAnswer): ContextResolutionResult
    {
        $system = <<<'TXT'
You expand short follow-up questions into ONE standalone ERP question using ONLY the prior user question and assistant answer.
Rules:
- Output JSON ONLY on a single line. No markdown. Schema:
  {"is_standalone":bool,"needs_clarification":bool,"resolved_question":string|null,"clarification_question":string|null}
- Never invent SKUs, IDs, dates, or suppliers not implied by the context.
- If unsure, set needs_clarification true and provide a short clarification_question.
- Do not execute tools or reference APIs.
TXT;

        $userBlock = "PREVIOUS_USER_QUESTION:\n{$previousQuestion}\n\nPREVIOUS_ASSISTANT_ANSWER:\n{$previousAnswer}\n\nNEW_FOLLOW_UP:\n{$followUp}";

        $response = $this->agentService->complete(new AiRequest(
            messages: [AiMessage::user($userBlock)],
            systemInstruction: $system,
        ));

        $parsed = $this->decodeJsonPayload($response->text);
        if ($parsed === null) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: false,
                needsClarification: true,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: 'Please restate your question with full detail.',
                strategy: 'ai_invalid_json',
            );
        }

        $needs = (bool) ($parsed['needs_clarification'] ?? false);
        $resolved = isset($parsed['resolved_question']) && is_string($parsed['resolved_question'])
            ? trim($parsed['resolved_question'])
            : null;

        if ($needs || $resolved === null || $resolved === '') {
            $clar = $parsed['clarification_question'] ?? null;

            return new ContextResolutionResult(
                wasAlreadyStandalone: false,
                needsClarification: true,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: is_string($clar) ? $clar : 'What would you like to know in one full sentence?',
                strategy: 'ai_clarification',
                metadata: ['raw' => $parsed],
            );
        }

        return new ContextResolutionResult(
            wasAlreadyStandalone: false,
            needsClarification: false,
            resolvedStandaloneQuestion: $resolved,
            clarificationQuestion: null,
            strategy: 'ai_resolved',
            metadata: ['raw' => $parsed],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonPayload(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[^}]+\}/s', $text, $m) === 1) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
