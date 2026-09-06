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
        'reasoning_effort',
        'web_search_enabled',
        'tavily_api_key',
        'search_fallback',
        'search_engine',
        'searxng_url',
        'tavily_search_mode',
        'enrichment_batch_limit',
        'match_concurrency',
        'catalog_search_limit',
        'product_search_card_detail',
        'match_apply_score',
        'match_substitute_score',
        'match_min_score',
        'match_allow_catalog_rows',
        'vector_enabled',
        'qdrant_url',
        'qdrant_api_key',
        'qdrant_collection',
        'embedding_model',
        'embedding_base_url',
        'embedding_api_key',
        'embedding_provider',
        'embedding_cloud_model',
        'embedding_cloud_api_key',
        'model_profiles',
        'catalog_slang',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'tavily_api_key' => 'encrypted',
            'qdrant_api_key' => 'encrypted',
            'embedding_api_key' => 'encrypted',
            'embedding_cloud_api_key' => 'encrypted',
            'model_profiles' => 'encrypted:array',
            'catalog_slang' => 'array',
            'timeout_seconds' => 'integer',
            'temperature' => 'float',
            'web_search_enabled' => 'boolean',
            'enrichment_use_large_model' => 'boolean',
            'enrichment_batch_limit' => 'integer',
            'match_concurrency' => 'integer',
            'catalog_search_limit' => 'integer',
            'match_apply_score' => 'integer',
            'match_substitute_score' => 'integer',
            'match_min_score' => 'integer',
            'match_allow_catalog_rows' => 'boolean',
            'vector_enabled' => 'boolean',
        ];
    }
}
