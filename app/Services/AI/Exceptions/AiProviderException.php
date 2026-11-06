<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public const CODE_MISSING_API_KEY = 'missing_api_key';

    public const CODE_UNSUPPORTED_PROVIDER = 'unsupported_provider';

    public const CODE_AUTHENTICATION = 'authentication';

    public const CODE_RATE_LIMIT = 'rate_limit';

    public const CODE_TIMEOUT = 'timeout';

    public const CODE_SERVER_ERROR = 'server_error';

    public const CODE_INVALID_RESPONSE = 'invalid_response';

    public const CODE_PROVIDER_ERROR = 'provider_error';

    public function __construct(
        string $message,
        private readonly string $errorCode = self::CODE_PROVIDER_ERROR,
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
