<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Podpowiedź „możliwe, że to zamówienie z tej oferty” (inquiries:order-hints): dokument sprzedaży z ERP XL klienta
 * zapytania z towarami oferty. Wniosek, nie fakt — wynik zapytania wpisuje tylko handlowiec.
 *
 * Podpowiedź obowiązuje tylko dla kontrahenta, dla którego ją policzono (customer_xl_gid = clients.xl_gid bieżącego
 * klienta zapytania) — scope ofCurrentClient. Zmiana klienta zapytania kasuje podpowiedzi (InquiryClientLinker), a ten
 * warunek pilnuje odczytu, gdyby nocny przebieg i zmiana klienta się minęły.
 *
 * @property int $client_inquiry_id
 * @property int $customer_xl_gid
 * @property int $document_type
 * @property int $document_id
 * @property string $document_number
 */
class InquiryOrderHint extends Model
{
    protected $fillable = [
        'client_inquiry_id',
        'customer_xl_gid',
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
            'customer_xl_gid' => 'integer',
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

    /**
     * Tylko podpowiedzi policzone dla obecnego klienta zapytania (ten sam kontrahent XL).
     *
     * @param  Builder<InquiryOrderHint>  $query
     * @return Builder<InquiryOrderHint>
     */
    public function scopeOfCurrentClient(Builder $query): Builder
    {
        return $query->whereExists(static function (QueryBuilder $sub): void {
            $sub->selectRaw('1')
                ->from('client_inquiries')
                ->join('clients', 'clients.id', '=', 'client_inquiries.client_id')
                ->whereColumn('client_inquiries.id', 'inquiry_order_hints.client_inquiry_id')
                ->whereColumn('clients.xl_gid', 'inquiry_order_hints.customer_xl_gid');
        });
    }
}
