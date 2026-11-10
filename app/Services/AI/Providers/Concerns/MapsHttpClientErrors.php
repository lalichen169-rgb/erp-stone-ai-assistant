<?php

namespace App\Services\AI\Providers\Concerns;

use App\Services\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

trait MapsHttpClientErrors
{
    protected function mapHttpClientException(\Throwable $exception, string $providerLabel): AiProviderException
    {
        if ($exception instanceof ConnectionException) {
            return new AiProviderException(
                "{$providerLabel} request timed out or could not connect.",
                AiProviderException::CODE_TIMEOUT,
                previous: $exception
            );
        }

        if ($exception instanceof RequestException) {
            return $this->mapHttpResponseError($exception->response, $providerLabel);
        }

        return new AiProviderException(
            "{$providerLabel} request failed.",
            AiProviderException::CODE_PROVIDER_ERROR,
            previous: $exception
        );
    }

    protected function mapHttpResponseError(Response $response, string $providerLabel): AiProviderException
    {
        $status = $response->status();
        $message = (string) ($response->json('error.message') ?? $response->json('message') ?? "{$providerLabel} request failed.");

        $message = $this->sanitizeProviderMessage($message);

        return match (true) {
            $status === 401 || $status === 403 => new AiProviderException(
                "{$providerLabel} authentication failed.",
                AiProviderException::CODE_AUTHENTICATION,
                $status
            ),
            $status === 429 => new AiProviderException(
                "{$providerLabel} rate limit exceeded.",
                AiProviderException::CODE_RATE_LIMIT,
                $status
            ),
            $status >= 500 => new AiProviderException(
                "{$providerLabel} service is temporarily unavailable.",
                AiProviderException::CODE_SERVER_ERROR,
                $status
            ),
            default => new AiProviderException(
                $message,
                AiProviderException::CODE_PROVIDER_ERROR,
                $status
            ),
        };
    }

    protected function sanitizeProviderMessage(string $message): string
    {
        $message = preg_replace('/sk-[A-Za-z0-9_-]+/', '[redacted]', $message) ?? $message;

        return preg_replace('/Bearer\s+\S+/', 'Bearer [redacted]', $message) ?? $message;
    }
}
