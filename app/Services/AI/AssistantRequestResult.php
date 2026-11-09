<?php

namespace App\Services\AI;

/**
 * Outcome of POST /api/assistant orchestration (HTTP mapping lives in the controller).
 */
final readonly class AssistantRequestResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public bool $success,
        public int $httpStatus,
        public string $errorCode,
        public string $message,
        public ?array $data = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return [
            'success' => $this->success,
            'httpStatus' => $this->httpStatus,
            'errorCode' => $this->errorCode,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStorageArray(array $stored): self
    {
        return new self(
            success: (bool) ($stored['success'] ?? false),
            httpStatus: (int) ($stored['httpStatus'] ?? 200),
            errorCode: (string) ($stored['errorCode'] ?? 'ok'),
            message: (string) ($stored['message'] ?? ''),
            data: isset($stored['data']) && is_array($stored['data']) ? $stored['data'] : null,
        );
    }
}
