<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTemplate extends Model
{
    protected $fillable = [
        'code',
        'name',
        'version',
        'allowed_tools',
        'output_types',
    ];

    protected function casts(): array
    {
        return [
            'allowed_tools' => 'array',
            'output_types' => 'array',
        ];
    }
}
