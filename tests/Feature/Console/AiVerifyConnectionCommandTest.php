<?php

namespace Tests\Feature\Console;

use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ResponsesContract;
use OpenAI\Exceptions\ErrorException;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class AiVerifyConnectionCommandTest extends TestCase
{
    public function test_verify_connection_prints_safe_diagnostics_on_openai_auth_failure(): void
    {
        Config::set('ai.provider', 'openai');
        Config::set('ai.openai.api_key', 'test-key-not-for-production');

        $psrResponse = $this->createMock(ResponseInterface::class);
        $psrResponse->method('getStatusCode')->willReturn(401);
        $psrResponse->method('getHeaderLine')
            ->willReturnCallback(static function (string $name): string {
                return match (strtolower($name)) {
                    'x-request-id' => 'req_verify_cli_01',
                    default => '',
                };
            });

        $responses = $this->createMock(ResponsesContract::class);
        $responses->method('create')->willThrowException(
            new ErrorException([
                'message' => 'Incorrect API key provided: sk-badkey',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ], $psrResponse)
        );

        $client = $this->createMock(ClientContract::class);
        $client->method('responses')->willReturn($responses);

        $this->app->instance(ClientContract::class, $client);

        $exitCode = Artisan::call('ai:verify-connection');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Provider: openai', $output);
        $this->assertStringContainsString('OpenAI authentication failed', $output);
        $this->assertStringContainsString('HTTP status: 401', $output);
        $this->assertStringContainsString('App error code: '.AiProviderException::CODE_AUTHENTICATION, $output);
        $this->assertStringNotContainsString('sk-badkey', $output);
        $this->assertStringNotContainsString('Bearer ', $output);
    }
}
