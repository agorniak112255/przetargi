<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'source_snapshot' => 'array',
            'decided_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
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
