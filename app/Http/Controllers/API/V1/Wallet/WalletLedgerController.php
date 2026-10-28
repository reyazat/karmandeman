<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wallet\WalletLedgerResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class WalletLedgerController extends Controller
{
    public function __invoke(
        Request $request,
        WorkspaceRepositoryInterface $workspaces,
        WalletRepositoryInterface $wallets,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());
        $wallet = $workspace !== null ? $wallets->forWorkspace($workspace) : null;

        if ($wallet === null) {
            return ApiResponse::error('WALLET_NOT_FOUND', 'کیف پول یافت نشد.', 404);
        }

        Gate::authorize('view', $wallet);

        return ApiResponse::success((new WalletLedgerResource($wallet))->withEntries($wallets->entries($wallet)));
    }
}
