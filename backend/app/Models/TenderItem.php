<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderItem extends Model
{
    protected $fillable = [
        'tender_id',
        'line_no',
        'requirement',
        'main_product_id',
        'main_variant_id',
        'main_variant_label',
        'main_variant_sku',
        'main_variant_source',
        'companion_product_id',
        'ai_match_percent',
        'ai_match_reasons',
        'match_source',
        'battlecard_substitutes',
        'custom_name',
        'custom_url',
        'quantity',
        'offer_price',
        'companion_offer_price',
        'margin_percent',
        'status',
    ];

    protected $hidden = [
        'battlecard_substitutes',
    ];

    protected function casts(): array
    {
        return [
            'offer_price' => 'decimal:2',
            'companion_offer_price' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'ai_match_reasons' => 'array',
            'battlecard_substitutes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Wariant należy do karty: każda zmiana karty (dopasowanie, ręczny wybór, czyszczenie) bez
        // jednoczesnego wyboru wariantu zeruje wariant — jedno miejsce zamiast pilnowania każdego zapisu.
        static::saving(function (TenderItem $item): void {
            if ($item->isDirty('main_product_id') && ! $item->isDirty('main_variant_id')) {
                $item->clearVariant();
            }
        });
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function mainProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'main_product_id');
    }

    public function mainVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'main_variant_id');
    }

    /**
     * Wariant do oferty — tylko gdy należy do bieżącej karty (scalanie kart przepina main_product_id
     * zapisem bez zdarzeń modelu, wtedy stary wariant już nie obowiązuje).
     */
    public function offerVariant(): ?ProductVariant
    {
        if ($this->main_variant_id === null || $this->main_product_id === null) {
            return null;
        }
        $variant = $this->mainVariant;

        return $variant !== null && (int) $variant->product_id === (int) $this->main_product_id ? $variant : null;
    }

    public function clearVariant(): void
    {
        $this->main_variant_id = null;
        $this->main_variant_label = null;
        $this->main_variant_sku = null;
        $this->main_variant_source = null;
    }

    public function companionProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'companion_product_id');
    }

    public function lineOfferUnit(): ?float
    {
        if ($this->offer_price === null && $this->companion_offer_price === null) {
            return null;
        }

        return round((float) ($this->offer_price ?? 0) + (float) ($this->companion_offer_price ?? 0), 2);
    }

    public function clearCompanion(): void
    {
        $this->companion_product_id = null;
        $this->companion_offer_price = null;
    }

    public function hasCustomOffer(): bool
    {
        return trim((string) ($this->custom_name ?? '')) !== '';
    }

    public function isExternalHintOffer(): bool
    {
        return $this->hasCustomOffer() && $this->match_source === 'external';
    }

    public function isManualCustomOffer(): bool
    {
        return $this->hasCustomOffer() && $this->match_source !== 'external';
    }

    public function hasOfferProduct(): bool
    {
        return $this->main_product_id !== null || $this->hasCustomOffer();
    }
}
