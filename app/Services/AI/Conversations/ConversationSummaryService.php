<?php

namespace App\Services\AI\Conversations;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSummary;
use App\Services\AI\AiAgentService;
use App\Services\AI\Exceptions\AiProviderException;
use Throwable;

final class ConversationSummaryService
{
    public function __construct(
        private readonly AiAgentService $agentService,
    ) {}

    public function maybeRefreshAfterTurn(Conversation $conversation): void
    {
        $minMessages = (int) config('ai.conversation.summary.min_messages_for_summary', 4);
        $interval = (int) config('ai.conversation.summary.refresh_message_interval', 10);

        $total = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->count();

        if ($total < $minMessages) {
            return;
        }

        $latest = $conversation->latestSummary;
        $afterSeq = $latest?->last_summarized_sequence ?? 0;
        $pending = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->where('sequence', '>', $afterSeq)
            ->count();

        if ($latest !== null && $pending < $interval) {
            return;
        }

        try {
            $this->generateAndStore($conversation);
        } catch (AiProviderException|Throwable) {
            // Summaries are optional; never break persistence.
        }
    }

    private function generateAndStore(Conversation $conversation): void
    {
        $latest = $conversation->latestSummary;
        $afterSeq = $latest?->last_summarized_sequence ?? 0;

        $chunk = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->where('sequence', '>', $afterSeq)
            ->orderBy('sequence')
            ->limit(80)
            ->get();

        if ($chunk->isEmpty()) {
            return;
        }

        $lines = [];
        foreach ($chunk as $row) {
            $lines[] = $row->role->value.': '.$this->messageSnippet($row);
        }

        $body = implode("\n", $lines);

        $system = <<<'TXT'
You write concise conversation summaries for Stone ERP assistant logs.
Rules:
- Only restate what appears in the transcript. Do not invent IDs, numbers, or supplier names.
- Separate confirmed facts vs open questions.
- Do not include API keys, passwords, bank details, or raw JSON blobs.
- Maximum 800 words. Plain text.
TXT;

        $response = $this->agentService->complete(new AiRequest(
            messages: [AiMessage::user($body)],
            systemInstruction: $system,
        ));

        $text = trim($response->text);
        if ($text === '') {
            return;
        }

        $version = (int) (ConversationSummary::query()->where('conversation_id', $conversation->id)->max('version') ?? 0) + 1;

        $lastMessage = $chunk->last();

        ConversationSummary::query()->create([
            'conversation_id' => $conversation->id,
            'version' => $version,
            'content' => $text,
            'last_summarized_message_id' => $lastMessage?->id,
            'last_summarized_sequence' => $lastMessage?->sequence,
            'covered_message_count' => $chunk->count(),
        ]);
    }

    private function messageSnippet(ConversationMessage $row): string
    {
        $content = (string) $row->content;
        if (strlen($content) > 2000) {
            return substr($content, 0, 2000).'…';
        }

        return $content;
    }
}
