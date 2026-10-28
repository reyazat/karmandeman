<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountDeletionStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Detached from workspace and user once the live purge ran (ADR-014).
 *
 * @property string $public_id
 * @property int|null $workspace_id
 * @property int|null $user_id
 * @property AccountDeletionStatus $status
 * @property Carbon $requested_at
 * @property Carbon $deadline_at
 * @property string|null $transit_key
 * @property Carbon|null $key_destroy_started_at
 * @property Carbon|null $key_destroyed_at
 * @property Carbon|null $live_purged_at
 * @property Carbon|null $completed_at
 * @property int $attempts
 */
class AccountDeletion extends Model
{
    use HasPublicId;

    protected $fillable = ['workspace_id', 'user_id', 'channel', 'status', 'requested_at', 'deadline_at'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AccountDeletionStatus::class,
            'requested_at' => 'datetime',
            'deadline_at' => 'datetime',
            'key_destroy_started_at' => 'datetime',
            'key_destroyed_at' => 'datetime',
            'live_purged_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
