<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Usługa z katalogu ERP XL (Twr_Typ 4), np. „PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6” — do pozycji modułu Przeglądy.
 * Zapis: nocny odczyt erp:inspections. Osobno od erp_items (towary), które zasilają łączenie z kartami i Zapasy.
 */
class ErpService extends Model
{
    protected $fillable = [
        'xl_gid',
        'xl_type',
        'code',
        'name',
        'unit',
        'archived',
        'synced_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'xl_gid' => 'integer',
            'xl_type' => 'integer',
            'archived' => 'boolean',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
