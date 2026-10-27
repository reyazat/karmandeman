<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceDataKey extends Model
{
    public $timestamps = false;

    protected $fillable = ['workspace_id', 'transit_key', 'wrapped_key', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
