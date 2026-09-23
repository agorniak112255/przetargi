<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Czy cennik konta B2B ustala cenę i opis wyrobów danego producenta. Tylko wyłączenia — brak wiersza
 * znaczy, że oba znaczniki są włączone (App\Services\B2b\B2bManufacturerRules).
 */
class B2bAccountManufacturerRule extends Model
{
    protected $fillable = [
        'b2b_account_id',
        'manufacturer',
        'manufacturer_key',
        'take_price',
        'take_description',
    ];

    protected function casts(): array
    {
        return [
            'take_price' => 'boolean',
            'take_description' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }
}
