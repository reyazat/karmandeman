<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Employee;

use App\Models\Employee;
use App\Models\EmployeeTemplate;
use App\Models\Workspace;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

final class EloquentEmployeeRepository implements EmployeeRepositoryInterface
{
    public function ensureTemplate(string $code, array $attributes): EmployeeTemplate
    {
        return EmployeeTemplate::firstOrCreate(['code' => $code], $attributes);
    }

    public function ensureInstance(Workspace $workspace, EmployeeTemplate $template): Employee
    {
        return Employee::firstOrCreate(
            ['workspace_id' => $workspace->id, 'template_id' => $template->id],
            ['status' => 'active'],
        );
    }

    public function defaultFor(Workspace $workspace): ?Employee
    {
        return Employee::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }
}
