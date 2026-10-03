<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decyzja „pominięte” przy ogłoszeniu o zamówieniu (zakładka Ogłoszenia) — jedna na ogłoszenie, wspólna dla zespołu;
 * `user_id` = kto pominął (null po usunięciu konta).
 */
class ProcurementNoticeSkip extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'procurement_notice_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(ProcurementNotice::class, 'procurement_notice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
