<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryItem extends Model
{
    protected $fillable = [
        'workspace_id',
        'employee_id',
        'scope',
        'key',
        'value_json',
        'source_type',
        'source_id',
        'verification_status',
        'confirmed_by',
        'revision',
        'status',
        'sensitivity',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
        ];
    }
}
