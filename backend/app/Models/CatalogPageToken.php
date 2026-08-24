<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogPageToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'catalog_page_id',
        'token',
    ];
}
