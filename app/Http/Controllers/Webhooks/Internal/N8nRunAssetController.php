<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks\Internal;

use App\Actions\Project\ReadRunAssetAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** One photo of an extraction run for n8n, through its signed link (ADR-033). */
final class N8nRunAssetController extends Controller
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __invoke(string $run, string $asset, Request $request, ReadRunAssetAction $read): Response
    {
        try {
            // A repeated or array parameter is no link of ours.
            $expires = $request->query('expires');
            $signature = $request->query('signature');
            [$found, $bytes] = $read->execute($run, $asset, is_string($expires) ? $expires : '', is_string($signature) ? $signature : '');
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                ReadRunAssetAction::FORBIDDEN => ApiResponse::error($e->getMessage(), 'Link is not valid for this run.', 403),
                'ASSET_METADATA_UNPARSEABLE' => ApiResponse::error($e->getMessage(), 'Photo could not be cleaned and is not sent.', 422),
                'ASSET_INTEGRITY_FAILED' => ApiResponse::error($e->getMessage(), 'Photo failed its integrity check.', 500),
                default => throw $e,
            };
        }

        return response($bytes, 200, [
            'Content-Type' => $found->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.$found->public_id.'.'.self::EXTENSIONS[$found->mime].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
