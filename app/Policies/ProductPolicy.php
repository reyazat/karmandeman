<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

/** The pilot has one role, the active owner, so view and update grant the same today. */
final class ProductPolicy
{
    use AuthorizesWorkspaceRecords;

    public function view(User $user, Product $product): bool
    {
        return $this->ownsWorkspace($user, $product->workspace_id);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->ownsWorkspace($user, $product->workspace_id);
    }
}
