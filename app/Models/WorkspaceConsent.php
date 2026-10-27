<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceConsent extends Model
{
    public $timestamps = false;

    protected $fillable = ['workspace_id', 'user_id', 'policy_version', 'policy_document_hash', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
