<?php

namespace App\Services\AI\Tools;

use App\DTOs\AI\AiToolCall;
use App\DTOs\AI\AiToolDefinition;
use App\Services\AI\Tools\Contracts\AiToolInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class ToolExecutor
{
    /**
     * Keys that must never be honored from model-supplied JSON (server context only).
     *
     * @var list<string>
     */
    private const FORBIDDEN_ARGUMENT_KEYS = [
        'user_id',
        'userId',
        'role',
        'abilities',
        'ability',
        'email',
        'password',
        'token',
        'api_key',
        'conversation_key',
        'conversationKey',
        'idempotency_key',
        'idempotencyKey',
        'session_id',
    ];

    public function __construct(private readonly ToolRegistry $registry) {}

    public function execute(ToolExecutionContext $context, AiToolCall $call): ToolExecutionResult
    {
        $normalized = $this->normalizeArguments($call);

        if ($normalized instanceof ToolExecutionResult) {
            return $normalized;
        }

        $stripped = $this->stripForbiddenKeys($normalized);

        if ($stripped === null) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'forbidden_arguments',
                errorMessage: 'Request contained disallowed argument keys.',
            );
        }

        $tool = $this->registry->get($call->name);

        if ($tool === null) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'unknown_tool',
                errorMessage: 'Unknown tool name.',
            );
        }

        if (! $tool->allowedFor($context->user)) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'permission_denied',
                errorMessage: 'You are not allowed to use this tool.',
            );
        }

        $schemaKeys = $this->validateArgumentsAgainstToolSchema($stripped, $tool->definition());
        if ($schemaKeys instanceof ToolExecutionResult) {
            return $schemaKeys;
        }

        try {
            return $tool->execute($context, $stripped);
        } catch (AuthorizationException) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'permission_denied',
                errorMessage: 'You are not allowed to perform this operation.',
            );
        } catch (Throwable) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'internal_error',
                errorMessage: 'Tool execution failed.',
            );
        }
    }

    /**
     * OpenAI may surface invalid JSON as a single `raw` string; reject safely.
     *
     * @return array<string, mixed>|ToolExecutionResult
     */
    private function normalizeArguments(AiToolCall $call): array|ToolExecutionResult
    {
        $arguments = $call->arguments;

        if (isset($arguments['raw']) && count($arguments) === 1 && is_string($arguments['raw'])) {
            return new ToolExecutionResult(
                success: false,
                errorCode: 'invalid_arguments',
                errorMessage: 'Tool arguments were not valid JSON.',
            );
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null  Null if forbidden keys were present
     */
    private function stripForbiddenKeys(array $arguments): ?array
    {
        foreach (array_keys($arguments) as $key) {
            if (in_array($key, self::FORBIDDEN_ARGUMENT_KEYS, true)) {
                return null;
            }
        }

        return $arguments;
    }

    /**
     * Reject root-level keys not declared in the tool's JSON Schema `properties` when the schema
     * is a closed object (additionalProperties false or omitted with an explicit property list).
     *
     * @param  array<string, mixed>  $arguments
     */
    private function validateArgumentsAgainstToolSchema(array $arguments, AiToolDefinition $definition): ?ToolExecutionResult
    {
        $schema = $definition->parameters;

        if (! is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return null;
        }

        $additional = $schema['additionalProperties'] ?? null;

        // Open object: provider compatibility — do not reject unknown keys here.
        if ($additional === true) {
            return null;
        }

        $properties = $schema['properties'] ?? [];
        if (! is_array($properties) || $properties === []) {
            return null;
        }

        $allowed = array_keys($properties);

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $allowed, true)) {
                return new ToolExecutionResult(
                    success: false,
                    errorCode: 'validation_error',
                    errorMessage: 'Tool arguments contained unknown or disallowed keys.',
                );
            }
        }

        return null;
    }
}
