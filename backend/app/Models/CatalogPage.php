<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Karta produktu z sitemapy producenta lub hurtowni. Indeks pozwala znaleźć
 * stronę po kodzie bez pytania wyszukiwarki, która przy 30 tys. produktów blokuje ruch.
 */
class CatalogPage extends Model
{
    protected $fillable = [
        'host',
        'url_hash',
        'url',
        'title',
        'haystack',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public static function hashFor(string $url): string
    {
        return hash('sha256', mb_strtolower(trim($url)));
    }
}
