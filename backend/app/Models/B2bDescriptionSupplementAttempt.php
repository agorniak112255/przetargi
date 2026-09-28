<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Próba uzupełnienia krótkiego opisu B2B ze stron konta (B2bDescriptionSupplement, SupplementB2bDescriptionJob).
 * Wynik i źródła próby zostają tu, także gdy karta zostaje z opisem z B2B — widać, że program szukał i czego nie znalazł.
 */
class B2bDescriptionSupplementAttempt extends Model
{
    public const STATUS_QUEUED = 'queued';

    /** Opis karty zastąpiony opisem z tekstu B2B i stron. */
    public const STATUS_REPLACED = 'replaced';

    /** Strony znalezione, ale opis odrzucony (nie dłuższy, twierdzenia spoza źródeł…) — karta z opisem z B2B. */
    public const STATUS_KEPT = 'kept_b2b';

    /** Żadna potwierdzona strona z sieci — karta z opisem z B2B. */
    public const STATUS_NO_PAGES = 'no_pages';

    /** Błąd w trakcie (model, sieć) — do ponowienia. */
    public const STATUS_FAILED = 'failed';

    /** Job pracuje nad kartą — etap w kolumnie stage, początek w started_at. */
    public const STATUS_RUNNING = 'running';

    /**
     * Zatrzymane przyciskiem „Zatrzymaj” (B2bDescriptionSupplement::stop) — karta nietknięta. Nie jest wynikiem
     * ostatecznym: przycisk „Uzupełnij krótkie opisy” ją wznawia, nocna synchronizacja nie.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /** Karta w toku — czeka w kolejce albo job nad nią pracuje. */
    public const PENDING_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    /** Wynik ostateczny dla tego wejścia (tekst źródła + lista stron) — bez ponowień, dopóki wejście się nie zmieni. */
    public const FINAL_STATUSES = [self::STATUS_REPLACED, self::STATUS_KEPT, self::STATUS_NO_PAGES];

    /**
     * Tyle nieudanych prób (dwa zlecenia po dwie próby joba) dla tego samego wejścia, po których synchronizacja przestaje
     * zlecać kartę — inaczej błąd stały (strona psująca model) wracałby co noc. Przycisk „także karty już próbowane”
     * i zmiana tekstu źródła albo listy stron zlecają ją dalej.
     */
    public const MAX_FAILED_ATTEMPTS = 4;

    protected $fillable = [
        'product_id',
        'b2b_account_id',
        'source_sha1',
        'hosts_sha1',
        'status',
        'stage',
        'attempts',
        'result_sha1',
        'source_urls',
        'message',
        'attempted_at',
        'started_at',
        'retry_at',
    ];

    protected function casts(): array
    {
        return [
            'source_urls' => 'array',
            'attempts' => 'integer',
            'attempted_at' => 'datetime',
            'started_at' => 'datetime',
            'retry_at' => 'datetime',
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
}
