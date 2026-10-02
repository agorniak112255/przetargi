<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Klient (zamawiający). Dopisany ręcznie (source = manual) albo z Comarch ERP XL (source = erp_xl, erp:clients) —
 * wtedy pola karty, osoby kontaktowe, opiekun i zakupy w roku są kopią z XL odświeżaną co noc.
 */
class Client extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_ERP_XL = 'erp_xl';

    protected $fillable = [
        'name',
        'acronym',
        'nip',
        'nip_prefix',
        'regon',
        'street',
        'address_line2',
        'postal_code',
        'city',
        'county',
        'commune',
        'voivodeship',
        'country',
        'phone',
        'phone2',
        'fax',
        'emails',
        'website',
        'contacts',
        'account_manager',
        'account_manager_email',
        'owner_id',
        'source',
        'xl_gid',
        'xl_archived',
        'sales_year',
        'sales_net',
        'sale_documents',
        'last_sale_at',
        'xl_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'emails' => 'array',
            'contacts' => 'array',
            'xl_gid' => 'integer',
            'xl_archived' => 'boolean',
            'sales_year' => 'integer',
            'sales_net' => 'decimal:2',
            'sale_documents' => 'integer',
            'last_sale_at' => 'date:Y-m-d',
            'xl_synced_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(Tender::class);
    }
}
