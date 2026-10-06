<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pozycja modułu Przeglądy: towar albo usługa z ERP XL z interwałem przeglądu wybranym przez człowieka. Sprzedaż tej
 * pozycji klientowi = początek odliczania; termin = data sprzedaży + interval_months. Towar może mieć usługę, która go
 * odnawia (renewed_by_xl_gid) — sprzedaż tej usługi po zakupie zamyka termin zakupu. Interwału system nie wymyśla.
 */
class InspectionPosition extends Model
{
    /** Interwały do wyboru (decyzja właściciela 06.10.2026), w miesiącach. */
    public const INTERVALS = [1, 3, 6, 9, 12, 15, 18, 24];

    public const TYPE_GOODS = 1;

    public const TYPE_SERVICE = 4;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SUGGESTION = 'suggestion';

    protected $fillable = [
        'xl_gid',
        'xl_type',
        'code',
        'name',
        'unit',
        'interval_months',
        'renewed_by_xl_gid',
        'note',
        'active',
        'source',
        'pattern_position_id',
        'history_loaded_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'xl_gid' => 'integer',
            'xl_type' => 'integer',
            'interval_months' => 'integer',
            'renewed_by_xl_gid' => 'integer',
            'active' => 'boolean',
            'pattern_position_id' => 'integer',
            'history_loaded_at' => 'datetime',
        ];
    }

    /** @return HasMany<InspectionDue, $this> */
    public function due(): HasMany
    {
        return $this->hasMany(InspectionDue::class);
    }

    /** @return BelongsTo<InspectionPosition, $this> */
    public function pattern(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pattern_position_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
