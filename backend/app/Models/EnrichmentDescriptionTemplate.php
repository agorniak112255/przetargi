<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrichmentDescriptionTemplate extends Model
{
    protected $fillable = [
        'kategoria_bhp',
        'instructions',
        'layout',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }
}
