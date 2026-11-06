<?php

namespace Tests\Feature\AI;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiProviderFactory;
use App\Contracts\AiProviderInterface;
use App\DTOs\AI\AiResponse;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use Tests\Support\AiTestResponses;
use Tests\TestCase;

class AiAgentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        OpenAI::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_ai_agent_service_delegates_through_provider_interface(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'testing-openai-key-not-for-production');

        $client = OpenAI::fake([
            CreateResponse::fake([
                'output' => [
                    AiTestResponses::openAiAssistantMessage('Delegated response'),
                ],
            ], strategy: \OpenAI\Testing\Enums\OverrideStrategy::Replace),
        ]);

        $this->app->instance(\OpenAI\Contracts\ClientContract::class, $client);
        $this->app->instance(\App\Services\AI\Providers\OpenAiProvider::class, new \App\Services\AI\Providers\OpenAiProvider($client));

        $response = app(AiAgentService::class)->complete(new AiRequest(
            messages: [AiMessage::user('Ping')],
        ));

        $this->assertSame('Delegated response', $response->text);
        $this->assertSame('openai', $response->provider);
    }

    public function test_ai_agent_service_does_not_reference_openai_sdk_classes(): void
    {
        $provider = new class implements AiProviderInterface
        {
            public function name(): string
            {
                return 'stub';
            }

            public function complete(AiRequest $request): AiResponse
            {
                return new AiResponse(
                    text: 'stubbed',
                    provider: 'stub',
                    model: 'stub-model',
                );
            }
        };

        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new AiAgentService($factory);

        $response = $service->complete(new AiRequest(
            messages: [AiMessage::user('Hello')],
        ));

        $this->assertSame('stubbed', $response->text);
    }
}
