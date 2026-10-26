<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthorizeCurrentWorkspace
{
    public function __construct(private WorkspaceRepositoryInterface $workspaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->workspaces->currentFor($request->user());
        abort_if($workspace === null, 404);
        Gate::authorize('access', $workspace);

        return $next($request);
    }
}
