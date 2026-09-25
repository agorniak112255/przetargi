<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wniosek modelu ze zdjęcia karty (nie tekst karty) — np. czy pięta sandała jest zabudowana. Zapisuje go tylko komenda
 * products:check-closed-heel; wyszukiwarka pokazuje go modelowi osobnym polem i dopisuje pochodzenie do uzasadnienia.
 */
class ProductVisualCheck extends Model
{
    public const FEATURE_CLOSED_HEEL = 'closed_heel';

    /**
     * Wersja pytania do modelu obrazu. Ocena starszą wersją nie idzie do wyszukiwarki, a komenda ocenia zdjęcie od nowa
     * (25.09.2026: pierwsza wersja „closed/open” myliła klapki z paskiem za piętą — 50 ocen do powtórzenia).
     */
    public const CURRENT_PROMPT_VERSION = 'heel-2026-09-25-dwa-pytania';

    public const ANSWER_CLOSED = 'closed';

    public const ANSWER_OPEN = 'open';

    public const ANSWER_UNCLEAR = 'unclear';

    protected $fillable = [
        'product_id',
        'product_image_id',
        'feature',
        'answer',
        'image_checksum',
        'image_source_url',
        'model',
        'prompt_version',
        'what_seen',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'product_image_id');
    }
}
