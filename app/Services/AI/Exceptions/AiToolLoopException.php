<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

final class AiToolLoopException extends RuntimeException
{
    public const CODE_MAX_ITERATIONS = 'max_tool_iterations';

    public const CODE_MAX_TOOL_CALLS = 'max_tool_calls';

    public const CODE_REPEATED_SAME_CALLS = 'repeated_identical_tool_calls';

    public const CODE_INCOMPLETE = 'incomplete';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
