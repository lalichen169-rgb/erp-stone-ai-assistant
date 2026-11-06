<?php

namespace App\Services\AI\Tools;

final readonly class ToolExecutionResult
{
    /**
     * @param  array<string, mixed>|null  $data  JSON-serializable payload for the assistant
     */
    public function __construct(
        public bool $success,
        public ?array $data = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'success' => $this->success,
        ];

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        if ($this->errorCode !== null) {
            $payload['error'] = [
                'code' => $this->errorCode,
                'message' => $this->errorMessage ?? 'An error occurred.',
            ];
        }

        return $payload;
    }
}
