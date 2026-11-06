<?php

namespace App\Services\AI\Context;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AI\AiAgentService;
use App\Services\AI\Exceptions\AiProviderException;

final class FollowUpQuestionResolver
{
    public function __construct(
        private readonly AiAgentService $agentService,
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

        if ($this->looksClearlyStandalone($trimmed)) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: true,
                needsClarification: false,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: null,
                strategy: 'standalone_heuristic',
            );
        }

        $prev = $this->lastCompletedUserAssistantPair($conversation);
        if ($prev === null) {
            return new ContextResolutionResult(
                wasAlreadyStandalone: true,
                needsClarification: false,
                resolvedStandaloneQuestion: null,
                clarificationQuestion: null,
                strategy: 'no_prior_turn',
            );
        }

        $previousUser = $prev['user'];
        $previousAssistant = $prev['assistant'];

        $baseQuestion = $previousUser->metadata['resolved_standalone_question'] ?? $previousUser->content;
        $baseQuestion = is_string($baseQuestion) ? $baseQuestion : (string) $previousUser->content;

        $deterministic = $this->tryDeterministic($trimmed, $baseQuestion, (string) $previousAssistant->content);
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
            return $this->resolveWithAi($trimmed, $baseQuestion, (string) $previousAssistant->content);
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

    /**
     * @return array{user: ConversationMessage, assistant: ConversationMessage}|null
     */
    private function lastCompletedUserAssistantPair(Conversation $conversation): ?array
    {
        $users = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->where('role', \App\Enums\ConversationMessageRole::User)
            ->orderByDesc('sequence')
            ->limit(5)
            ->get();

        foreach ($users as $lastUser) {
            $assistant = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('is_internal', false)
                ->where('role', \App\Enums\ConversationMessageRole::Assistant)
                ->where('sequence', '>', $lastUser->sequence)
                ->orderBy('sequence')
                ->first();

            if ($assistant !== null) {
                return ['user' => $lastUser, 'assistant' => $assistant];
            }
        }

        return null;
    }

    private function tryDeterministic(string $followUp, string $previousQuestion, string $previousAnswer): ?ContextResolutionResult
    {
        // "And in Dallas?" / "in Dallas?"
        if (preg_match('/^(and\s+)?in\s+(?P<city>[A-Za-z][A-Za-z\s\-]+)\??$/i', $followUp, $m) === 1) {
            $city = trim($m['city']);
            if ($city !== '' && preg_match('/Houston|Dallas|Austin/i', $previousQuestion) === 1) {
                $resolved = preg_replace('/Houston|Dallas|Austin/i', $city, $previousQuestion, 1);

                return new ContextResolutionResult(
                    wasAlreadyStandalone: false,
                    needsClarification: false,
                    resolvedStandaloneQuestion: $resolved,
                    clarificationQuestion: null,
                    strategy: 'deterministic_city_swap',
                );
            }
        }

        // "What about 2cm?" thickness
        if (preg_match('/^(what\s+about|and\s+for)\s+(?P<th>\d+(\.\d+)?)\s*cm\??$/i', $followUp, $m) === 1) {
            $th = $m['th'];
            $resolved = preg_replace('/\b\d+(\.\d+)?\s*cm\b/i', $th.' cm', $previousQuestion, 1);

            return new ContextResolutionResult(
                wasAlreadyStandalone: false,
                needsClarification: false,
                resolvedStandaloneQuestion: $resolved,
                clarificationQuestion: null,
                strategy: 'deterministic_thickness',
            );
        }

        // "What about Supplier X?"
        if (preg_match('/^(what|how)\s+about\s+(?P<supplier>.+)\??$/i', $followUp, $m) === 1) {
            $supplier = trim($m['supplier']);
            if ($supplier !== '' && preg_match('/supplier/i', $previousQuestion) === 1) {
                $resolved = preg_replace(
                    '/Supplier\s+[A-Za-z0-9\s\-]+/i',
                    'Supplier '.$supplier,
                    $previousQuestion,
                    1,
                );

                return new ContextResolutionResult(
                    wasAlreadyStandalone: false,
                    needsClarification: false,
                    resolvedStandaloneQuestion: $resolved,
                    clarificationQuestion: null,
                    strategy: 'deterministic_supplier_swap',
                );
            }
        }

        return null;
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
