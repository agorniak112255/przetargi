<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Campaigns\CampaignBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kampania reklamowa: towar (zwykle zalegający w XL) wysyłany mailem do klientów ze skrzynki autora.
 * Projekt → (Zaplanowana) → Wysyłka → Wysłana (albo Anulowana). Po starcie wysyłki kampanii nie edytuje się — duplikuje.
 */
class Campaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    /** Zaplanowana: nie do edycji, campaigns:dispatch wystartuje ją o scheduled_at. */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Układy bloku produktów (CampaignRenderer::COLUMNS, emails/campaign.blade.php). Usunięte układy z zapisanych
     * kampanii i szablonów zamienia CampaignBlocks::REPLACED_LAYOUTS.
     */
    public const LAYOUTS = ['grid3', 'grid2', 'list', 'grid2_desc', 'list_desc', 'pricelist'];

    public const XL_MODES = ['items', 'group', 'mine'];

    public const XL_MONTHS = [12, 24];

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'subject',
        'preheader',
        'heading',
        'intro',
        'layout',
        'template_id',
        'blocks',
        'brand_color',
        'valid_until',
        'status',
        'audience',
        'scheduled_at',
        'schedule_error',
        'sending_started_at',
        'sent_at',
        'totals',
        'duplicated_from_id',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'totals' => 'array',
            'blocks' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // kod w temacie „Zapytaj o ofertę” wiąże odpowiedź klienta z kampanią
        static::created(static function (Campaign $campaign): void {
            if ($campaign->code === null) {
                $campaign->forceFill(['code' => 'K-'.str_pad((string) $campaign->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Bloki treści maila (App\Services\Campaigns\CampaignBlocks). Kampania sprzed szablonów (blocks = null) ma bloki
     * wyliczone z heading, intro i layout — wygląda jak dotąd.
     *
     * @return list<array<string, mixed>>
     */
    public function effectiveBlocks(): array
    {
        return is_array($this->blocks) ? CampaignBlocks::upgrade($this->blocks) : CampaignBlocks::legacy($this);
    }

    /**
     * Odbiorcy z domyślnymi wartościami — kształt z kontraktu API.
     *
     * customer_ids: null = cała kategoria klientów XL; lista = tylko ci zaznaczeni w oknie „Pokaż / wybierz”
     * (przy wysyłce przecięta z kategorią, więc klient, który z niej wypadł, nie dostanie maila).
     *
     * @return array{list_ids: list<int>, xl: array{mode: string|null, months: int, only_mine: bool, customer_ids: list<int>|null}}
     */
    public function audienceSettings(): array
    {
        $a = is_array($this->audience) ? $this->audience : [];
        $xl = is_array($a['xl'] ?? null) ? $a['xl'] : [];
        $mode = $xl['mode'] ?? null;
        $months = (int) ($xl['months'] ?? 24);
        $customerIds = $xl['customer_ids'] ?? null;

        return [
            'list_ids' => array_values(array_map('intval', is_array($a['list_ids'] ?? null) ? $a['list_ids'] : [])),
            'xl' => [
                'mode' => in_array($mode, self::XL_MODES, true) ? $mode : null,
                'months' => in_array($months, self::XL_MONTHS, true) ? $months : 24,
                'only_mine' => (bool) ($xl['only_mine'] ?? false),
                'customer_ids' => is_array($customerIds) ? array_values(array_unique(array_map('intval', $customerIds))) : null,
            ],
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CampaignTemplate, $this> szablon, z którego skopiowano bloki (null po usunięciu szablonu) */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'template_id');
    }

    /** @return HasMany<CampaignItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CampaignItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** @return HasMany<CampaignReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(CampaignReply::class);
    }
}
