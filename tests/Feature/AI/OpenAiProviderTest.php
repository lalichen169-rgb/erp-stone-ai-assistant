<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\DTOs\AI\AiToolDefinition;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Config;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ResponsesContract;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\AiTestResponses;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_openai_provider_builds_responses_request_and_maps_text_and_usage(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');
        Config::set('ai.openai.model', 'gpt-4o-mini');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'model' => 'gpt-4o-mini',
                'output' => [
                    AiTestResponses::openAiAssistantMessage('Stone ERP AI connection successful.'),
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'input_tokens_details' => ['cached_tokens' => 0],
                    'output_tokens' => 5,
                    'output_tokens_details' => ['reasoning_tokens' => 0],
                    'total_tokens' => 15,
                ],
            ], strategy: OverrideStrategy::Replace),
        ]);

        $provider = new OpenAiProvider($client);

        $response = $provider->complete(new AiRequest(
            messages: [AiMessage::user('Reply with exactly: Stone ERP AI connection successful.')],
            systemInstruction: 'You are a test assistant.',
        ));

        $this->assertSame('Stone ERP AI connection successful.', $response->text);
        $this->assertSame('openai', $response->provider);
        $this->assertSame(10, $response->inputTokens);
        $this->assertSame(5, $response->outputTokens);

        OpenAI::assertSent(Responses::class, function (string $method, array $parameters): bool {
            if ($method !== 'create') {
                return false;
            }

            return ($parameters['model'] ?? null) === 'gpt-4o-mini'
                && ($parameters['instructions'] ?? null) === 'You are a test assistant.'
                && ($parameters['input'][0]['content'][0]['text'] ?? null) === 'Reply with exactly: Stone ERP AI connection successful.';
        });
    }

    public function test_openai_provider_handles_missing_usage_metadata(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'usage' => null,
                'output' => [
                    AiTestResponses::openAiAssistantMessage('OK'),
                ],
            ], strategy: OverrideStrategy::Replace),
        ]);

        $response = (new OpenAiProvider($client))->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));

        $this->assertSame('OK', $response->text);
        $this->assertNull($response->inputTokens);
        $this->assertNull($response->outputTokens);
    }

    public function test_openai_provider_maps_api_errors_to_safe_exceptions(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $psrResponse = $this->createMock(ResponseInterface::class);
        $psrResponse->method('getStatusCode')->willReturn(401);

        $responses = $this->createMock(ResponsesContract::class);
        $responses->method('create')->willThrowException(
            new ErrorException(['message' => 'Incorrect API key provided: sk-secret'], $psrResponse)
        );

        $client = $this->createMock(ClientContract::class);
        $client->method('responses')->willReturn($responses);

        $provider = new OpenAiProvider($client);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('authentication failed');

        $provider->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_missing_openai_api_key_fails_clearly(): void
    {
        Config::set('ai.openai.api_key', '');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured');

        app(OpenAiProvider::class)->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
        ));
    }

    public function test_request_can_include_tool_definitions(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'output' => [
                    AiTestResponses::openAiAssistantMessage('Ready'),
                ],
            ], strategy: OverrideStrategy::Replace),
        ]);

        (new OpenAiProvider($client))->complete(new AiRequest(
            messages: [AiMessage::user('Hi')],
            tools: [
                new AiToolDefinition('lookup_stock', 'Find stock', [
                    'type' => 'object',
                    'properties' => ['sku' => ['type' => 'string']],
                ]),
            ],
        ));

        OpenAI::assertSent(Responses::class, function (string $method, array $parameters): bool {
            return $method === 'create'
                && ($parameters['tools'][0]['name'] ?? null) === 'lookup_stock';
        });
    }

    public function test_openai_tool_continuation_sends_previous_response_id_and_function_outputs(): void
    {
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'id' => 'resp_after_tools',
                'output' => [
                    AiTestResponses::openAiAssistantMessage('Thanks for the tool output.'),
                ],
            ], strategy: OverrideStrategy::Replace),
        ]);

        (new OpenAiProvider($client))->complete(new AiRequest(
            messages: [],
            openaiPreviousResponseId: 'resp_prev',
            openaiToolOutputInput: [
                [
                    'type' => 'function_call_output',
                    'call_id' => 'call_abc',
                    'output' => '{"success":true}',
                ],
            ],
        ));

        OpenAI::assertSent(Responses::class, function (string $method, array $parameters): bool {
            if ($method !== 'create') {
                return false;
            }

            $input = $parameters['input'] ?? [];

            return ($parameters['previous_response_id'] ?? null) === 'resp_prev'
                && isset($input[0]['call_id'])
                && $input[0]['call_id'] === 'call_abc'
                && ($input[0]['type'] ?? null) === 'function_call_output'
                && ! array_key_exists('id', $input[0]);
        });
    }
}
