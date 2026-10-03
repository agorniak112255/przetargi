<?php

declare(strict_types=1);

namespace App\Services\Tenders;

use App\Models\Competitor;
use App\Support\CompanyName;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Jedna firma konkurencji = jeden wiersz `competitors`.
 *
 * Kolejność: NIP (10 cyfr z poprawną sumą kontrolną), potem klucz nazwy (CompanyName::key). Firma znaleziona po
 * nazwie dostaje NIP, gdy się pojawi — chyba że ma już inny NIP (wtedy to inna firma o tej samej nazwie).
 * Niepoprawny NIP nie jest zapisywany w firmie — surową wartość przechowuje wywołujący
 * (tender_lots.winner_national_id_raw). Nazwa firmy zostaje taka, jak przyszła ze źródła.
 */
final class CompetitorRegistry
{
    public function resolve(?string $name, ?string $nationalId): ?Competitor
    {
        $name = $name !== null ? trim((string) preg_replace('/\s+/u', ' ', $name)) : '';
        $key = CompanyName::key($name);
        $nip = CompanyName::nip($nationalId);

        if ($nip === null && $key === '') {
            return null;
        }

        try {
            return $this->find($name, $key, $nip);
        } catch (UniqueConstraintViolationException) {
            // równoległy zapis tej samej firmy (unikalny NIP) — druga próba znajdzie już zapisany wiersz
            return $this->find($name, $key, $nip);
        }
    }

    private function find(string $name, string $key, ?string $nip): Competitor
    {
        if ($nip !== null) {
            $byNip = Competitor::query()->where('nip', $nip)->first();
            if ($byNip !== null) {
                // firma założona dotąd bez nazwy (sam NIP) dostaje nazwę ze źródła
                if ($byNip->name_key === '' && $key !== '') {
                    $byNip->forceFill(['name' => mb_substr($name, 0, 500), 'name_key' => $key])->save();
                }

                return $byNip;
            }
        }

        if ($key !== '') {
            $byName = Competitor::query()
                ->where('name_key', $key)
                ->when($nip !== null, static fn ($query) => $query->whereNull('nip'))
                ->orderBy('id')
                ->first();
            if ($byName !== null) {
                if ($nip !== null) {
                    $byName->forceFill(['nip' => $nip])->save();
                }

                return $byName;
            }
        }

        return Competitor::query()->create([
            // bez nazwy w źródle firma jest opisana numerem, który jest w źródle — nie zmyślamy nazwy
            'name' => $name !== '' ? mb_substr($name, 0, 500) : 'NIP '.$nip,
            'name_key' => $key,
            'nip' => $nip,
        ]);
    }
}
