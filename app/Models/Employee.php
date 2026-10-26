<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $fillable = [
        'workspace_id',
        'template_id',
        'name',
        'status',
        'config',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<EmployeeTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmployeeTemplate::class, 'template_id');
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }
}
