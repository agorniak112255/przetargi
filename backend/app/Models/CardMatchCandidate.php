<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Propozycja połączenia karty dystrybutora (source) z kartą producenta (target) — ekran „Łączenie kart”.
 * Powstaje przy odświeżeniu propozycji (CardMatchFinder) tylko z pewnego klucza; decyzję podejmuje człowiek.
 */
class CardMatchCandidate extends Model
{
    /** Pewna para — czeka na decyzję. */
    public const STATUS_PENDING = 'pending';

    /** Klucz wskazuje kartę, ale łączenie byłoby niebezpieczne albo niejednoznaczne — tylko do wglądu, z powodem. */
    public const STATUS_CONFLICT = 'conflict';

    /** Człowiek odrzucił — para nie wraca przy odświeżeniu. */
    public const STATUS_REJECTED = 'rejected';

    /** Połączona (ProductSizeMergeService::mergeDuplicate) — ślad decyzji. */
    public const STATUS_MERGED = 'merged';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONFLICT, self::STATUS_REJECTED, self::STATUS_MERGED];

    /** EAN pozycji (rozmiaru) — poprawna suma GS1, bez EAN-ów wewnętrznych i opakowań. */
    public const BY_EAN = 'ean';

    /** Kod producenta w tej samej marce kanonicznej (CanonicalBrand). */
    public const BY_MANUFACTURER_CODE = 'manufacturer_code';

    /** Klucze karty dystrybutora wskazują dokładnie jedną kartę producenta — połączenie dwóch kart. */
    public const KIND_MERGE = 'merge';

    /** Producent ma osobną kartę na każdy rozmiar w tej samej cenie — połączenie kart rozmiarów w jedną. */
    public const KIND_SIZE_MERGE = 'size_merge';

    /** Pozycje karty dystrybutora trafiają w kilka kart producenta (kolory, rozmiary w różnych cenach) — rozdzielenie. */
    public const KIND_SPLIT = 'split';

    public const KINDS = [self::KIND_MERGE, self::KIND_SIZE_MERGE, self::KIND_SPLIT];

    /** Pozycje różnią się rozmiarem (etykieta dystrybutora i nazwy kart producenta). */
    public const SIGNAL_SIZE = 'size';

    /** Pozycje różnią się kolorem. */
    public const SIGNAL_COLOR = 'color';

    /** Nie wiadomo — decyduje człowiek. */
    public const SIGNAL_UNKNOWN = 'unknown';

    public const SIGNALS = [self::SIGNAL_SIZE, self::SIGNAL_COLOR, self::SIGNAL_UNKNOWN];

    protected $fillable = [
        'source_product_id',
        'target_product_id',
        'status',
        'matched_by',
        'matched_value',
        'matched_source_key',
        'brand',
        'hits',
        'positions',
        'reason',
        'conflict_product_ids',
        'kind',
        'plan',
        'targets_key',
        'plan_hash',
        'decision_input',
        'source_snapshot',
        'backup_path',
        'decided_by',
        'decided_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'source_product_id' => 'integer',
            'target_product_id' => 'integer',
            'hits' => 'integer',
            'positions' => 'integer',
            'conflict_product_ids' => 'array',
            'plan' => 'array',
            'source_snapshot' => 'array',
            'decision_input' => 'array',
            'decided_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Klucz zestawu kart docelowych (UNIQUE z source_product_id): posortowane rosnąco id połączone przecinkiem, dłuższy
     * niż 255 znaków — „sha1:” i skrót listy.
     *
     * @param  list<int>  $ids
     */
    public static function targetsKeyFor(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $key = implode(',', $ids);

        return strlen($key) > 255 ? 'sha1:'.sha1($key) : $key;
    }

    /**
     * Wiersz zapisany bez targets_key (ręcznie, w testach, sprzed kroku 5): klucz z karty docelowej albo z listy kart
     * konfliktu; wiersz bez żadnej karty — „gone:{id}” (jak w migracji), do czasu nadania id klucz tymczasowy
     * unikalny, żeby UNIQUE nie łączyło dwóch takich wierszy jednej karty.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ((string) $row->targets_key !== '') {
                return;
            }
            if ($row->target_product_id !== null) {
                $row->targets_key = (string) $row->target_product_id;
            } elseif (is_array($row->conflict_product_ids) && $row->conflict_product_ids !== []) {
                $row->targets_key = self::targetsKeyFor($row->conflict_product_ids);
            } else {
                $row->targets_key = 'new:'.Str::uuid()->toString();
            }
        });
        static::created(static function (self $row): void {
            if (str_starts_with((string) $row->targets_key, 'new:')) {
                $key = 'gone:'.$row->id;
                static::query()->toBase()->where('id', $row->id)->update(['targets_key' => $key]);
                $row->targets_key = $key;
                $row->syncOriginalAttribute('targets_key');
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
