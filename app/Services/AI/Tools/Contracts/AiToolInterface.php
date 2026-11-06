<?php

namespace App\Services\AI\Tools\Contracts;

use App\DTOs\AI\AiToolDefinition;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\AI\Tools\ToolExecutionResult;

interface AiToolInterface
{
    public function name(): string;

    public function definition(): AiToolDefinition;

    /**
     * Whether this tool may be offered to the model for this user.
     */
    public function allowedFor(User $user): bool;

    /**
     * @param  array<string, mixed>  $arguments  Validated, allowlisted arguments only
     */
    public function execute(ToolExecutionContext $context, array $arguments): ToolExecutionResult;
}
