<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pozycja faktury sprzedaży (FS 2033, FSE 2037) albo jej korekty (2041, ilość i wartość ze znakiem) z ERP XL —
 * wszystkie usługi (Twr_Typ 4) i towary pozycji Przeglądów. Kopia dosłowna; zapis: erp:inspections.
 */
class InspectionSaleLine extends Model
{
    protected $fillable = [
        'document_type',
        'document_id',
        'line',
        'document_number',
        'issued_on',
        'sold_on',
        'customer_xl_gid',
        'recipient_xl_gid',
        'xl_item_gid',
        'xl_item_type',
        'quantity',
        'net_value',
        'warehouse_code',
        'location',
        'operator_ident',
        'corrects_document_type',
        'corrects_document_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => 'integer',
            'document_id' => 'integer',
            'line' => 'integer',
            'issued_on' => 'date',
            'sold_on' => 'date',
            'customer_xl_gid' => 'integer',
            'recipient_xl_gid' => 'integer',
            'xl_item_gid' => 'integer',
            'xl_item_type' => 'integer',
            'quantity' => 'decimal:3',
            'net_value' => 'decimal:2',
            'corrects_document_type' => 'integer',
            'corrects_document_id' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
