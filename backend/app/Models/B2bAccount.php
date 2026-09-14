<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dane logowania do witryny B2B dostawcy (np. b2b.anro.net.pl) i harmonogram pobierania cennika.
 */
class B2bAccount extends Model
{
    public const FREQUENCIES = ['off', 'daily', 'weekly'];

    public const SYNC_TIMEZONE = 'Europe/Warsaw';

    /** Automatyczne sprawdzanie rusza od tej godziny (czas polski) — noc, poza godzinami pracy. */
    public const SYNC_FROM_HOUR = 2;

    /** Przebieg „running” starszy niż tyle godzin uznajemy za przerwany (np. restart serwera). */
    public const RUNNING_STALE_HOURS = 6;

    protected $fillable = [
        'username',
        'password',
        'sites',
        'note',
        'connector',
        'sync_frequency',
        'sync_images',
        'sync_requested_at',
        'last_sync_status',
        'last_sync_started_at',
        'last_sync_finished_at',
        'last_sync_message',
        'last_price_list_id',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'sites' => 'array',
            'sync_images' => 'boolean',
            'sync_requested_at' => 'datetime',
            'last_sync_started_at' => 'datetime',
            'last_sync_finished_at' => 'datetime',
        ];
    }

    public function isSyncDue(CarbonInterface $now): bool
    {
        if (trim((string) $this->connector) === '') {
            return false;
        }
        if ($this->last_sync_status === 'running'
            && $this->last_sync_started_at !== null
            && $this->last_sync_started_at->greaterThan($now->copy()->subHours(self::RUNNING_STALE_HOURS))) {
            return false;
        }
        if ($this->sync_requested_at !== null) {
            return true;
        }

        $frequency = $this->sync_frequency ?? 'off';
        if ($frequency === 'off' || $now->copy()->setTimezone(self::SYNC_TIMEZONE)->hour < self::SYNC_FROM_HOUR) {
            return false;
        }

        $last = $this->last_sync_started_at;
        if ($last === null) {
            return true;
        }

        // Zapas kilku godzin: przebieg o 02:05 nie przesuwa kolejnego na 02:10 następnego dnia.
        return match ($frequency) {
            'daily' => $last->lessThanOrEqualTo($now->copy()->subHours(20)),
            'weekly' => $last->lessThanOrEqualTo($now->copy()->subHours(6 * 24 + 12)),
            default => false,
        };
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function productLinks(): HasMany
    {
        return $this->hasMany(B2bProductLink::class);
    }
}
