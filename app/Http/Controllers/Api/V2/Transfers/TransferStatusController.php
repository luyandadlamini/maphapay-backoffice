<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2\Transfers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;
use Workflow\WorkflowStub;

/**
 * Polling endpoint for sync-bounded-wait transfers.
 *
 * Pair with the POST /api/v2/transfers HTTP 202 response, which carries the
 * `workflow_id` and a `status_url` pointing here. Mobile clients call this
 * until they see `state: 'completed'` (with `result` payload) or
 * `state: 'failed'`. Polling rounds should back off (e.g. 250 ms → 1 s).
 */
class TransferStatusController extends Controller
{
    #[OA\Get(
        path: '/api/v2/transfers/{workflowId}/status',
        operationId: 'getTransferStatus',
        tags: ['Transfers'],
        summary: 'Poll the status of an asynchronous transfer workflow',
        description: 'Returns the current state of the workflow created by POST /api/v2/transfers. Use this after receiving HTTP 202 from the create endpoint.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'workflowId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'The workflow_id returned by POST /api/v2/transfers',
            ),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Workflow status snapshot',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', example: 'success'),
            new OA\Property(property: 'message', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'workflow_id', type: 'string'),
                new OA\Property(property: 'state', type: 'string', enum: ['completed', 'pending', 'failed']),
                new OA\Property(property: 'result', type: 'object', nullable: true, description: 'Workflow output (only when state=completed)'),
                new OA\Property(property: 'failure_message', type: 'string', nullable: true, description: 'Safe error message (only when state=failed)'),
            ]),
        ])
    )]
    #[OA\Response(response: 404, description: 'Workflow not found')]
    public function show(Request $request, string $workflowId): JsonResponse
    {
        try {
            $stub = WorkflowStub::load($workflowId);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Transfer workflow not found.'],
                'data'    => null,
            ], 404);
        }

        if ($stub->completed()) {
            return $this->okJson($workflowId, 'completed', result: $stub->output());
        }

        if ($stub->failed()) {
            return $this->okJson($workflowId, 'failed', failureMessage: $this->failureMessageFor($stub));
        }

        return $this->okJson($workflowId, 'pending');
    }

    /**
     * @param  mixed  $result
     */
    private function okJson(string $workflowId, string $state, mixed $result = null, ?string $failureMessage = null): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => [],
            'data'    => [
                'workflow_id'     => $workflowId,
                'state'           => $state,
                'result'          => $result,
                'failure_message' => $failureMessage,
            ],
        ]);
    }

    private function failureMessageFor(\Workflow\WorkflowStub $stub): string
    {
        try {
            $first = $stub->exceptions()->first();
            $message = is_object($first) ? ($first->message ?? null) : null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        } catch (Throwable) {
            // fall through to generic message
        }

        return 'Transfer failed';
    }
}
