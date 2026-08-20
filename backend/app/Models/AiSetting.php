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
        'enrichment_use_large_model',
        'timeout_seconds',
        'temperature',
        'web_search_enabled',
        'tavily_api_key',
        'search_fallback',
        'tavily_search_mode',
        'enrichment_batch_limit',
        'match_concurrency',
        'vector_enabled',
        'qdrant_url',
        'qdrant_api_key',
        'qdrant_collection',
        'embedding_model',
        'embedding_base_url',
        'embedding_api_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'tavily_api_key' => 'encrypted',
            'qdrant_api_key' => 'encrypted',
            'embedding_api_key' => 'encrypted',
            'timeout_seconds' => 'integer',
            'temperature' => 'float',
            'web_search_enabled' => 'boolean',
            'enrichment_use_large_model' => 'boolean',
            'enrichment_batch_limit' => 'integer',
            'match_concurrency' => 'integer',
            'vector_enabled' => 'boolean',
        ];
    }
}
