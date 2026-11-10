<?php

namespace Tests\Unit\Services\AI\ToolCalling;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\DTOs\AI\AiToolCall;
use App\DTOs\AI\AiToolDefinition;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\ToolCalling\ClaudeToolCallingDriver;
use App\Services\AI\Tools\ToolExecutionResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudeToolCallingDriverTest extends TestCase
{
    private ClaudeToolCallingDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = app(ClaudeToolCallingDriver::class);
    }

    #[Test]
    public function test_user_and_assistant_history_without_tools(): void
    {
        $built = $this->driver->buildFirstRequest(new AiRequest(messages: [
            AiMessage::user('Hello'),
            AiMessage::assistant('Hi'),
            AiMessage::user('Follow up'),
        ]), []);

        $this->assertCount(3, $built->claudeMessages);
        $this->assertSame('user', $built->claudeMessages[0]['role']);
    }

    #[Test]
    public function test_historical_tool_messages_rejected_on_initial_request(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Prior'),
            AiMessage::toolResult('toolu_1', ['success' => true], 'checkStock'),
            AiMessage::user('Follow up'),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('cannot include tool result messages');

        $this->driver->buildFirstRequest($request, []);
    }

    #[Test]
    public function test_same_turn_follow_up_still_appends_tool_results(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Stock?'],
        ];
        $original = new AiRequest(messages: [AiMessage::user('Stock?')], claudeMessages: $history);
        $response = new AiResponse(
            text: '',
            provider: 'claude',
            model: 'claude-test',
            providerResponseId: 'msg_1',
            inputTokens: 1,
            outputTokens: 1,
            totalTokens: 2,
            finishReason: null,
            toolCalls: [new AiToolCall('toolu_live', 'checkStock', ['warehouse_name' => 'Houston'])],
            stopReason: 'tool_use',
            claudeAssistantBlocks: [[
                'type' => 'tool_use',
                'id' => 'toolu_live',
                'name' => 'checkStock',
                'input' => ['warehouse_name' => 'Houston'],
            ]],
            claudeConversationExtension: $history,
        );

        $followUp = $this->driver->buildFollowUpRequest(
            $original,
            $response,
            ['toolu_live' => new ToolExecutionResult(success: true, data: ['slabs_on_hand' => 42])],
            [],
        );

        $resultBlocks = [];
        foreach ($followUp->claudeMessages ?? [] as $row) {
            if (($row['role'] ?? '') === 'user' && is_array($row['content'] ?? null)) {
                foreach ($row['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $resultBlocks[] = $block;
                    }
                }
            }
        }

        $this->assertCount(1, $resultBlocks);
        $this->assertSame('toolu_live', $resultBlocks[0]['tool_use_id']);
    }

}
