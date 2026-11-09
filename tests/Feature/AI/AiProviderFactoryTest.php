<?php

namespace Tests\Feature\AI;

use App\Contracts\AiProviderInterface;
use App\Services\AI\AiProviderFactory;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AiProviderFactoryTest extends TestCase
{
    public function test_factory_resolves_openai_provider(): void
    {
        Config::set('ai.provider', 'openai');

        $provider = app(AiProviderFactory::class)->make();

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
        $this->assertInstanceOf(AiProviderInterface::class, $provider);
        $this->assertSame('openai', $provider->name());
    }

    public function test_factory_resolves_claude_provider(): void
    {
        Config::set('ai.provider', 'claude');

        $provider = app(AiProviderFactory::class)->make();

        $this->assertInstanceOf(ClaudeProvider::class, $provider);
        $this->assertSame('claude', $provider->name());
    }

    public function test_factory_resolves_ollama_provider(): void
    {
        Config::set('ai.provider', 'ollama');

        $provider = app(AiProviderFactory::class)->make();

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->name());
    }

    public function test_factory_rejects_unimplemented_providers(): void
    {
        Config::set('ai.provider', 'gemini');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('not implemented');

        app(AiProviderFactory::class)->make();
    }
}
