<?php

use App\Http\Controllers\API\V1\Brand\CurrentBrandController;
use App\Http\Controllers\API\V1\Identity\MeController;
use App\Http\Controllers\API\V1\Identity\TelegramAuthController;
use App\Http\Controllers\API\V1\Privacy\AccountDeletionController;
use App\Http\Controllers\API\V1\Product\ProductController;
use App\Http\Controllers\API\V1\Project\ProjectController;
use App\Http\Controllers\API\V1\Project\ProjectWorkflowController;
use App\Http\Controllers\API\V1\Wallet\WalletLedgerController;
use App\Http\Controllers\API\V1\Workspace\CurrentWorkspaceController;
use App\Http\Controllers\Webhooks\Telegram\TelegramWebhookController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

// Channel webhooks: verified by their own middleware, outside the v1 API scope.
Route::post('/webhooks/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');

// API v1: thin controllers -> FormRequest -> Action -> Repository contract.
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn () => ApiResponse::success(['status' => 'ok']));

    Route::post('/auth/telegram/verify', [TelegramAuthController::class, 'store'])->middleware('throttle:telegram-auth');

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::get('/me', MeController::class);
        Route::middleware(['workspace.authorized', 'throttle:workspace-api'])->group(function (): void {
            Route::get('/workspaces/current', CurrentWorkspaceController::class);
            Route::get('/brands/current', [CurrentBrandController::class, 'show']);
            Route::patch('/brands/current', [CurrentBrandController::class, 'update']);
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereUuid('project');
            Route::apiResource('/projects', ProjectController::class)->only(['index', 'store']);
            Route::get('/projects/{project}/products', [ProductController::class, 'index'])->whereUuid('project');
            Route::post('/projects/{project}/products', [ProductController::class, 'store'])->whereUuid('project');
            Route::get('/projects/{project}/products/summary', [ProductController::class, 'summary'])->whereUuid('project');
            Route::post('/projects/{project}/finish-input', [ProjectWorkflowController::class, 'finishInput'])->whereUuid('project');
            Route::post('/projects/{project}/approve-products', [ProjectWorkflowController::class, 'approveProducts'])->whereUuid('project');
            Route::get('/projects/{project}/snapshot', [ProjectWorkflowController::class, 'snapshot'])->whereUuid('project');
            Route::get('/products/{product}', [ProductController::class, 'show'])->whereUuid('product');
            Route::patch('/products/{product}/draft', [ProductController::class, 'revise'])->whereUuid('product');
            Route::post('/products/{product}/approve', [ProductController::class, 'approve'])->whereUuid('product');
            Route::post('/products/{product}/archive', [ProductController::class, 'archive'])->whereUuid('product');
            Route::get('/products/{product}/approved', [ProductController::class, 'approved'])->whereUuid('product');
            Route::get('/wallet/ledger', WalletLedgerController::class);
            Route::post('/account/deletion', [AccountDeletionController::class, 'store'])->middleware('throttle:account-deletion');
        });
    });
});
