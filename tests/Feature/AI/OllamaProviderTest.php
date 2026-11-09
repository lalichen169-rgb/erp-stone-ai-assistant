<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiToolDefinition;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\OllamaProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaProviderTest extends TestCase
{
    private const BASE = 'http://ollama.test';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.ollama.base_url', self::BASE);
        Config::set('ai.ollama.model', 'llama3.1:8b');
        Config::set('ai.ollama.request_timeout', 120);
        Config::set('ai.ollama.connect_timeout', 10);
        Config::set('ai.ollama.keep_alive', '5m');
        Config::set('ai.ollama.chat_options', ['temperature' => 0.1]);

        Http::preventStrayRequests();
    }

    public function test_ollama_posts_to_api_chat_with_model_and_stream_false(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from Ollama.',
                ],
                'done' => true,
            ], 200),
        ]);

        $response = app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));

        $this->assertSame('Hello from Ollama.', $response->text);
        $this->assertSame('ollama', $response->provider);
        $this->assertSame('llama3.1:8b', $response->model);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === self::BASE.'/api/chat'
                && ($body['model'] ?? '') === 'llama3.1:8b'
                && ($body['stream'] ?? null) === false
                && ($body['keep_alive'] ?? '') === '5m'
                && ($body['options']['temperature'] ?? null) === 0.1;
        });
    }

    public function test_system_and_user_messages_are_mapped(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'OK'],
            ], 200),
        ]);

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('User question')],
            systemInstruction: 'ERP assistant.',
        ));

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];

            return ($messages[0]['role'] ?? '') === 'system'
                && ($messages[0]['content'] ?? '') === 'ERP assistant.'
                && ($messages[1]['role'] ?? '') === 'user'
                && ($messages[1]['content'] ?? '') === 'User question';
        });
    }

    public function test_tool_schema_mapping(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => ''],
            ], 200),
        ]);

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Check stock')],
            tools: [
                new AiToolDefinition(
                    name: 'checkStock',
                    description: 'Check inventory',
                    parameters: [
                        'type' => 'object',
                        'properties' => ['warehouse_name' => ['type' => 'string']],
                    ],
                ),
            ],
        ));

        Http::assertSent(function ($request) {
            $tools = $request->data()['tools'] ?? [];

            return ($tools[0]['type'] ?? '') === 'function'
                && ($tools[0]['function']['name'] ?? '') === 'checkStock'
                && ($tools[0]['function']['description'] ?? '') === 'Check inventory';
        });
    }

    public function test_single_tool_call_response(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_abc',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => ['warehouse_name' => 'Houston'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Stock?')],
        ));

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('call_abc', $response->toolCalls[0]->id);
        $this->assertSame('checkStock', $response->toolCalls[0]->name);
        $this->assertSame('Houston', $response->toolCalls[0]->arguments['warehouse_name']);
    }

    public function test_multiple_tool_calls_and_json_string_arguments(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Working.',
                    'tool_calls' => [
                        [
                            'id' => 'c1',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => json_encode(['warehouse_name' => 'Houston'], JSON_THROW_ON_ERROR),
                            ],
                        ],
                        [
                            'id' => 'c2',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => ['warehouse_name' => 'Dallas'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Compare')],
        ));

        $this->assertSame('Working.', $response->text);
        $this->assertCount(2, $response->toolCalls);
    }

    public function test_ollama_messages_bypass_normalized_messages(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'Done'],
            ], 200),
        ]);

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [],
            ollamaMessages: [
                ['role' => 'user', 'content' => 'Prior'],
                ['role' => 'assistant', 'content' => 'Ack'],
            ],
        ));

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];

            return count($messages) === 2
                && ($messages[1]['content'] ?? '') === 'Ack';
        });
    }

    public function test_preserves_conversation_extension_on_tool_response(): void
    {
        $prior = [
            ['role' => 'user', 'content' => 'Hi'],
        ];

        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_x',
                            'function' => [
                                'name' => 'checkStock',
                                'arguments' => [],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = app(OllamaProvider::class)->complete(new AiRequest(
            messages: [],
            ollamaMessages: $prior,
        ));

        $this->assertSame($prior, $response->ollamaConversationExtension);
        $this->assertIsArray($response->ollamaAssistantMessage);
    }

    public function test_usage_metadata_when_available(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'prompt_eval_count' => 12,
                'eval_count' => 8,
                'message' => ['role' => 'assistant', 'content' => 'OK'],
            ], 200),
        ]);

        $response = app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));

        $this->assertSame(12, $response->inputTokens);
        $this->assertSame(8, $response->outputTokens);
        $this->assertSame(20, $response->totalTokens);
    }

    public function test_missing_usage_metadata_is_null(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => ['role' => 'assistant', 'content' => 'OK'],
            ], 200),
        ]);

        $response = app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));

        $this->assertNull($response->inputTokens);
        $this->assertNull($response->outputTokens);
        $this->assertNull($response->totalTokens);
    }

    public function test_empty_message_object_fails(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response(['model' => 'llama3.1:8b'], 200),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('without a message');

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_malformed_json_body_fails(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response('not-json', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->expectException(AiProviderException::class);

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_invalid_tool_call_structure(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[]],
                ],
            ], 200),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('function object');

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_http_4xx_mapping(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response(['error' => 'not found'], 404),
        ]);

        try {
            app(OllamaProvider::class)->complete(new AiRequest(
                messages: [AiMessage::user('Hi')],
            ));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::CODE_PROVIDER_ERROR, $exception->errorCode());
            $this->assertSame(404, $exception->httpStatus());
        }
    }

    public function test_http_5xx_mapping(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response(['error' => 'server broke'], 500),
        ]);

        try {
            app(OllamaProvider::class)->complete(new AiRequest(
                messages: [AiMessage::user('Hi')],
            ));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::CODE_SERVER_ERROR, $exception->errorCode());
            $this->assertSame(500, $exception->httpStatus());
        }
    }

    public function test_connection_failure_maps_to_timeout_code(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => function (): void {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        try {
            app(OllamaProvider::class)->complete(new AiRequest(
                messages: [AiMessage::user('Hi')],
            ));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::CODE_TIMEOUT, $exception->errorCode());
        }
    }

    public function test_error_field_in_success_body(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'error' => 'model "missing" not found',
            ], 200),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('not found');

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_missing_base_url_configuration(): void
    {
        Config::set('ai.ollama.base_url', '');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('base URL');

        app(OllamaProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_errors_do_not_leak_secrets(): void
    {
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'error' => 'failed with sk-secretkey1234567890',
            ], 200),
        ]);

        try {
            app(OllamaProvider::class)->complete(new AiRequest(
                messages: [AiMessage::user('Hi')],
            ));
        } catch (AiProviderException $exception) {
            $this->assertStringNotContainsString('sk-secretkey', $exception->getMessage());
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
        }
    }
}
