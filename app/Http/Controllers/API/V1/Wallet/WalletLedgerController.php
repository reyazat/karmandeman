<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wallet\WalletLedgerEntryResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return ApiResponse::success([
            'balance' => $wallet->balance_cached,
            'entries' => WalletLedgerEntryResource::collection($wallets->entries($wallet)),
        ]);
    }
}
