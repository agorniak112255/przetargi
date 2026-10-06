<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * „Pomiń klienta” w Przeglądach: wszystkie przeglądy klienta (inspection_position_id = null) albo jedna pozycja,
 * na zawsze (until_on = null) albo do daty. Bez tego lista co roku rośnie o klientów, którzy odeszli.
 */
class InspectionDismissal extends Model
{
    /** Powody do wyboru: robi u innej firmy, zrezygnował, pomiń, inny. */
    public const REASONS = ['other_company', 'resigned', 'skip', 'other'];

    protected $fillable = [
        'customer_xl_gid',
        'inspection_position_id',
        'until_on',
        'reason',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_xl_gid' => 'integer',
            'inspection_position_id' => 'integer',
            'until_on' => 'date',
            'user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<InspectionPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(InspectionPosition::class, 'inspection_position_id');
    }
}
