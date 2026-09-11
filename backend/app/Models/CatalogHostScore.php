<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ile razy dana domena dała potwierdzoną kartę produktu, a ile razy nic nie wniosła.
 */
class CatalogHostScore extends Model
{
    protected $fillable = [
        'host',
        'hits',
        'misses',
        'last_hit_at',
    ];

    protected $casts = [
        'hits' => 'integer',
        'misses' => 'integer',
        'last_hit_at' => 'datetime',
    ];
}
