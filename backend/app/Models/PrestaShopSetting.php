<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrestaShopSetting extends Model
{
    protected $fillable = [
        'enabled',
        'host',
        'port',
        'database_name',
        'username',
        'password',
        'table_prefix',
        'id_lang',
        'shop_url',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'id_lang' => 'integer',
            'password' => 'encrypted',
        ];
    }
}
