<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wiersz tabeli części ze strony producenta (Coba: `<table id="parts-table">`): numer części, rozmiar, kolor, waga,
 * zdjęcie modelu z wiersza i zdjęcie stylu w kolorze wiersza. Zapisuje tylko products:parts-table --refresh; czyta
 * App\Services\Enrichment\PartsTable\PartsTables.
 *
 * @property int $id
 * @property string $brand_key
 * @property string $page_url
 * @property string $page_url_hash
 * @property string|null $page_title
 * @property string $part_code
 * @property string $part_label
 * @property string|null $size_label
 * @property string|null $colour_label
 * @property float|null $weight_kg
 * @property string|null $model_image_url
 * @property string|null $style_image_url
 * @property bool $has_styles
 * @property string $page_sha
 */
class ManufacturerPart extends Model
{
    protected $fillable = [
        'brand_key',
        'page_url',
        'page_url_hash',
        'page_title',
        'part_code',
        'part_label',
        'size_label',
        'colour_label',
        'weight_kg',
        'model_image_url',
        'style_image_url',
        'has_styles',
        'page_sha',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'has_styles' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    /** sha1 adresu strony małymi literami, bez białych znaków na brzegach — klucz page_url_hash. */
    public static function hashFor(string $url): string
    {
        return sha1(mb_strtolower(trim($url), 'UTF-8'));
    }

    /** Kod części do porównań: wielkie litery i cyfry, bez separatorów („AF0107-06” → „AF010706”). */
    public static function codeKey(?string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $code, 'UTF-8'));
    }

    /** Ostatni człon ścieżki adresu strony („https://www.coba.com/pl/produkt/hygimat-2” → „hygimat-2”). */
    public static function pageKeyFor(string $url): string
    {
        $path = trim((string) (parse_url(trim($url), PHP_URL_PATH) ?? ''), '/');
        $parts = explode('/', $path);

        return (string) end($parts);
    }
}
