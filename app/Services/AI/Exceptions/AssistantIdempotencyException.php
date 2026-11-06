<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

final class AssistantIdempotencyException extends RuntimeException
{
    public const CODE_BODY_MISMATCH = 'idempotency_body_mismatch';

    public const CODE_IN_PROGRESS = 'idempotency_in_progress';

    public function __construct(
        string $message,
        public readonly string $errorCode = self::CODE_BODY_MISMATCH,
    ) {
        parent::__construct($message);
    }
}
