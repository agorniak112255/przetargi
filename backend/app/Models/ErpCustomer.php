<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kontrahent z Comarch ERP XL, który kupował (FS/PA, faktury do WZ) — adresy e-mail i operator, który najczęściej
 * wystawiał mu dokumenty. Zapis: App\Services\Erp\ErpCustomerSync (co noc); XL tylko czytany. Adres, telefony, osoby
 * kontaktowe i opiekun — tylko klienci z terminami przeglądów (App\Services\Inspections\InspectionCustomerDetails).
 */
class ErpCustomer extends Model
{
    protected $fillable = [
        'xl_gid',
        'acronym',
        'name',
        'nip',
        'city',
        'emails',
        'archived',
        'last_sale_at',
        'sale_documents_24m',
        'main_operator',
        'street',
        'address_line2',
        'postal_code',
        'voivodeship',
        'phone',
        'phone2',
        'contacts',
        'account_manager',
        'account_manager_email',
        'details_synced_at',
        'synced_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'xl_gid' => 'integer',
            'emails' => 'array',
            'archived' => 'boolean',
            'last_sale_at' => 'date',
            'sale_documents_24m' => 'integer',
            'contacts' => 'array',
            'details_synced_at' => 'datetime',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return HasMany<ErpCustomerItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ErpCustomerItem::class);
    }
}
