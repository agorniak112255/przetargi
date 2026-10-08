<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wersja opisu karty (08.10.2026) — zapis, propozycja albo przebieg w cieniu z werdyktem tożsamości strony źródła,
 * liczbą dowodów i decyzją przeglądu. Reguły zapisu i porównania: App\Services\Enrichment\DescriptionVersionStore.
 *
 * superseded powstaje tylko z published (record() przy nowej published), więc oznacza opis, który był na karcie.
 * Propozycja po decyzji zostaje proposed z decision approved/url_given — czeka tylko propozycja bez decyzji.
 *
 * @property array<string, mixed>|null $enrichment_payload
 * @property array<string, mixed>|null $enrichment_trace
 */
class ProductDescriptionVersion extends Model
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SHADOW = 'shadow';

    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_PROPOSED,
        self::STATUS_SUPERSEDED,
        self::STATUS_REJECTED,
        self::STATUS_SHADOW,
    ];

    public const ORIGIN_ENRICHMENT = 'enrichment';

    public const ORIGIN_SKU_CACHE = 'sku_cache';

    public const ORIGIN_RESTORE = 'restore';

    public const ORIGIN_REVIEW_APPROVE = 'review_approve';

    public const ORIGIN_LEGACY_BASELINE = 'legacy_baseline';

    public const ORIGIN_STORED_SOURCES = 'stored_sources';

    /** opis członka modelu przepisany z wersji lidera (etap 2; lider w enrichment_payload.model_group) */
    public const ORIGIN_MODEL_SHARED = 'model_shared';

    public const ORIGINS = [
        self::ORIGIN_ENRICHMENT,
        self::ORIGIN_SKU_CACHE,
        self::ORIGIN_RESTORE,
        self::ORIGIN_REVIEW_APPROVE,
        self::ORIGIN_LEGACY_BASELINE,
        self::ORIGIN_STORED_SOURCES,
        self::ORIGIN_MODEL_SHARED,
    ];

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_URL_GIVEN = 'url_given';

    public const DECISION_RESTORED = 'restored';

    public const VERDICT_HARD = 'hard';

    public const VERDICT_SOFT = 'soft';

    public const VERDICT_NONE = 'none';

    protected $fillable = [
        'product_id',
        'status',
        'origin',
        'description',
        'enrichment_payload',
        'enrichment_trace',
        'packaging',
        'description_sha1',
        'primary_source_url',
        'identity_verdict',
        'identity_reason',
        'evidence_count',
        'completeness',
        'review_reason',
        'reason',
        'batch_id',
        'created_by',
        'decision',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'enrichment_payload' => 'array',
            'enrichment_trace' => 'array',
            'evidence_count' => 'integer',
            'completeness' => 'decimal:3',
            'batch_id' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Najnowsza wersja z przebiegu karty w partii (zapis albo propozycja; także zapis już zastąpiony kolejnym) —
     * podstawa opisu członków modelu, gdy zadanie lidera nie zdążyło jej przekazać (ponowienie po wyjątku, limit czasu,
     * zabity worker). Odrzucona propozycja nie wraca jako podstawa.
     */
    public static function latestOfRun(int $productId, int $batchId): ?self
    {
        return self::query()
            ->where('product_id', $productId)
            ->where('batch_id', $batchId)
            ->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_PROPOSED, self::STATUS_SUPERSEDED])
            ->whereNotNull('description')
            ->orderByDesc('id')
            ->first();
    }
}
