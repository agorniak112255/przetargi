<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BrandDictionary;
use Illuminate\Database\Eloquent\Model;

/**
 * Wpis słownika producentów i marek (zob. BrandDictionary). Każdy zapis i usunięcie unieważnia pamięć
 * słownika — inaczej edycja w administracji działałaby dopiero po restarcie workera.
 *
 * @property int $id
 * @property string $term
 * @property string $term_key
 * @property string $kind
 * @property string|null $manufacturer
 * @property bool $detect_in_query
 * @property string|null $note
 */
class BrandDictionaryEntry extends Model
{
    public const KIND_PRODUCER = 'producer';

    public const KIND_BRAND = 'brand';

    public const KIND_EXCLUSION = 'exclusion';

    public const KINDS = [self::KIND_PRODUCER, self::KIND_BRAND, self::KIND_EXCLUSION];

    protected $fillable = [
        'term',
        'term_key',
        'kind',
        'manufacturer',
        'detect_in_query',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'detect_in_query' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            $entry->term = trim($entry->term);
            $entry->term_key = BrandDictionary::key($entry->term);
            if ($entry->kind !== self::KIND_BRAND) {
                $entry->manufacturer = null;
            }
            if ($entry->kind === self::KIND_EXCLUSION) {
                // wykluczenie z definicji nie jest wyłuskiwane — flaga bez znaczenia, trzymamy ją spójnie
                $entry->detect_in_query = false;
            }
        });

        static::saved(static fn (): mixed => BrandDictionary::forget());
        static::deleted(static fn (): mixed => BrandDictionary::forget());
    }
}
