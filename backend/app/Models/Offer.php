<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oferta dla klienta: wybrane produkty (towary XL albo karty) z ceną netto, wysyłana ze skrzynki autora — osobny mail
 * na każdy adres — albo kopiowana do Thunderbirda. Bez statusu: zawsze edytowalna; co dostał klient, mówi zapis
 * wysyłki (OfferSend). Widzi i zmienia ją tylko autor.
 */
class Offer extends Model
{
    /** Forma oferty: body — produkty w treści maila; pdf — krótki mail, oferta w załączniku PDF; both — oba. */
    public const DELIVERIES = ['body', 'pdf', 'both'];

    /**
     * Rodzaj: products — produkty z ceną (offer_items); inspection — oferta przeglądu dla klienta XL (customer_xl_gid)
     * z terminami bez cen (offer_inspection_lines), przygotowywana z modułu Przeglądy.
     */
    public const KIND_PRODUCTS = 'products';

    public const KIND_INSPECTION = 'inspection';

    public const KINDS = [self::KIND_PRODUCTS, self::KIND_INSPECTION];

    /**
     * Ceny w mailu: net — netto (jak wpisane przy pozycjach); gross — brutto ze stałą stawką offers.vat_percent (XL nie
     * podaje stawki towaru). Handlowiec zawsze wpisuje netto.
     */
    public const PRICE_MODES = ['net', 'gross'];

    /** @var array<string, mixed> jak domyślne kolumn — nowa oferta ma formę, rodzaj i ceny bez odczytu z bazy */
    protected $attributes = ['delivery' => 'body', 'kind' => self::KIND_PRODUCTS, 'price_mode' => 'net'];

    protected $fillable = [
        'user_id',
        'kind',
        'customer_xl_gid',
        'code',
        'subject',
        'intro',
        'layout',
        'valid_until',
        'delivery',
        'price_mode',
        'last_sent_at',
        'last_copied_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_xl_gid' => 'integer',
            'valid_until' => 'date',
            'last_sent_at' => 'datetime',
            'last_copied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // kod w temacie „Zapytaj o ofertę” wiąże pytanie klienta z ofertą (jak K-0001 kampanii)
        static::created(static function (Offer $offer): void {
            if ($offer->code === null) {
                $offer->forceFill(['code' => 'OF-'.str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OfferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<OfferInspectionLine, $this> wiersze oferty przeglądu (kind = inspection) */
    public function inspectionLines(): HasMany
    {
        return $this->hasMany(OfferInspectionLine::class)->orderBy('position')->orderBy('id');
    }

    public function isInspection(): bool
    {
        return $this->kind === self::KIND_INSPECTION;
    }

    /** Ceny brutto w mailu; nieznana wartość = netto. */
    public function isGross(): bool
    {
        return $this->price_mode === 'gross';
    }

    /** Stawka VAT cen brutto (config offers.vat_percent). */
    public static function vatPercent(): float
    {
        return (float) config('offers.vat_percent', 23);
    }

    /** Cena brutto z netto: round(netto × (1 + VAT/100); 2). */
    public static function gross(float $net): float
    {
        return round($net * (1 + self::vatPercent() / 100), 2);
    }

    /** @return HasMany<OfferSend, $this> najnowsza wysyłka pierwsza */
    public function sends(): HasMany
    {
        return $this->hasMany(OfferSend::class)->latest()->orderByDesc('id');
    }

    /** @return HasMany<OfferRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(OfferRecipient::class);
    }
}
