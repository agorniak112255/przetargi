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
        'webservice_key',
        'table_prefix',
        'id_lang',
        'shop_url',
        'id_category_default',
        'delivery_label',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'id_lang' => 'integer',
            'id_category_default' => 'integer',
            'password' => 'encrypted',
            'webservice_key' => 'encrypted',
        ];
    }
}
