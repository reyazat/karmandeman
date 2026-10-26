<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TelegramAuthRequest extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'key', 'request_hash', 'encrypted_token', 'personal_access_token_id', 'created_at'];

    protected function casts(): array
    {
        return ['encrypted_token' => 'encrypted', 'created_at' => 'datetime'];
    }
}
