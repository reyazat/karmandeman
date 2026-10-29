<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One photo sent to the bot, pending download (ADR-024).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $project_id
 * @property int $user_id
 * @property string $file_ref
 * @property int|null $declared_size
 * @property string $message_key
 * @property Carbon $created_at
 */
class TelegramMediaIntake extends Model
{
    public $timestamps = false;

    protected $fillable = ['workspace_id', 'project_id', 'user_id', 'file_ref', 'declared_size', 'message_key', 'created_at'];

    protected $hidden = ['file_ref'];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['declared_size' => 'integer', 'created_at' => 'datetime'];
    }
}
