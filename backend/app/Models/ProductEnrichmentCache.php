<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductEnrichmentCache extends Model
{
    protected $fillable = [
        'manufacturer',
        'sku',
        'description',
        'enrichment_payload',
        'image_urls',
        'source_urls',
    ];

    protected function casts(): array
    {
        return [
            'enrichment_payload' => 'array',
            'image_urls' => 'array',
            'source_urls' => 'array',
        ];
    }

    public static function normalizeKey(string $manufacturer, string $sku): array
    {
        return [
            'manufacturer' => mb_strtolower(trim($manufacturer)),
            'sku' => mb_strtolower(trim($sku)),
        ];
    }
}
