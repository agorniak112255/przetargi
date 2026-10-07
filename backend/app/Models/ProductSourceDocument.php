<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Źródło opisu karty (08.10.2026) — strona albo PDF z werdyktem tożsamości i skrótem tekstu; tekst na dysku „sources”.
 * Zapis i retencja: App\Services\Enrichment\SourceDocumentStore.
 *
 * @property list<array{type: string, value: string}>|null $markup_codes
 * @property list<array<string, mixed>>|null $norm_facts
 */
class ProductSourceDocument extends Model
{
    public const ROLE_DESCRIPTION = 'description';

    public const ROLE_IMAGE = 'image';

    public const ROLE_NORMS = 'norms';

    protected $fillable = [
        'product_id',
        'description_version_id',
        'url',
        'url_hash',
        'final_url',
        'host',
        'sha256',
        'filtered_sha256',
        'chars',
        'fetched_at',
        'identity_verdict',
        'identity_reason',
        'identity_key',
        'roles',
        'markup_codes',
        'norm_facts',
    ];

    protected function casts(): array
    {
        return [
            'description_version_id' => 'integer',
            'chars' => 'integer',
            'fetched_at' => 'datetime',
            'markup_codes' => 'array',
            'norm_facts' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** sha1 adresu po Product::normalizeShopUrl — ten sam adres z „/” na końcu czy inną wielkością hosta to jeden wiersz. */
    public static function urlHash(string $url): string
    {
        return sha1(Product::normalizeShopUrl($url));
    }

    /** @return list<string> */
    public function roleList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->roles)));
    }
}
