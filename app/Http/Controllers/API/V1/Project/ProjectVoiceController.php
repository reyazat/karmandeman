<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\AttachProjectVoiceAction;
use App\Actions\Project\ReadProjectVoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AttachProjectVoiceRequest;
use App\Http\Resources\Project\VoiceAssetResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\VoiceAssetRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Private, untranscribed voice intake: no provider calls or credit charges. */
final class ProjectVoiceController extends Controller
{
    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces) {}

    public function store(string $project, AttachProjectVoiceRequest $request, AttachProjectVoiceAction $attach): JsonResponse
    {
        $workspace = $this->workspaces->currentFor($request->user());
        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }
        try {
            [$voice, $replayed] = $attach->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->bytes(),
            );
        } catch (DomainException $e) {
            return $this->error($e);
        }

        return ApiResponse::success(new VoiceAssetResource($voice), $replayed || ! $voice->wasRecentlyCreated ? 200 : 201);
    }

    public function index(string $project, Request $request, ProjectRepositoryInterface $projects, VoiceAssetRepositoryInterface $voices): JsonResponse
    {
        $workspace = $this->workspaces->currentFor($request->user());
        $found = $workspace === null ? null : $projects->findPublicForWorkspace($workspace, $project);
        if ($found === null) {
            return ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404);
        }
        Gate::authorize('view', $found);

        return ApiResponse::success(VoiceAssetResource::collection($voices->paginateForProject($workspace, $found)));
    }

    public function download(string $voice, Request $request, ReadProjectVoiceAction $read): Response
    {
        $workspace = $this->workspaces->currentFor($request->user());
        if ($workspace === null) {
            return ApiResponse::error('VOICE_NOT_FOUND', 'فایل یافت نشد.', 404);
        }
        try {
            [$found, $bytes] = $read->execute($workspace, $request->user(), $voice);
        } catch (DomainException $e) {
            return $this->error($e);
        }

        return response($bytes, 200, [
            'Content-Type' => $found->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.$found->public_id.'.ogg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function error(DomainException $e): JsonResponse
    {
        return ApiResponse::fromPrivacyGate($e) ?? match ($e->getMessage()) {
            'PROJECT_NOT_FOUND', 'VOICE_NOT_FOUND' => ApiResponse::error($e->getMessage(), 'فایل یا پروژه یافت نشد.', 404),
            'VOICE_TOO_LARGE', 'VOICE_TOO_LONG', 'VOICE_DAMAGED', 'VOICE_UNSUPPORTED' => ApiResponse::error($e->getMessage(), 'ویس باید Ogg/Opus سالم، حداکثر دو دقیقه و دو مگابایت باشد.', 422),
            'VOICE_INTAKE_DISABLED' => ApiResponse::error('VOICE_INTAKE_DISABLED', 'دریافت ویس هنوز فعال نیست.', 409),
            'VOICE_INTEGRITY_FAILED' => ApiResponse::error('VOICE_INTEGRITY_FAILED', 'فایل قابل اعتماد نیست.', 500),
            'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED', 'PROJECT_NOT_ACCEPTING_INPUT' => ApiResponse::error($e->getMessage(), 'نسخه یا کلید درخواست با وضعیت کنونی سازگار نیست.', 409),
            default => throw $e,
        };
    }
}
