<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'channel',
        'external_update_id',
        'intent',
        'redacted_body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'redacted_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
