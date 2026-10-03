<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Podpowiedź „możliwe, że to zamówienie z tej oferty” (inquiries:order-hints): dokument sprzedaży z ERP XL klienta
 * zapytania z towarami oferty. Wniosek, nie fakt — wynik zapytania wpisuje tylko handlowiec.
 *
 * @property int $client_inquiry_id
 * @property int $document_type
 * @property int $document_id
 * @property string $document_number
 */
class InquiryOrderHint extends Model
{
    protected $fillable = [
        'client_inquiry_id',
        'document_type',
        'document_id',
        'document_number',
        'issued_at',
        'document_net',
        'matched_net',
        'offered_items',
        'linked_items',
        'matched_items',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'client_inquiry_id' => 'integer',
            'document_type' => 'integer',
            'document_id' => 'integer',
            'issued_at' => 'date:Y-m-d',
            'document_net' => 'decimal:2',
            'matched_net' => 'decimal:2',
            'offered_items' => 'integer',
            'linked_items' => 'integer',
            'matched_items' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ClientInquiry::class, 'client_inquiry_id');
    }
}
