<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Retention;

use App\Models\ApiIdempotencyRecord;
use App\Models\ConversationEvent;
use App\Models\TelegramAuthRequest;
use App\Models\TelegramOnboarding;
use App\Repositories\Contracts\RetentionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentRetentionRepository implements RetentionRepositoryInterface
{
    private const DRAFT_STEPS = ['name', 'unit', 'category', 'tone', 'review'];

    public function deleteConversationEventsBefore(CarbonImmutable $cutoff): int
    {
        return ConversationEvent::query()->where('created_at', '<', $cutoff)->delete();
    }

    public function deleteAuthReplaysBefore(CarbonImmutable $issuedBefore): int
    {
        return TelegramAuthRequest::query()
            ->where(fn (Builder $query) => $query->where('created_at', '<', $issuedBefore)
                ->orWhereNotExists(fn ($tokens) => $tokens->select(DB::raw(1))->from('personal_access_tokens')
                    ->whereColumn('personal_access_tokens.id', 'telegram_auth_requests.personal_access_token_id')))
            ->delete();
    }

    public function expireIdempotencySnapshotsBefore(CarbonImmutable $cutoff): int
    {
        return ApiIdempotencyRecord::query()->where('created_at', '<', $cutoff)->whereNotNull('result_attributes')
            ->update(['result_attributes' => null]);
    }

    public function resetOnboardingDraftsBefore(CarbonImmutable $cutoff): int
    {
        $stale = fn () => TelegramOnboarding::query()->whereIn('step', self::DRAFT_STEPS)->where('updated_at', '<', $cutoff);
        $named = fn ($brands) => $brands->select(DB::raw(1))->from('brands')
            ->whereColumn('brands.workspace_id', 'telegram_onboardings.workspace_id')->whereNotNull('brands.name');
        $reset = ['draft' => null, 'expected_brand_version' => null, 'updated_at' => now()];

        return $stale()->whereExists($named)->update(['step' => 'ready'] + $reset)
            + $stale()->whereNotExists($named)->update(['step' => 'name'] + $reset);
    }
}
