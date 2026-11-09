<?php

namespace Tests\Support;

final class AiTestResponses
{
    /**
     * Usage shape required by openai-php CreateResponse::fake().
     *
     * @return array<string, mixed>
     */
    public static function openAiUsage(int $inputTokens, int $outputTokens, int $totalTokens): array
    {
        return [
            'input_tokens' => $inputTokens,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens' => $outputTokens,
            'output_tokens_details' => ['reasoning_tokens' => 0],
            'total_tokens' => $totalTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function openAiFunctionCall(
        string $name,
        string $argumentsJson,
        string $callId = 'call_1',
        string $id = 'fc_1',
    ): array {
        return [
            'type' => 'function_call',
            'name' => $name,
            'arguments' => $argumentsJson,
            'call_id' => $callId,
            'id' => $id,
            'status' => 'completed',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function openAiAssistantMessage(string $text): array
    {
        return [
            'type' => 'message',
            'id' => 'msg_test',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => $text,
                    'annotations' => [],
                ],
            ],
        ];
    }
}
