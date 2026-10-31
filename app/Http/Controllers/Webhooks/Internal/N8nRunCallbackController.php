<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks\Internal;

use App\Actions\Project\CompleteAIWorkflowRunAction;
use App\Exceptions\WorkflowOutputRejected;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Signed result of an n8n run (ADR-032). The signature is checked by
 * middleware over the raw body; the Action re-validates everything else.
 * Answers carry no product data, so n8n learns nothing it did not send.
 */
final class N8nRunCallbackController extends Controller
{
    public function __invoke(Request $request, CompleteAIWorkflowRunAction $complete): JsonResponse
    {
        $raw = $request->getContent();
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ApiResponse::error('WORKFLOW_OUTPUT_INVALID', 'Callback body is not JSON.', 422);
        }
        if (! is_array($body) || array_is_list($body)) {
            return ApiResponse::error('WORKFLOW_OUTPUT_INVALID', 'Callback body must be an object.', 422);
        }

        try {
            $outcome = $complete->execute($raw, $body);
        } catch (WorkflowOutputRejected $e) {
            return ApiResponse::error($e->getMessage(), 'Callback body breaks the output schema.', 422, ['fields' => array_slice($e->fields, 0, 50)]);
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                CompleteAIWorkflowRunAction::RUN_NOT_FOUND => ApiResponse::error($e->getMessage(), 'Unknown run.', 404),
                CompleteAIWorkflowRunAction::RUN_ALREADY_COMPLETED => ApiResponse::error($e->getMessage(), 'Run already has a different result.', 409),
                default => throw $e,
            };
        }

        return ApiResponse::success(['outcome' => $outcome]);
    }
}
