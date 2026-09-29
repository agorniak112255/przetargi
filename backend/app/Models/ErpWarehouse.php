<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Magazyn XL w podziale handlowe / usługowe (raport zapasów). Kod dosłownie z CDN.Magazyny.MAG_Kod.
 */
class ErpWarehouse extends Model
{
    protected $fillable = ['code', 'name', 'is_service'];

    protected function casts(): array
    {
        return ['is_service' => 'boolean'];
    }

    /** @return list<string> kody magazynów usługowych */
    public static function serviceCodes(): array
    {
        return self::query()->where('is_service', true)->pluck('code')->map(fn ($c): string => (string) $c)->values()->all();
    }
}
