<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Services\AI\AssistantRunCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantRunController extends Controller
{
    public function __construct(
        private readonly AssistantRunCoordinator $runCoordinator,
    ) {}

    public function show(Request $request, string $runId): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.'],
            ], 401);
        }

        $run = AssistantRun::query()
            ->where('public_id', $runId)
            ->first();

        if ($run === null || $run->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => 'Assistant run not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->runCoordinator->statusPayload($run),
        ], 200);
    }
}
