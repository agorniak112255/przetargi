<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Miesięczny cel sprzedaży handlowca (netto PLN). Ustala osoba z reports.targets.manage (set_by).
 *
 * @property int $user_id
 * @property int $year
 * @property int $month
 * @property string $amount
 */
class SalesTarget extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'month',
        'amount',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:2',
            'set_by' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
