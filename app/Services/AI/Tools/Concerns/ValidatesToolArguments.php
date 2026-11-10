<?php

namespace App\Services\AI\Tools\Concerns;

use App\Services\AI\Tools\ToolExecutionResult;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait ValidatesToolArguments
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|ToolExecutionResult
     */
    protected function validateOrError(array $arguments, array $rules, array $messages = []): array|ToolExecutionResult
    {
        try {
            return Validator::make($arguments, $rules, $messages)->validate();
        } catch (ValidationException $exception) {
            $first = $exception->validator->errors()->first();

            return new ToolExecutionResult(
                success: false,
                errorCode: 'validation_error',
                errorMessage: $first ?: 'Invalid arguments.',
            );
        }
    }
}
