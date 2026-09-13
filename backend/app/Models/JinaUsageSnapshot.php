<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Próbka salda tokenów Jina. Z różnic między próbkami liczymy tempo zużycia
 * i datę, kiedy klucz się skończy (doładowanie = saldo rośnie, nie liczy się).
 */
class JinaUsageSnapshot extends Model
{
    protected $fillable = ['tokens_left', 'taken_at'];

    protected function casts(): array
    {
        return [
            'tokens_left' => 'integer',
            'taken_at' => 'datetime',
        ];
    }
}
