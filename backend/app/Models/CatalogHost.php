<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wynik ostatniego przejścia catalog:index po domenie — także gdy sitemap
 * nic nie dała, żeby --missing-only nie próbowało jej przy każdym deployu.
 */
class CatalogHost extends Model
{
    protected $fillable = [
        'host',
        'pages_count',
        'off_host_count',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
    ];
}
