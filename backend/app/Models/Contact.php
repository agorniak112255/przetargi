<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Adres e-mail odbiorcy kampanii (małe litery, jeden wiersz na adres) — wspólny dla wszystkich grup. */
class Contact extends Model
{
    protected $fillable = [
        'email',
        'name',
        'company',
        'erp_customer_id',
    ];

    /** @return BelongsToMany<MailingList, $this> */
    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(MailingList::class, 'mailing_list_contact')
            ->using(MailingListContact::class)
            ->withPivot(['id', 'basis', 'basis_note', 'added_by'])
            ->withTimestamps();
    }

    /** @return BelongsTo<ErpCustomer, $this> */
    public function erpCustomer(): BelongsTo
    {
        return $this->belongsTo(ErpCustomer::class);
    }
}
