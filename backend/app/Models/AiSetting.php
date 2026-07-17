<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'enabled',
        'provider',
        'base_url',
        'api_key',
        'model',
        'timeout_seconds',
        'temperature',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'timeout_seconds' => 'integer',
            'temperature' => 'float',
        ];
    }
}
