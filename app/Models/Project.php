<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property ProjectState $state */
class Project extends Model
{
    protected $fillable = [
        'workspace_id',
        'employee_id',
        'name',
        'state',
        'version',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function casts(): array
    {
        return [
            'state' => ProjectState::class,
        ];
    }
}
