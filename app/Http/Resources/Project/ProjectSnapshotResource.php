<?php

declare(strict_types=1);

namespace App\Http\Resources\Project;

use App\Http\Resources\Product\ProductRecordResource;
use App\Models\ProductRevision;
use App\Models\ProjectSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Approved batch (ADR-018). The public id is the snapshot version of FR-043;
 * MACs and internal ids stay hidden. Records are added only after the
 * integrity check of ReadProjectSnapshotAction.
 *
 * @mixin ProjectSnapshot
 */
final class ProjectSnapshotResource extends JsonResource
{
    /**
     * @param  list<ProductRevision>|null  $revisions
     */
    public function __construct(ProjectSnapshot $snapshot, private readonly ?array $revisions = null)
    {
        parent::__construct($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'snapshot_id' => $this->public_id,
            'project_id' => $this->project->public_id,
            'number' => $this->number,
            'product_count' => count($this->items),
            'approved_at' => $this->created_at->toIso8601String(),
            'products' => $this->when($this->revisions !== null, fn () => ProductRecordResource::collection($this->revisions ?? [])),
        ];
    }
}
