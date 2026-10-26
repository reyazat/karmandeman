<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Model;

class WorkspaceMembership extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
        ];
    }
}
