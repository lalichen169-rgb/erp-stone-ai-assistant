<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\ClaudeProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeProviderTest extends TestCase
{
    public function test_claude_provider_maps_messages_response(): void
    {
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_123',
                'model' => 'claude-test-model',
                'stop_reason' => 'end_turn',
                'content' => [
                    ['type' => 'text', 'text' => 'Stone ERP AI connection successful.'],
                ],
                'usage' => [
                    'input_tokens' => 11,
                    'output_tokens' => 7,
                ],
            ], 200),
        ]);

        $response = app(ClaudeProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hello')],
            systemInstruction: 'Be concise.',
        ));

        $this->assertSame('Stone ERP AI connection successful.', $response->text);
        $this->assertSame('claude', $response->provider);
        $this->assertSame(11, $response->inputTokens);
        $this->assertSame(7, $response->outputTokens);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && ($body['system'] ?? '') === 'Be concise.'
                && ($body['messages'][0]['content'] ?? '') === 'Hello';
        });
    }

    public function test_missing_anthropic_api_key_fails_clearly(): void
    {
        Config::set('ai.claude.api_key', '');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('Anthropic API key is not configured');

        app(ClaudeProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_claude_authentication_errors_are_mapped_safely(): void
    {
        Config::set('ai.claude.api_key', 'testing-anthropic-key');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ], 401),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('authentication failed');

        app(ClaudeProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_claude_provider_maps_tool_use_blocks_and_preserves_conversation_extension(): void
    {
        Config::set('ai.claude.api_key', 'testing-anthropic-key');
        Config::set('ai.claude.model', 'claude-test-model');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_tool',
                'model' => 'claude-test-model',
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_xyz',
                        'name' => 'checkStock',
                        'input' => ['warehouse_name' => 'Houston'],
                    ],
                ],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ], 200),
        ]);

        $prior = [
            ['role' => 'user', 'content' => 'Hi'],
        ];

        $response = app(ClaudeProvider::class)->complete(new AiRequest(
            messages: [],
            systemInstruction: 'Sys',
            claudeMessages: $prior,
        ));

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('toolu_xyz', $response->toolCalls[0]->id);
        $this->assertSame('checkStock', $response->toolCalls[0]->name);
        $this->assertSame($prior, $response->claudeConversationExtension);
    }
}
