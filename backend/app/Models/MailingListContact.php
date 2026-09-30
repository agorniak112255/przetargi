<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Adres w grupie z podstawą wysyłki: customer = stały klient, consent = zgoda (skąd — basis_note). */
class MailingListContact extends Pivot
{
    public const BASIS_CUSTOMER = 'customer';

    public const BASIS_CONSENT = 'consent';

    public const BASES = [self::BASIS_CUSTOMER, self::BASIS_CONSENT];

    protected $table = 'mailing_list_contact';

    public $incrementing = true;
}
