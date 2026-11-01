<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\AttachProductTableAction;
use App\Actions\Project\DiscardProductTableAction;
use App\Actions\Project\ImportProductTableAction;
use App\Actions\Project\ReadProductTableAction;
use App\Exceptions\ProductTableRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AttachProductTableRequest;
use App\Http\Requests\Project\ImportProductTableRequest;
use App\Http\Resources\Project\ProductTableResource;
use App\Http\Responses\ApiResponse;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A seller's CSV of simple products (PRD E03, ADR-040). */
final class ProductTableController extends Controller
{
    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces) {}

    public function store(string $project, AttachProductTableRequest $request, AttachProductTableAction $attach): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $attach): JsonResponse {
            [$table, $parsed, $replayed] = $attach->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->bytes(),
            );

            return ApiResponse::success((new ProductTableResource($table))->withParsed($parsed), $replayed || ! $table->wasRecentlyCreated ? 200 : 201);
        });
    }

    public function show(string $table, Request $request, ReadProductTableAction $read): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($table, $request, $read): JsonResponse {
            [$found, $parsed] = $read->execute($workspace, $request->user(), $table);

            return ApiResponse::success((new ProductTableResource($found))->withParsed($parsed));
        });
    }

    public function import(string $table, ImportProductTableRequest $request, ImportProductTableAction $import): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($table, $request, $import): JsonResponse {
            [$found, $replayed] = $import->execute($workspace, $request->user(), $request->validated('idempotency_key'), $table, $request->unit());

            return ApiResponse::success(new ProductTableResource($found), $replayed ? 200 : 201);
        });
    }

    public function destroy(string $table, Request $request, DiscardProductTableAction $discard): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($table, $request, $discard): JsonResponse {
            $discard->execute($workspace, $request->user(), $table);

            return response()->json(null, 204);
        });
    }

    /** @param  Closure(Workspace): JsonResponse  $handler */
    private function respond(Request $request, Closure $handler): JsonResponse
    {
        $workspace = $this->workspaces->currentFor($request->user());
        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            return $handler($workspace);
        } catch (ProductTableRejected $e) {
            return ApiResponse::error($e->getMessage(), 'کد محصول (SKU) این ردیف‌ها قبلاً در فضای کاری ثبت شده است؛ هیچ محصولی از این فایل ثبت نشد.', 409, ['lines' => $e->lines]);
        } catch (DomainException $e) {
            return ApiResponse::fromPrivacyGate($e) ?? match ($e->getMessage()) {
                'PROJECT_NOT_FOUND', 'TABLE_NOT_FOUND' => ApiResponse::error($e->getMessage(), 'پروژه یا فایل یافت نشد.', 404),
                'TABLE_UNREADABLE' => ApiResponse::error('TABLE_UNREADABLE', 'فقط فایل CSV با کدگذاری UTF-8 پذیرفته می‌شود.', 422),
                'TABLE_TOO_LARGE' => ApiResponse::error('TABLE_TOO_LARGE', 'حجم فایل بیش از حد مجاز است.', 422, ['max_kilobytes' => (int) config('karmandeman.tables.max_kilobytes')]),
                'TABLE_TOO_MANY_ROWS' => ApiResponse::error('TABLE_TOO_MANY_ROWS', 'تعداد ردیف‌های فایل بیش از حد مجاز است.', 422, ['max_rows' => (int) config('karmandeman.tables.max_rows')]),
                'TABLE_TOO_MANY_COLUMNS' => ApiResponse::error('TABLE_TOO_MANY_COLUMNS', 'تعداد ستون‌های فایل بیش از حد مجاز است.', 422, ['max_columns' => (int) config('karmandeman.tables.max_columns')]),
                'TABLE_EMPTY' => ApiResponse::error('TABLE_EMPTY', 'فایل سطر عنوان و دست‌کم یک ردیف محصول ندارد.', 422),
                'TABLE_NO_NAME_COLUMN' => ApiResponse::error('TABLE_NO_NAME_COLUMN', 'ستون نام محصول پیدا نشد؛ عنوان آن را «نام» بگذار.', 422),
                'TABLE_AMBIGUOUS_COLUMNS' => ApiResponse::error('TABLE_AMBIGUOUS_COLUMNS', 'دو ستون برای یک مشخصه (مثلاً دو ستون قیمت) هست؛ یکی را حذف کن.', 422),
                'TABLE_MIXED_PRICE_UNITS' => ApiResponse::error('TABLE_MIXED_PRICE_UNITS', 'قیمت‌های فایل هم تومان و هم ریال‌اند؛ همه را یک واحد کن.', 422),
                'TABLE_PRICE_UNIT_REQUIRED' => ApiResponse::error('TABLE_PRICE_UNIT_REQUIRED', 'واحد قیمت‌ها (تومان یا ریال) را مشخص کن.', 422),
                'TABLE_PRICE_UNIT_CONFLICT' => ApiResponse::error('TABLE_PRICE_UNIT_CONFLICT', 'واحد انتخاب‌شده با واحدی که فایل نام می‌برد یکی نیست.', 422),
                'TABLE_HAS_NO_PRODUCTS' => ApiResponse::error('TABLE_HAS_NO_PRODUCTS', 'هیچ ردیف قابل ثبتی در فایل نیست.', 422),
                'TABLE_ALREADY_IMPORTED', 'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED', 'PROJECT_NOT_ACCEPTING_INPUT', 'PROJECT_NOT_ACCEPTING_PRODUCTS' => ApiResponse::error(
                    $e->getMessage(), 'وضعیت فایل یا پروژه با این درخواست سازگار نیست.', 409,
                ),
                default => throw $e,
            };
        }
    }
}
