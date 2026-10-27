<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    use HasPublicId;

    protected $fillable = ['workspace_id', 'user_id', 'kind', 'status', 'details', 'details_hash', 'external_key'];

    protected function casts(): array
    {
        return ['details' => 'encrypted'];
    }
}
