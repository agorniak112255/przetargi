<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestaCategory extends Model
{
    protected $fillable = [
        'presta_id',
        'parent_presta_id',
        'name',
        'path',
        'level_depth',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'presta_id' => 'integer',
            'parent_presta_id' => 'integer',
            'level_depth' => 'integer',
            'active' => 'boolean',
        ];
    }
}
