<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Firma konkurencji z wyników przetargów. Jedna firma = jeden wiersz: po NIP (poprawna suma kontrolna),
 * a bez NIP po kluczu nazwy (App\Support\CompanyName::key) — zakłada i łączy App\Services\Tenders\CompetitorRegistry.
 */
class Competitor extends Model
{
    protected $fillable = [
        'name',
        'name_key',
        'nip',
    ];

    protected $hidden = [
        'name_key',
    ];

    public function wonLots(): HasMany
    {
        return $this->hasMany(TenderLot::class, 'winner_competitor_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(TenderLotOffer::class);
    }
}
