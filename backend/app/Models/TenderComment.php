<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderComment extends Model
{
    protected $fillable = [
        'tender_id',
        'tender_item_id',
        'user_id',
        'body',
        'mentioned_user_ids',
    ];

    protected function casts(): array
    {
        return [
            // osoby wybrane z listy „@” (identyfikatory użytkowników) — null = komentarz bez wzmianek
            'mentioned_user_ids' => 'array',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenderItem::class, 'tender_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
