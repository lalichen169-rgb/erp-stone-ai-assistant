<?php

namespace Tests\Unit\Services\AI\ToolCalling;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiResponse;
use App\DTOs\AI\AiToolCall;
use App\DTOs\AI\AiToolDefinition;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\ToolCalling\OllamaToolCallingDriver;
use App\Services\AI\Tools\ToolExecutionResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OllamaToolCallingDriverTest extends TestCase
{
    private OllamaToolCallingDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = app(OllamaToolCallingDriver::class);
    }

    #[Test]
    public function test_user_and_assistant_only_history(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Hello'),
            AiMessage::assistant('Hi there'),
            AiMessage::user('Follow up'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);
        $roles = array_column($built->ollamaMessages ?? [], 'role');

        $this->assertSame(['user', 'assistant', 'user'], $roles);
    }

    #[Test]
    public function test_single_historical_tool_round_rebuilt_with_assistant_tool_calls(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Stock in Houston?'),
            AiMessage::toolResult('call_1', ['success' => true, 'data' => ['slabs_on_hand' => 42]], 'checkStock', [
                'material' => 'Quartz',
                'color' => 'Calacatta',
                'warehouse_name' => 'Houston',
            ]),
            AiMessage::assistant('42 slabs available.'),
            AiMessage::user('And in Dallas?'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);
        $messages = $built->ollamaMessages ?? [];

        $this->assertCount(5, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertArrayHasKey('tool_calls', $messages[1]);
        $this->assertSame('checkStock', $messages[1]['tool_calls'][0]['function']['name']);
        $this->assertSame('call_1', $messages[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $messages[2]['role']);
        $this->assertSame('call_1', $messages[2]['tool_call_id']);
        $this->assertSame('checkStock', $messages[2]['tool_name']);
        $this->assertSame('assistant', $messages[3]['role']);
        $this->assertSame('And in Dallas?', $messages[4]['content']);
    }

    #[Test]
    public function test_multiple_historical_tools_in_one_round(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Compare'),
            AiMessage::toolResult('c1', ['success' => true, 'data' => ['a' => 1]], 'checkStock', ['warehouse_name' => 'Houston']),
            AiMessage::toolResult('c2', ['success' => true, 'data' => ['a' => 2]], 'checkStock', ['warehouse_name' => 'Dallas']),
            AiMessage::assistant('Done.'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);
        $assistant = $built->ollamaMessages[1] ?? [];

        $this->assertCount(2, $assistant['tool_calls']);
        $this->assertSame('c1', $assistant['tool_calls'][0]['id']);
        $this->assertSame('c2', $assistant['tool_calls'][1]['id']);
    }

    #[Test]
    public function test_multiple_tool_rounds_in_one_conversation(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Q1'),
            AiMessage::toolResult('a1', ['success' => true], 'checkStock', []),
            AiMessage::assistant('A1'),
            AiMessage::user('Q2'),
            AiMessage::toolResult('a2', ['success' => true], 'findOverduePurchaseOrders', []),
            AiMessage::assistant('A2'),
            AiMessage::user('Q3'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);
        $roles = array_column($built->ollamaMessages ?? [], 'role');

        $this->assertSame(
            ['user', 'assistant', 'tool', 'assistant', 'user', 'assistant', 'tool', 'assistant', 'user'],
            $roles,
        );
    }

    #[Test]
    public function test_missing_tool_call_id_uses_safe_fallback_without_throwing(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Hi'),
            AiMessage::toolResult('unknown', ['success' => false, 'error' => ['message' => 'bad']], 'checkStock', null),
            AiMessage::assistant('Sorry.'),
            AiMessage::user('Again'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);

        $this->assertArrayNotHasKey('tool_call_id', $built->ollamaMessages[2]);
        $this->assertSame('historical_call_0', $built->ollamaMessages[1]['tool_calls'][0]['id']);
    }

    #[Test]
    public function test_malformed_tool_payload_still_maps_content(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('Hi'),
            new AiMessage(
                role: AiMessage::ROLE_TOOL,
                content: 'not-json',
                toolCallId: 'x1',
                toolName: 'checkStock',
                toolPayload: ['raw' => 'not-json'],
            ),
            AiMessage::assistant('OK'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);

        $this->assertStringContainsString('not-json', (string) ($built->ollamaMessages[2]['content'] ?? ''));
    }

    #[Test]
    public function test_regression_live_02_no_exception_on_historical_tool_messages(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('How many 3cm Calacatta slabs do we have in Houston?'),
            AiMessage::toolResult('ollama_call_0', [
                'success' => true,
                'data' => ['slabs_on_hand' => 0],
            ], 'checkStock', [
                'material' => 'Calacatta',
                'warehouse_city' => 'Houston',
            ]),
            AiMessage::assistant('We do not have any in Houston.'),
            AiMessage::user('And in Dallas?'),
        ]);

        $built = $this->driver->buildFirstRequest($request, [new AiToolDefinition('checkStock', 'Stock', [])]);

        $this->assertGreaterThanOrEqual(5, count($built->ollamaMessages ?? []));
        $this->assertSame('And in Dallas?', $built->ollamaMessages[array_key_last($built->ollamaMessages)]['content']);
    }

    #[Test]
    public function test_same_turn_follow_up_still_appends_tool_results(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Stock?'],
        ];
        $original = new AiRequest(messages: [AiMessage::user('Stock?')], ollamaMessages: $history);
        $response = new AiResponse(
            text: '',
            provider: 'ollama',
            model: 'llama3.1:8b',
            providerResponseId: null,
            inputTokens: 1,
            outputTokens: 1,
            totalTokens: 2,
            finishReason: 'stop',
            toolCalls: [new AiToolCall('live_1', 'checkStock', ['warehouse_name' => 'Houston'])],
            stopReason: 'stop',
            ollamaAssistantMessage: [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'live_1',
                        'function' => ['name' => 'checkStock', 'arguments' => ['warehouse_name' => 'Houston']],
                    ],
                ],
            ],
            ollamaConversationExtension: $history,
        );

        $followUp = $this->driver->buildFollowUpRequest(
            $original,
            $response,
            ['live_1' => new ToolExecutionResult(success: true, data: ['slabs_on_hand' => 42])],
            [],
        );

        $toolRows = array_values(array_filter(
            $followUp->ollamaMessages ?? [],
            fn (array $m): bool => ($m['role'] ?? '') === 'tool',
        ));

        $this->assertCount(1, $toolRows);
        $this->assertSame('live_1', $toolRows[0]['tool_call_id']);
    }

    #[Test]
    public function test_no_duplicate_messages_in_output(): void
    {
        $request = new AiRequest(messages: [
            AiMessage::user('One'),
            AiMessage::toolResult('id1', ['success' => true], 'checkStock', []),
            AiMessage::assistant('Two'),
        ]);

        $built = $this->driver->buildFirstRequest($request, []);

        $this->assertCount(4, $built->ollamaMessages);
    }

    #[Test]
    public function test_system_instruction_prepended_once(): void
    {
        $request = new AiRequest(
            messages: [AiMessage::user('Hi')],
            systemInstruction: 'You are ERP assistant.',
        );

        $built = $this->driver->buildFirstRequest($request, []);

        $this->assertSame('system', $built->ollamaMessages[0]['role']);
        $this->assertSame('You are ERP assistant.', $built->ollamaMessages[0]['content']);
    }
}
