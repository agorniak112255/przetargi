<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Szablon maila kampanii: bloki (App\Services\Campaigns\CampaignBlocks) i kolor. Własny użytkownika albo wspólny
 * (is_shared, prowadzi campaigns.manage). Kampania dostaje kopię bloków — zmiana szablonu jej nie zmienia.
 */
class CampaignTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'is_shared',
        'brand_color',
        'blocks',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'blocks' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> autor (null po usunięciu konta) */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
