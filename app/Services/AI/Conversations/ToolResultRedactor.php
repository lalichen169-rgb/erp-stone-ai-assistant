<?php

namespace App\Services\AI\Conversations;

use App\Auth\ErpAbilities;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutionResult;
use Illuminate\Support\Facades\Gate;

/**
 * Removes sensitive fields before persisting tool results in conversation logs.
 */
final class ToolResultRedactor
{
    /**
     * @var list<string>
     */
    private const BANKING_RESULT_KEYS = [
        'bank_details',
        'bank_account',
        'iban',
        'swift',
        'routing',
        'account_number',
    ];

    /**
     * @var list<string>
     */
    private const ALWAYS_FORBIDDEN_RESULT_KEYS = [
        'password',
        'token',
        'api_key',
    ];

    public function redactExecutionResult(ToolExecutionResult $result, ?User $viewer = null): ToolExecutionResult
    {
        if (! $result->success) {
            return $result;
        }

        if ($result->data === null) {
            return $result;
        }

        $data = $this->stripKeysRecursive($result->data, $viewer);

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
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripKeysRecursive(array $data, ?User $viewer): array
    {
        $forbidden = self::ALWAYS_FORBIDDEN_RESULT_KEYS;
        if ($viewer === null || ! Gate::forUser($viewer)->allows(ErpAbilities::VIEW_SUPPLIER_BANK_DETAILS)) {
            $forbidden = array_merge($forbidden, self::BANKING_RESULT_KEYS);
        }

        foreach ($forbidden as $key) {
            unset($data[$key]);
        }

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->stripKeysRecursive($v, $viewer);
            }
        }

        return $data;
    }
}
