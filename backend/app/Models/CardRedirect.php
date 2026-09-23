<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapa połączeń: kod ze źródła (konto B2B albo cennik z pliku + kod pozycji) → karta, na którą ma trafiać po decyzji
 * człowieka. product_id null = karta docelowa usunięta („decyzja bez karty”); target_snapshot = karta z chwili decyzji.
 * Zapis: App\Services\Catalog\CardRedirectStore.
 */
class CardRedirect extends Model
{
    /** Połączenie karty dystrybutora z kartą producenta (ekran „Łączenie kart”, products:merge-duplicate). */
    public const REASON_MERGE = 'merge';

    /** Łączenie rozmiarów w kartę modelu (etap C3). */
    public const REASON_SIZE_MERGE = 'size_merge';

    /** Rozdzielenie karty dystrybutora na karty producenta (etap C2). */
    public const REASON_SPLIT = 'split';

    public const REASONS = [self::REASON_MERGE, self::REASON_SIZE_MERGE, self::REASON_SPLIT];

    protected $fillable = [
        'source_key',
        'position_key',
        'b2b_account_id',
        'price_list_id',
        'product_id',
        'reason',
        'is_anchor',
        'position_label',
        'remote_sku',
        'target_snapshot',
        'card_match_candidate_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'b2b_account_id' => 'integer',
            'price_list_id' => 'integer',
            'product_id' => 'integer',
            'card_match_candidate_id' => 'integer',
            'created_by' => 'integer',
            'is_anchor' => 'boolean',
            'target_snapshot' => 'array',
        ];
    }

    /** Wiersze mapy wskazujące kartę. */
    public static function forProduct(int $productId): Collection
    {
        return static::query()
            ->where('product_id', $productId)
            ->orderBy('source_key')
            ->orderBy('position_key')
            ->get();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CardMatchCandidate::class, 'card_match_candidate_id');
    }
}
