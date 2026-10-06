<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wyliczony termin przeglądu: klient XL × pozycja. Tylko klienci z co najmniej jedną nieodnowioną sprzedażą pozycji.
 * Przebudowa: App\Services\Inspections\InspectionDueBuilder (nocą i po zmianie pozycji). Stan (zaległy, w oknie)
 * liczy się przy odczycie według polskiej daty — tu tylko daty.
 */
class InspectionDue extends Model
{
    protected $table = 'inspection_due';

    protected $fillable = [
        'customer_xl_gid',
        'inspection_position_id',
        'due_on',
        'open_count',
        'open_quantity',
        'last_on',
        'last_quantity',
        'last_net',
        'last_documents',
        'first_on',
        'recipient_xl_gid',
        'location',
        'operator_ident',
        'same_nip_newer',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_xl_gid' => 'integer',
            'inspection_position_id' => 'integer',
            'due_on' => 'date',
            'open_count' => 'integer',
            'open_quantity' => 'decimal:3',
            'last_on' => 'date',
            'last_quantity' => 'decimal:3',
            'last_net' => 'decimal:2',
            'last_documents' => 'array',
            'first_on' => 'date',
            'recipient_xl_gid' => 'integer',
            'same_nip_newer' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InspectionPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(InspectionPosition::class, 'inspection_position_id');
    }
}
