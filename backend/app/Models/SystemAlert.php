<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alert „Stanu systemu” — jeden otwarty wiersz (resolved_at null) na incydent danego `subject_key`
 * (np. zadanie harmonogramu albo konto dostawcy). Prowadzi App\Services\System\SystemAlertService.
 */
class SystemAlert extends Model
{
    protected $fillable = [
        'kind',
        'subject_key',
        'title',
        'first_failed_at',
        'last_failed_at',
        'failures',
        'last_message',
        'emailed_at',
        'muted_at',
        'muted_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'failures' => 'integer',
            'emailed_at' => 'datetime',
            'muted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function mutedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_by');
    }

    /**
     * @param  Builder<SystemAlert>  $query
     * @return Builder<SystemAlert>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
