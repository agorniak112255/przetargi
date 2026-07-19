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
        'enrichment_model',
        'timeout_seconds',
        'temperature',
        'web_search_enabled',
        'tavily_api_key',
        'search_fallback',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'tavily_api_key' => 'encrypted',
            'timeout_seconds' => 'integer',
            'temperature' => 'float',
            'web_search_enabled' => 'boolean',
        ];
    }
}
