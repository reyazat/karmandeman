<?php

declare(strict_types=1);

namespace App\Actions\Employee;

use App\Models\Employee;
use App\Models\Workspace;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

final readonly class EnsureDefaultEmployeeAction
{
    public function __construct(
        private EmployeeRepositoryInterface $employees,
    ) {}

    /**
     * Guarantee the first employee (product-prep template) exists for the workspace.
     */
    public function execute(Workspace $workspace): Employee
    {
        $template = $this->employees->ensureTemplate(
            config('karmandeman.default_employee_template', 'product-prep'),
            [
                'name' => 'کارمند آماده‌سازی محصول',
                'version' => '1.0',
                'allowed_tools' => ['transcribe', 'extract', 'draft_copy'],
                'output_types' => ['woocommerce_csv', 'pdf_catalog', 'json', 'online_catalog', 'zip'],
            ],
        );

        return $this->employees->ensureInstance($workspace, $template);
    }
}
