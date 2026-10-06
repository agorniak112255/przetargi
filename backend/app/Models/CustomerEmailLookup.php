<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ostatnie szukanie adresu e-mail klienta XL w sieci — żeby noc nie szukała tych samych klientów codziennie. */
class CustomerEmailLookup extends Model
{
    protected $fillable = ['customer_xl_gid', 'checked_at', 'found', 'error'];

    protected function casts(): array
    {
        return [
            'customer_xl_gid' => 'integer',
            'checked_at' => 'datetime',
            'found' => 'integer',
        ];
    }
}
