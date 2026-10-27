<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property Carbon $requested_at
 * @property Carbon $deadline_at
 */
class AccountDeletion extends Model
{
    use HasPublicId;

    protected $fillable = ['workspace_id', 'user_id', 'channel', 'status', 'requested_at', 'deadline_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'deadline_at' => 'datetime'];
    }
}
