<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NoticeNumber;
use App\Support\OfferPricing;
use App\Support\PolishTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

class Tender extends Model
{
    /** próby zapisu z kolejnym numerem wewnętrznym przy równoczesnym zakładaniu przetargów */
    public const NUMBER_ATTEMPTS = 5;

    protected $fillable = [
        'number',
        'title',
        'client_id',
        'owner_id',
        'deadline',
        'deadline_time',
        'notice_number',
        'result_status',
        'bzp_checked_at',
        'contract_notice_id',
        'result_notice_id',
        'status',
        'ai_percent',
        'offer_value_net',
        'margin_percent',
        'target_margin_percent',
        'last_activity_at',
    ];

    /**
     * margin_percent_standard — marża od ceny standardowej kart z ceną specjalną B2B; użytkownik bez
     * prices.supplier_special.view dostaje ją pod nazwą margin_percent (App\Services\Tenders\TenderPriceView).
     */
    protected $hidden = [
        'margin_percent_standard',
    ];

    /**
     * notice_source — źródło numeru ogłoszenia (bzp / ted / null) wyprowadzone z notice_number.
     */
    protected $appends = [
        'notice_source',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'last_activity_at' => 'datetime',
            'offer_value_net' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'margin_percent_standard' => 'decimal:2',
            'target_margin_percent' => 'decimal:2',
            'bzp_checked_at' => 'datetime',
        ];
    }

    /**
     * Godzina składania ofert w czasie polskim „na zegarze”: w API „HH:MM” albo null (kolumna TIME trzyma
     * „HH:MM:SS”). Zapis przyjmuje „H:MM”, „HH:MM” i „HH:MM:SS”; pusty tekst = brak godziny. Inny zapis to błąd
     * wywołującego (kontroler waliduje format wcześniej) — wyjątek zamiast cichej utraty godziny.
     *
     * @return Attribute<?string, ?string>
     */
    protected function deadlineTime(): Attribute
    {
        return Attribute::make(
            get: static function (mixed $value): ?string {
                if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) !== 1) {
                    return null;
                }

                return sprintf('%02d:%s', (int) $m[1], $m[2]);
            },
            set: static function (mixed $value): ?string {
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return null;
                }
                if (! is_string($value) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m) !== 1) {
                    throw new InvalidArgumentException('Godzina terminu musi mieć postać GG:MM.');
                }

                return sprintf('%02d:%s:00', (int) $m[1], $m[2]);
            },
        );
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function noticeSource(): Attribute
    {
        return Attribute::get(fn (): ?string => NoticeNumber::source($this->attributes['notice_number'] ?? null));
    }

    /**
     * Kolejny numer wewnętrzny „PRZ/RRRR/NNNN” — rok w czasie polskim, liczba przetargów z tego roku + 1, zajęty
     * numer — następny wolny. $taken: numer, który właśnie okazał się zajęty (wyścig z równoległym zapisem, którego
     * ten odczyt może jeszcze nie widzieć) — wynik jest od niego większy.
     */
    public static function nextNumber(?string $taken = null): string
    {
        $year = (int) PolishTime::now()->format('Y');
        $seq = static::query()->where('number', 'like', "PRZ/{$year}/%")->count() + 1;
        if ($taken !== null && preg_match('~^PRZ/'.$year.'/(\d+)$~', $taken, $m) === 1) {
            $seq = max($seq, (int) $m[1] + 1);
        }
        do {
            $number = sprintf('PRZ/%d/%04d', $year, $seq++);
        } while (static::query()->where('number', $number)->exists());

        return $number;
    }

    /**
     * Zapis nowego przetargu z kolejnym numerem wewnętrznym. Dwa równoczesne zapisy mogą wyliczyć ten sam numer —
     * przegrany dostaje błąd unikalności numeru i próbuje z następnym (najwyżej NUMBER_ATTEMPTS razy).
     *
     * @param  array<string, mixed>  $attributes  bez `number`
     */
    public static function createWithNextNumber(array $attributes): static
    {
        $taken = null;
        for ($attempt = 1; ; $attempt++) {
            $number = static::nextNumber($taken);
            try {
                /** @var static $tender */
                $tender = static::query()->create(['number' => $number] + $attributes);

                return $tender;
            } catch (UniqueConstraintViolationException $e) {
                // tylko kolizja numeru (MariaDB: klucz tenders_number_unique, SQLite: tenders.number) — sam napis
                // „number” jest też w treści zapytania INSERT, więc nie wystarcza
                $message = $e->getMessage();
                $numberTaken = str_contains($message, 'tenders_number_unique') || str_contains($message, 'tenders.number');
                if ($attempt >= self::NUMBER_ATTEMPTS || ! $numberTaken) {
                    throw $e;
                }
                $taken = $number;
            }
        }
    }

    public function targetMarkupPercent(): float
    {
        if ($this->target_margin_percent !== null) {
            return (float) $this->target_margin_percent;
        }

        return OfferPricing::markupPercent();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TenderItem::class)->orderBy('line_no');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(TenderCondition::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenderDocument::class)->latest();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TenderStatusHistory::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TenderActivity::class)->latest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TenderComment::class)->latest('id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TenderInvitation::class)->latest('id');
    }

    /** Części zamówienia z wynikiem (przetarg bez części dostaje część nr 1 przy pierwszym zapisie wyniku). */
    public function lots(): HasMany
    {
        return $this->hasMany(TenderLot::class)->orderBy('lot_no');
    }

    public function contractNotice(): BelongsTo
    {
        return $this->belongsTo(ProcurementNotice::class, 'contract_notice_id');
    }

    public function resultNotice(): BelongsTo
    {
        return $this->belongsTo(ProcurementNotice::class, 'result_notice_id');
    }

    /**
     * Przetargi użytkownika jako opiekun albo zaproszony.
     *
     * @param  Builder<Tender>  $query
     * @return Builder<Tender>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $userId = (int) $user->id;

        return $query->where(function (Builder $builder) use ($userId): void {
            $builder->where('owner_id', $userId)
                ->orWhereHas('invitations', static function (Builder $invitations) use ($userId): void {
                    $invitations->where('user_id', $userId);
                });
        });
    }
}
