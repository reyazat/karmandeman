<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks\Internal;

use App\Actions\Project\ReadRunVoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** One voice of an extraction run for n8n, through its signed link (ADR-047). */
final class N8nRunVoiceController extends Controller
{
    public function __invoke(string $run, string $voice, Request $request, ReadRunVoiceAction $read): Response
    {
        try {
            // A repeated or array parameter is no link of ours.
            $expires = $request->query('expires');
            $signature = $request->query('signature');
            [$found, $bytes] = $read->execute($run, $voice, is_string($expires) ? $expires : '', is_string($signature) ? $signature : '');
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                ReadRunVoiceAction::FORBIDDEN => ApiResponse::error($e->getMessage(), 'Link is not valid for this run.', 403),
                'VOICE_DAMAGED' => ApiResponse::error($e->getMessage(), 'Voice could not be cleaned and is not sent.', 422),
                'VOICE_INTEGRITY_FAILED' => ApiResponse::error($e->getMessage(), 'Voice failed its integrity check.', 500),
                default => throw $e,
            };
        }

        return response($bytes, 200, [
            'Content-Type' => $found->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.$found->public_id.'.ogg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
