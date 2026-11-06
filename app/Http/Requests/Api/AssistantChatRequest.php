<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssistantChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('ai.assistant.max_message_chars', 4000);

        return [
            'conversation_id' => ['sometimes', 'nullable', 'uuid'],
            'message' => ['required', 'string', 'min:1', 'max:'.$max],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['conversation_id', 'message'];
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add($key, 'This field is not allowed.');
                }
            }

            $message = $this->input('message');
            if (! is_string($message)) {
                return;
            }

            if (trim($message) === '') {
                $validator->errors()->add('message', 'The message field must contain non-whitespace text.');
            }

            $idem = $this->headers->get('Idempotency-Key');
            if (is_string($idem) && trim($idem) !== '' && empty($this->input('conversation_id'))) {
                $validator->errors()->add(
                    'conversation_id',
                    'conversation_id is required when Idempotency-Key is sent.',
                );
            }
        });
    }
}
