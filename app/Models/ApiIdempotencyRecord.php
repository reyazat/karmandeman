<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $result_attributes null once expired by retention (ADR-015) */
final class ApiIdempotencyRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'user_id', 'operation', 'key', 'request_hash', 'result_attributes', 'created_at',
    ];

    protected function casts(): array
    {
        return ['result_attributes' => 'array', 'created_at' => 'datetime'];
    }
}
