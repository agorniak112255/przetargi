<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NoticeNumber;
use App\Support\OfferPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Tender extends Model
{
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
