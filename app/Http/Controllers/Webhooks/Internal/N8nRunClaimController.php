<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks\Internal;

use App\Actions\Project\ClaimAIWorkflowRunAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Signed claim of a run by one n8n execution (ADR-038). The signature is
 * checked by middleware over the raw body; n8n works on the run only after
 * a 200 here and stops on any other answer.
 */
final class N8nRunClaimController extends Controller
{
    public function __invoke(Request $request, ClaimAIWorkflowRunAction $claim): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ApiResponse::error(ClaimAIWorkflowRunAction::CLAIM_INVALID, 'Claim body is not JSON.', 422);
        }

        try {
            $claim->execute(is_array($body) && ! array_is_list($body) ? $body : []);
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                ClaimAIWorkflowRunAction::CLAIM_INVALID => ApiResponse::error($e->getMessage(), 'Claim body breaks the claim schema.', 422),
                ClaimAIWorkflowRunAction::RUN_NOT_FOUND => ApiResponse::error($e->getMessage(), 'Unknown run.', 404),
                ClaimAIWorkflowRunAction::RUN_ALREADY_CLAIMED => ApiResponse::error($e->getMessage(), 'Another execution works on this run.', 409),
                ClaimAIWorkflowRunAction::RUN_CLOSED => ApiResponse::error($e->getMessage(), 'Run can no longer change its batch.', 409),
                default => throw $e,
            };
        }

        return ApiResponse::success(['claimed' => true]);
    }
}
