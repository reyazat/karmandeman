<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryItemRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'memory_item_id', 'workspace_id', 'revision', 'value_json', 'status',
        'verification_status', 'confirmed_by', 'source_type', 'source_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['value_json' => 'array', 'created_at' => 'datetime'];
    }
}
