<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja oferty: towar XL i/lub karta katalogu z ceną netto (null = do uzupełnienia). Nazwa, zdjęcie, koszt i cena
 * sugerowana liczone na bieżąco (App\Services\Offers\OfferItemPresenter) — migawek nie ma.
 */
class OfferItem extends Model
{
    /**
     * Jednostka ceny wybrana przy pozycji: kod → etykieta po „/” w mailu. null = jednostka towaru XL, bez niej „szt”.
     * Stan w mailu zostaje w jednostce XL (tak liczy go magazyn).
     */
    public const PRICE_UNITS = ['szt' => 'szt', 'para' => 'para', 'opak' => 'opak.', 'karton' => 'karton'];

    /** Zapisy jednostki XL (małe litery) → kod PRICE_UNITS; inna jednostka XL nie odpowiada żadnemu wyborowi. */
    private const XL_UNIT_CODES = [
        'szt' => 'szt', 'szt.' => 'szt',
        'par' => 'para', 'para' => 'para', 'pary' => 'para',
        'op' => 'opak', 'op.' => 'opak', 'opak' => 'opak', 'opak.' => 'opak', 'opakowanie' => 'opak',
        'kart' => 'karton', 'kart.' => 'karton', 'karton' => 'karton',
    ];

    protected $fillable = [
        'offer_id',
        'position',
        'erp_item_id',
        'product_id',
        'price_net',
        'price_unit',
        'sizes',
        'note',
        'description',
        'link_url',
        'link_label',
        'link_color',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'price_net' => 'decimal:2',
        ];
    }

    /** Etykieta po „/” przy cenie: wybrana jednostka, inaczej jednostka towaru XL, inaczej „szt”. */
    public static function priceUnitLabel(?string $priceUnit, ?string $xlUnit): string
    {
        if ($priceUnit !== null && isset(self::PRICE_UNITS[$priceUnit])) {
            return self::PRICE_UNITS[$priceUnit];
        }

        return $xlUnit !== null && trim($xlUnit) !== '' ? $xlUnit : 'szt';
    }

    /**
     * Wybrana jednostka ceny inna niż jednostka towaru XL (koszt jest za jednostkę XL, więc porównanie z ceną traci
     * sens). Bez jednostki XL porównanie ze „szt”; bez wyboru — zawsze zgodna.
     */
    public static function unitMismatch(?string $priceUnit, ?string $xlUnit): bool
    {
        if ($priceUnit === null) {
            return false;
        }
        $xl = $xlUnit !== null && trim($xlUnit) !== '' ? (self::XL_UNIT_CODES[mb_strtolower(trim($xlUnit))] ?? null) : 'szt';

        return $xl !== $priceUnit;
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<ErpItem, $this> */
    public function erpItem(): BelongsTo
    {
        return $this->belongsTo(ErpItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
