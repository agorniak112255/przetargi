<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Część zamówienia z wynikiem przetargu. Przetarg bez części ma jedną część (nr 1).
 *
 * `manual_fields` — nazwy pól (z BZP_FIELDS i pól tylko ręcznych) wpisanych przez człowieka; Biuletyn ich nie
 * nadpisuje. Kwoty zwycięzcy i min/max są takie jak w ogłoszeniu, w walucie `currency`, bez przeliczania.
 * `created_by_bzp` — część założona automatycznie przy pierwszym powiązaniu przetargu z ogłoszeniem.
 */
class TenderLot extends Model
{
    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_NOT_SUBMITTED = 'not_submitted';

    /** wygrana / przegrana / unieważniona / nie złożyliśmy oferty */
    public const OUTCOMES = [
        self::OUTCOME_WON,
        self::OUTCOME_LOST,
        self::OUTCOME_CANCELLED,
        self::OUTCOME_NOT_SUBMITTED,
    ];

    /** cena / nie spełniliśmy wymagania / termin dostawy / błąd formalny / inny — tylko przy przegranej, tylko człowiek */
    public const LOSS_REASONS = [
        'price',
        'requirement',
        'delivery',
        'formal',
        'other',
    ];

    /**
     * Pola, które może wypełnić Biuletyn (nazwy jak w API części). „winner” obejmuje winner_competitor_id
     * i winner_national_id_raw. Powód przegranej, notatka i nasza cena są tylko ręczne.
     */
    public const BZP_FIELDS = [
        'name',
        'cpv_main',
        'estimated_value',
        'outcome',
        'winner',
        'winner_price',
        'currency',
        'offers_count',
        'lowest_price',
        'highest_price',
    ];

    /**
     * Wpis w manual_fields: człowiek potwierdził, że numer tej części jest numerem części z ogłoszenia (np. samotna
     * część nr 1 przy ogłoszeniu z wieloma częściami — „startowaliśmy w części 1”). Zmiana numeru części bez
     * ponownego potwierdzenia i zmiana numeru ogłoszenia kasują potwierdzenie.
     */
    public const LOT_NO_CONFIRMED = 'lot_no';

    protected $fillable = [
        'tender_id',
        'lot_no',
        'name',
        'cpv_main',
        'estimated_value',
        'our_net',
        'our_vat_rate',
        'outcome',
        'winner_competitor_id',
        'winner_national_id_raw',
        'winner_price',
        'currency',
        'offers_count',
        'lowest_price',
        'highest_price',
        'loss_reason',
        'note',
        'manual_fields',
        'bzp_notice_id',
        'bzp_applied_at',
        'created_by_bzp',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'lot_no' => 'integer',
            'estimated_value' => 'decimal:2',
            'our_net' => 'decimal:2',
            'our_vat_rate' => 'decimal:2',
            'winner_price' => 'decimal:2',
            'offers_count' => 'integer',
            'lowest_price' => 'decimal:2',
            'highest_price' => 'decimal:2',
            'manual_fields' => 'array',
            'bzp_applied_at' => 'datetime',
            'created_by_bzp' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Competitor::class, 'winner_competitor_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(TenderLotOffer::class)->orderBy('price');
    }

    public function bzpNotice(): BelongsTo
    {
        return $this->belongsTo(ProcurementNotice::class, 'bzp_notice_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
