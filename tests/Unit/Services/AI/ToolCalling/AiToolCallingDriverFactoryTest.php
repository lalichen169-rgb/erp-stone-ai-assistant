<?php

namespace Tests\Unit\Services\AI\ToolCalling;

use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\ToolCalling\AiToolCallingDriverFactory;
use App\Services\AI\ToolCalling\ClaudeToolCallingDriver;
use App\Services\AI\ToolCalling\OllamaToolCallingDriver;
use App\Services\AI\ToolCalling\OpenAiToolCallingDriver;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AiToolCallingDriverFactoryTest extends TestCase
{
    #[Test]
    public function test_resolves_openai_driver(): void
    {
        Config::set('ai.provider', 'openai');

        $driver = app(AiToolCallingDriverFactory::class)->make();

        $this->assertInstanceOf(OpenAiToolCallingDriver::class, $driver);
    }

    #[Test]
    public function test_resolves_claude_driver_for_claude_and_anthropic_alias(): void
    {
        Config::set('ai.provider', 'claude');
        $this->assertInstanceOf(ClaudeToolCallingDriver::class, app(AiToolCallingDriverFactory::class)->make());

        Config::set('ai.provider', 'anthropic');
        $this->assertInstanceOf(ClaudeToolCallingDriver::class, app(AiToolCallingDriverFactory::class)->make());
    }

    #[Test]
    public function test_resolves_ollama_driver(): void
    {
        Config::set('ai.provider', 'ollama');

        $this->assertInstanceOf(OllamaToolCallingDriver::class, app(AiToolCallingDriverFactory::class)->make());
    }

    #[Test]
    public function test_explicit_provider_argument_overrides_config(): void
    {
        Config::set('ai.provider', 'openai');

        $driver = app(AiToolCallingDriverFactory::class)->make('ollama');

        $this->assertInstanceOf(OllamaToolCallingDriver::class, $driver);
    }

    #[Test]
    public function test_unsupported_provider_throws(): void
    {
        $this->expectException(AiProviderException::class);

        app(AiToolCallingDriverFactory::class)->make('gemini');
    }
}
