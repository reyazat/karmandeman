<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $draft */
class TelegramOnboarding extends Model
{
    protected $fillable = ['workspace_id', 'step', 'draft', 'expected_brand_version'];

    protected function casts(): array
    {
        return ['draft' => 'encrypted:array', 'expected_brand_version' => 'integer'];
    }
}
