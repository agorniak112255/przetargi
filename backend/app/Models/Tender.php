<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\OfferPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tender extends Model
{
    protected $fillable = [
        'number',
        'title',
        'client_id',
        'owner_id',
        'deadline',
        'status',
        'ai_percent',
        'offer_value_net',
        'margin_percent',
        'target_margin_percent',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'last_activity_at' => 'datetime',
            'offer_value_net' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'target_margin_percent' => 'decimal:2',
        ];
    }

    public function targetMarkupPercent(): float
    {
        if ($this->target_margin_percent !== null) {
            return (float) $this->target_margin_percent;
        }

        return OfferPricing::markupPercent();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TenderItem::class)->orderBy('line_no');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(TenderCondition::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenderDocument::class)->latest();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TenderStatusHistory::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TenderActivity::class)->latest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TenderComment::class)->latest('id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TenderInvitation::class)->latest('id');
    }

    /**
     * Przetargi użytkownika jako opiekun albo zaproszony.
     *
     * @param  Builder<Tender>  $query
     * @return Builder<Tender>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $userId = (int) $user->id;

        return $query->where(function (Builder $builder) use ($userId): void {
            $builder->where('owner_id', $userId)
                ->orWhereHas('invitations', static function (Builder $invitations) use ($userId): void {
                    $invitations->where('user_id', $userId);
                });
        });
    }
}
