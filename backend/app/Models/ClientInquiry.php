<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientInquiry extends Model
{
    /** Pełna specyfikacja: nazwa z katalogu, SKU, producent, normy i cena. */
    public const TONE_HANDLOWY = 'handlowy';

    /** Oficjalny: nazwa, akapit opisu z karty, zdjęcie, normy i cena — bez SKU. */
    public const TONE_FORMAL = 'formal';

    /** Oficjalny krótki: nazwa, dwa–trzy zdania opisu z karty, zdjęcie, normy i cena — bez SKU. */
    public const TONE_FORMAL_SHORT = 'formal_krotki';

    /** Bez SKU: jedno zdanie opisu bez marki i modelu, normy i cena. */
    public const TONE_NO_SKU = 'bez_sku';

    /**
     * Stan analizy w tle (AnalyzeClientInquiryJob). null = zapytanie policzone jeszcze w żądaniu, czyli gotowe.
     * „running” bez końca po ANALYSIS_STALE_MINUTES uznajemy za przerwane (worker zabity w trakcie).
     */
    public const ANALYSIS_QUEUED = 'queued';

    public const ANALYSIS_RUNNING = 'running';

    public const ANALYSIS_DONE = 'done';

    public const ANALYSIS_FAILED = 'failed';

    public const ANALYSIS_STALE_MINUTES = 25;

    /** Szablony listu do klienta; wybór zapisuje się w kolumnie „tone”. */
    public const TONES = [self::TONE_HANDLOWY, self::TONE_FORMAL, self::TONE_FORMAL_SHORT, self::TONE_NO_SKU];

    /** Szablony, w których przy naszym wyrobie stoi jego zdjęcie (tylko w liście HTML). */
    public const PHOTO_TONES = [self::TONE_FORMAL, self::TONE_FORMAL_SHORT];

    /**
     * Warunki oferty wpisywane przez handlowca — klucz w `offer_terms` i etykieta
     * w liście do klienta. Kolejność jest kolejnością wierszy w liście.
     */
    public const OFFER_TERMS = [
        'lead_time' => 'Termin realizacji',
        'delivery' => 'Koszt dostawy',
        'payment' => 'Płatność',
        'validity' => 'Ważność oferty',
    ];

    /** Wynik zapytania wpisany przez handlowca: zamówił / zamówił część / nie zamówił / nie wiadomo. */
    public const OUTCOMES = ['ordered', 'partial', 'not_ordered', 'unknown'];

    /** Powód (tylko przy partial i not_ordered): cena, termin dostawy, kupił gdzie indziej, klient nie odpowiedział. */
    public const OUTCOME_REASONS = ['price', 'lead_time', 'bought_elsewhere', 'no_response'];

    /**
     * Skąd powiązanie z klientem (client_link_source): manual — wybrał handlowiec (automat go nie rusza, także
     * „bez klienta”), email — adres nadawcy jak na karcie klienta, nip — NIP z treści maila (InquiryClientLinker).
     */
    public const LINK_SOURCES = ['manual', 'email', 'nip'];

    protected $fillable = [
        'user_id',
        'client_id',
        'tone',
        'source_channel',
        'source_subject',
        'source_message_id',
        'source_fingerprint',
        'source_fingerprint_tail',
        'duplicate_of_id',
        'source_from_name',
        'source_from_email',
        'source_sent_at',
        'contact',
        'source_body',
        'analysis',
        'answers',
        'extra_note',
        'offer_terms',
        'reply_subject',
        'reply_body',
        'reply_html',
        'replied_at',
        'send_requested_at',
        'analysis_status',
        'analysis_run_id',
        'analysis_progress',
        'analysis_error',
        'analysis_started_at',
        'analysis_finished_at',
        'client_link_source',
        'client_linked_at',
        'outcome',
        'outcome_reason',
        'outcome_by',
        'outcome_at',
        'outcome_document_number',
        'outcome_document_date',
        'outcome_net_value',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'answers' => 'array',
            'replied_at' => 'datetime',
            'send_requested_at' => 'datetime',
            'source_sent_at' => 'datetime',
            'contact' => 'array',
            'offer_terms' => 'array',
            'analysis_progress' => 'array',
            'analysis_started_at' => 'datetime',
            'analysis_finished_at' => 'datetime',
            'client_linked_at' => 'datetime',
            'outcome_by' => 'integer',
            'outcome_at' => 'datetime',
            'outcome_document_date' => 'date:Y-m-d',
            'outcome_net_value' => 'decimal:2',
        ];
    }

    /**
     * Stan analizy do pokazania i do blokady zmian: stare wiersze (null) są gotowe, a przebieg „running”
     * starszy niż ANALYSIS_STALE_MINUTES to przebieg przerwany — worker padł i nikt go już nie dokończy.
     */
    public function effectiveAnalysisStatus(): string
    {
        $status = (string) ($this->analysis_status ?? self::ANALYSIS_DONE);
        if ($status === self::ANALYSIS_RUNNING
            && $this->analysis_started_at !== null
            && $this->analysis_started_at->lt(now()->subMinutes(self::ANALYSIS_STALE_MINUTES))) {
            return self::ANALYSIS_FAILED;
        }

        return $status;
    }

    public function isAnalyzed(): bool
    {
        return $this->effectiveAnalysisStatus() === self::ANALYSIS_DONE;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Podpowiedzi „możliwe zamówienie” z ERP XL (inquiries:order-hints). */
    public function hints(): HasMany
    {
        return $this->hasMany(InquiryOrderHint::class);
    }

    public function outcomeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outcome_by');
    }
}
