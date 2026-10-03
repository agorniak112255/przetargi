<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nagłówek faktury albo paragonu klienta z Comarch ERP XL (kopia nocna, erp:client-documents). Wartość netto PLN
 * = suma TrE_KsiegowaNetto pozycji; korekty mają wartość ze znakiem (zwykle ujemną). Jedno źródło sprzedaży dla
 * karty klienta, celów handlowców i podpowiedzi zamówień.
 *
 * @property int $document_type
 * @property int $document_id
 * @property string $document_number
 * @property string $kind
 * @property int $customer_xl_gid
 * @property int|null $client_id
 * @property string $net_value
 */
class ErpSaleDocument extends Model
{
    public const KIND_INVOICE = 'invoice';

    public const KIND_RECEIPT = 'receipt';

    public const KIND_EXPORT_INVOICE = 'export_invoice';

    public const KIND_INVOICE_CORRECTION = 'invoice_correction';

    public const KIND_RECEIPT_CORRECTION = 'receipt_correction';

    public const KINDS = [
        self::KIND_INVOICE,
        self::KIND_RECEIPT,
        self::KIND_EXPORT_INVOICE,
        self::KIND_INVOICE_CORRECTION,
        self::KIND_RECEIPT_CORRECTION,
    ];

    /** Typ dokumentu XL (TrN_GIDTyp) → rodzaj: FS, PA, FSE (eksportowa) i korekty FS / PA — jak erp:clients. */
    public const TYPE_KIND = [
        2033 => self::KIND_INVOICE,
        2034 => self::KIND_RECEIPT,
        2037 => self::KIND_EXPORT_INVOICE,
        2041 => self::KIND_INVOICE_CORRECTION,
        2042 => self::KIND_RECEIPT_CORRECTION,
    ];

    /** Rodzaje sprzedaży bez korekt (liczba dokumentów, „ostatni zakup”). */
    public const SALE_KINDS = [self::KIND_INVOICE, self::KIND_RECEIPT, self::KIND_EXPORT_INVOICE];

    protected $fillable = [
        'document_type',
        'document_id',
        'document_number',
        'kind',
        'issued_at',
        'customer_xl_gid',
        'client_id',
        'net_value',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => 'integer',
            'document_id' => 'integer',
            'issued_at' => 'date:Y-m-d',
            'customer_xl_gid' => 'integer',
            'client_id' => 'integer',
            'net_value' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
