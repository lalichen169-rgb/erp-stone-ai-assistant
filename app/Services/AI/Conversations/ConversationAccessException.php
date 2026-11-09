<?php

namespace App\Services\AI\Conversations;

use RuntimeException;

final class ConversationAccessException extends RuntimeException
{
    public const CODE_NOT_FOUND = 'conversation_not_found';

    public const CODE_FORBIDDEN = 'conversation_forbidden';

    public const CODE_INACTIVE = 'conversation_inactive';

    public function __construct(
        string $message,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }
}
