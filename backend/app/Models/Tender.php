<?php

declare(strict_types=1);

namespace App\Models;

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
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'last_activity_at' => 'datetime',
            'offer_value_net' => 'decimal:2',
            'margin_percent' => 'decimal:2',
        ];
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
}

