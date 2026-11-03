<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiRunStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One n8n extraction run of a batch (ADR-032). The public id is the run_id.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $project_id
 * @property int $requested_by
 * @property int|null $quote_id
 * @property int|null $reservation_entry_id
 * @property string $workflow
 * @property string $workflow_version
 * @property AiRunStatus $status
 * @property list<string> $input_asset_ids
 * @property list<string>|null $input_voice_ids
 * @property int $dispatch_attempts
 * @property Carbon $deadline_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $claimed_at
 * @property string|null $claimed_by_execution
 * @property Carbon|null $completed_at
 * @property string|null $error_code
 * @property string|null $result_mac
 * @property int|null $candidate_count
 * @property array<string, int|string>|null $usage
 */
class AiWorkflowRun extends Model
{
    use HasPublicId;

    public const WORKFLOW_PRODUCT_EXTRACTION = 'product_extraction';

    protected $fillable = [
        'workspace_id', 'project_id', 'requested_by', 'workflow', 'workflow_version', 'status',
        'input_asset_ids', 'input_voice_ids', 'deadline_at',
    ];

    protected $hidden = ['result_mac'];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'input_asset_ids' => 'array',
            'input_voice_ids' => 'array',
            'usage' => 'array',
            'dispatch_attempts' => 'integer',
            'candidate_count' => 'integer',
            'deadline_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
