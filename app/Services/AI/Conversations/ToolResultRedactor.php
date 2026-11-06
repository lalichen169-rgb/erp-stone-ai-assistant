<?php

namespace App\Services\AI\Conversations;

use App\Services\AI\Tools\ToolExecutionResult;

/**
 * Removes sensitive fields before persisting tool results in conversation logs.
 */
final class ToolResultRedactor
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_RESULT_KEYS = [
        'bank_details',
        'bank_account',
        'iban',
        'swift',
        'routing',
        'account_number',
        'password',
        'token',
        'api_key',
    ];

    public function redactExecutionResult(ToolExecutionResult $result): ToolExecutionResult
    {
        if (! $result->success) {
            return $result;
        }

        if ($result->data === null) {
            return $result;
        }

        $data = $this->stripKeysRecursive($result->data);

        return new ToolExecutionResult(
            success: $result->success,
            data: $data,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripKeysRecursive(array $data): array
    {
        foreach (self::FORBIDDEN_RESULT_KEYS as $key) {
            unset($data[$key]);
        }

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->stripKeysRecursive($v);
            }
        }

        return $data;
    }
}
