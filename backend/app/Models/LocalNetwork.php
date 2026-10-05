<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Adres sieci lokalnej (IPv4/IPv6 albo zakres CIDR) — zob. NetworkAccessPolicy. */
class LocalNetwork extends Model
{
    protected $fillable = [
        'address',
        'label',
    ];
}
