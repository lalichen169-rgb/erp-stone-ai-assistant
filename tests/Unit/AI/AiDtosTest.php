<?php

namespace Tests\Unit\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiToolDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AiDtosTest extends TestCase
{
    public function test_ai_message_requires_role_and_content(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiMessage('', 'hello');
    }

    public function test_ai_request_requires_messages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiRequest([]);
    }

    public function test_ai_request_allows_openai_continuation_without_initial_messages(): void
    {
        $request = new AiRequest(
            messages: [],
            openaiPreviousResponseId: 'resp_prev',
            openaiToolOutputInput: [
                ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{}'],
            ],
        );

        $this->assertSame([], $request->messages);
    }

    public function test_ai_request_accepts_tool_definitions_without_execution(): void
    {
        $request = new AiRequest(
            messages: [AiMessage::user('Find stock')],
            tools: [
                new AiToolDefinition(
                    name: 'lookup_stock',
                    description: 'Find slab counts',
                    parameters: [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                        ],
                    ],
                ),
            ],
        );

        $this->assertCount(1, $request->tools);
        $this->assertSame('lookup_stock', $request->tools[0]->name);
    }
}
