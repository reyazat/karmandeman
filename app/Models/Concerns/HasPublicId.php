<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

trait HasPublicId
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
