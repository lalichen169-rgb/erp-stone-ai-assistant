<?php

namespace App\Console\Commands;

use App\DTOs\AI\AiMessage;
use App\DTOs\AI\AiRequest;
use App\Services\AI\AiAgentService;
use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Console\Command;

class AiVerifyConnectionCommand extends Command
{
    protected $signature = 'ai:verify-connection';

    protected $description = 'Send one test prompt through AiAgentService to verify the configured AI provider (requires a local API key).';

    public function handle(AiAgentService $aiAgentService): int
    {
        $provider = (string) config('ai.provider');

        $this->info('Provider: '.$provider);

        try {
            $response = $aiAgentService->complete(new AiRequest(
                messages: [
                    AiMessage::user('Reply with exactly: Stone ERP AI connection successful.'),
                ],
            ));
        } catch (AiProviderException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Model: '.$response->model);
        $this->line('Response ID: '.($response->providerResponseId ?? 'n/a'));
        $this->line('Text: '.$response->text);

        if ($response->inputTokens !== null || $response->outputTokens !== null) {
            $this->line(sprintf(
                'Tokens: input=%s output=%s total=%s',
                $response->inputTokens ?? 'n/a',
                $response->outputTokens ?? 'n/a',
                $response->totalTokens ?? 'n/a'
            ));
        } else {
            $this->line('Tokens: not reported by provider');
        }

        return self::SUCCESS;
    }
}
