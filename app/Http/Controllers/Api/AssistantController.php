<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AssistantChatRequest;
use App\Services\AI\AssistantRequestResult;
use App\Services\AI\AssistantRequestService;
use App\Services\AI\Conversations\ConversationAccessException;
use App\Services\AI\Exceptions\AssistantIdempotencyException;
use Illuminate\Http\JsonResponse;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantRequestService $assistantRequestService,
    ) {}

    public function store(AssistantChatRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.'],
            ], 401);
        }

        $payload = $request->safe()->only(['conversation_id', 'message']);
        $message = trim((string) ($payload['message'] ?? ''));
        $conversationId = $payload['conversation_id'] ?? null;
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        $idempotencyKey = is_string($idempotencyKey) ? trim($idempotencyKey) : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        try {
            $result = $this->assistantRequestService->handle(
                $user,
                $conversationId,
                $message,
                $idempotencyKey,
            );
        } catch (ConversationAccessException $e) {
            $status = $e->errorCode === ConversationAccessException::CODE_NOT_FOUND ? 404 : 403;

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ],
            ], $status);
        } catch (AssistantIdempotencyException $e) {
            $status = match ($e->errorCode) {
                AssistantIdempotencyException::CODE_BODY_MISMATCH => 409,
                AssistantIdempotencyException::CODE_IN_PROGRESS => 503,
                default => 409,
            };

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ],
            ], $status);
        }

        return $this->jsonFromResult($result);
    }

    private function jsonFromResult(AssistantRequestResult $result): JsonResponse
    {
        $payload = [
            'success' => $result->success,
            'data' => $result->data,
        ];

        if (! $result->success && $result->errorCode !== '') {
            $payload['error'] = [
                'code' => $result->errorCode,
                'message' => $result->message,
            ];
        }

        return response()->json($payload, $result->httpStatus);
    }
}
