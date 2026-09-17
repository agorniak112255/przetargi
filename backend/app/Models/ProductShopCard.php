<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\B2b\B2bRemoteShopField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kopia tabelki z karty wyrobu u dostawcy (sklep B2B): wiersze nazwa→wartość dosłownie ze źródła, z kontem,
 * adresem karty i czasem pobrania. Jeden rekord na parę (karta, konto B2B) — kilku dostawców opisuje ten sam
 * wyrób osobno, jak sloty cen w product_source_prices.
 *
 * Te dane nie są opisem wyrobu: karta bez prozy u dostawcy nadal czeka na opis i zbiorcze uzupełnianie AI ma ją
 * traktować jako pustą (App\Services\B2b\B2bDescriptionSource, Product::isDescriptionText).
 *
 * @property array<int, array{section: string, rows: list<array{name: string, value: string}>}> $fields
 */
class ProductShopCard extends Model
{
    /** Karta dostawcy bywa długa (UVEX „Protection Level”), ale nie jest dokumentem — tyle wierszy wystarcza. */
    public const MAX_ROWS = 200;

    public const MAX_SECTION_CHARS = 120;

    public const MAX_NAME_CHARS = 255;

    public const MAX_VALUE_CHARS = 1000;

    protected $fillable = [
        'product_id',
        'b2b_account_id',
        'source_url',
        'fields',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }

    /**
     * Wiersze łącznika → kształt kolumny fields: sekcje w kolejności pierwszego wystąpienia, wiersze w kolejności
     * ze źródła. Puste nazwy i wartości odpadają (pusty wiersz nic nie mówi), powtórzone etykiety zostają.
     *
     * @param  list<B2bRemoteShopField>  $fields
     * @return array<int, array{section: string, rows: list<array{name: string, value: string}>}>
     */
    public static function sectionsFrom(array $fields): array
    {
        $sections = [];
        $rows = 0;
        foreach ($fields as $field) {
            if ($rows >= self::MAX_ROWS) {
                break;
            }
            $name = trim($field->name);
            $value = trim($field->value);
            if ($name === '' || $value === '') {
                continue;
            }
            $section = mb_substr(trim($field->section), 0, self::MAX_SECTION_CHARS);
            if (! array_key_exists($section, $sections)) {
                $sections[$section] = [];
            }
            $sections[$section][] = [
                'name' => mb_substr($name, 0, self::MAX_NAME_CHARS),
                'value' => mb_substr($value, 0, self::MAX_VALUE_CHARS),
            ];
            $rows++;
        }

        $out = [];
        foreach ($sections as $section => $sectionRows) {
            $out[] = ['section' => (string) $section, 'rows' => $sectionRows];
        }

        return $out;
    }
}
