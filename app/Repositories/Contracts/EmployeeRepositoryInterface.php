<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Employee;
use App\Models\EmployeeTemplate;
use App\Models\Workspace;

interface EmployeeRepositoryInterface
{
    public function ensureTemplate(string $code, array $attributes): EmployeeTemplate;

    public function ensureInstance(Workspace $workspace, EmployeeTemplate $template): Employee;

    public function defaultFor(Workspace $workspace): ?Employee;
}
