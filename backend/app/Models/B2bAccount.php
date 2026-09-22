<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
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

    protected $fillable = [
        'username',
        'contractor_code',
        'password',
        'connector_session',
        'connector_session_saved_at',
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
        'connector_session',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            // ciasteczka po logowaniu kodem z e-maila (B2bCodeLoginSite) — sekret jak hasło
            'connector_session' => 'encrypted:array',
            'connector_session_saved_at' => 'datetime',
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
        // Trwający przebieg nigdy nie jest „do uruchomienia” — bez limitu godzin (pobranie SignProject trwa kilka godzin).
        // Przerwany przebieg wykrywa b2b:sync-due po sygnale życia (B2bSyncRun::STALE_MINUTES bez postępu).
        if ($this->last_sync_status === 'running') {
            return false;
        }
        if ($this->sync_requested_at !== null) {
            return true;
        }

        $frequency = $this->sync_frequency ?? 'off';
        $local = CarbonImmutable::instance($now)->setTimezone(self::SYNC_TIMEZONE);
        $boundary = $local->setTime(self::SYNC_FROM_HOUR, 0);
        if ($frequency === 'off' || $local->lessThan($boundary)) {
            return false;
        }

        $last = $this->last_sync_started_at;
        if ($last === null) {
            return true;
        }

        // Liczone od dzisiejszej 02:00, nie od godziny ostatniego przebiegu: „Sprawdź teraz” o 18:00
        // nie przesuwa kolejnego automatycznego przebiegu na dzień roboczy.
        return match ($frequency) {
            'daily' => $last->lessThan($boundary),
            'weekly' => $last->lessThan($boundary->subDays(6)),
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

    public function syncRuns(): HasMany
    {
        return $this->hasMany(B2bSyncRun::class);
    }

    /** Rabaty dla witryn podających tylko cenę katalogową; kolejność sprawdzania = position. */
    public function discountRules(): HasMany
    {
        return $this->hasMany(B2bDiscountRule::class)->orderBy('position')->orderBy('id');
    }
}
