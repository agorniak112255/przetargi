<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Producenci z katalogu + aliasy z config — kontekst dla analizy SIWZ przez model.
 */
final class CatalogManufacturerContext
{
    private const CACHE_KEY = 'catalog.manufacturers.distinct.v1';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return list<string>
     */
    public function catalogManufacturers(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Product::query()
                ->whereNotNull('manufacturer')
                ->where('manufacturer', '!=', '')
                ->distinct()
                ->orderBy('manufacturer')
                ->pluck('manufacturer')
                ->map(static fn (mixed $name): string => trim((string) $name))
                ->filter(static fn (string $name): bool => $name !== '')
                ->values()
                ->all();
        });
    }

    public function promptBlock(): string
    {
        $lines = $this->catalogManufacturers();
        if ($lines === []) {
            return 'Producenci w katalogu: (pusto).';
        }

        return 'Producenci obecni w katalogu (pole manufacturer — użyj dokładnie jednej nazwy lub null): '
            .implode('; ', $lines).'.';
    }

    public function matchManufacturer(?string $guess): ?string
    {
        $guess = trim((string) $guess);
        if ($guess === '') {
            return null;
        }
        $needle = $this->compact($guess);
        if ($needle === '') {
            return null;
        }
        foreach ($this->catalogManufacturers() as $canonical) {
            if ($this->compact($canonical) === $needle) {
                return $canonical;
            }
        }
        // Marka ze słownika („Peltor” → 3M) przed luźnym etapem niżej. Katalog nazywa producenta, nie
        // podmarkę, więc „peltor” nie trafiało w żadną nazwę i enrichIntentManufacturers uznawało markę
        // za nieobecną w katalogu — a to zeruje producenta i model w intencji zapytania.
        $viaDictionary = $this->producerFromDictionary($needle);
        if ($viaDictionary !== null) {
            return $viaDictionary;
        }
        // Luźny etap: dłuższy zapis marki („Mapa Professional” → MAPA, „3M Polska” → 3M) albo pierwsze słowa dłuższej
        // nazwy producenta („DELTA” → Delta Plus). Tylko całe słowa od początku — do 25.09.2026 wystarczał podciąg
        // i słowo z SIWZ wersalikami stawało się marką: „PROSTY” → PROS, „OKULARY” → Okula, „SZELKI” → „TOP SAFETY
        // Szelki bezpieczeństwa”. Marka z intencji zawęża pulę, więc wyroby innych producentów znikały przed oceną.
        foreach ($this->catalogManufacturers() as $canonical) {
            if ($this->startsWithWords($guess, $canonical) || $this->startsWithWords($canonical, $guess)) {
                return $canonical;
            }
        }
        // Klucz z konfiguracji przychodzi z zapytania zbity, bez separatorów („msasafety” z „MSA-Safety”) — granice
        // słów bierzemy z zapisu klucza w konfiguracji („msa-safety”).
        foreach ($this->configAliasSpellings() as $alias) {
            if ($this->compact($alias) !== $needle) {
                continue;
            }
            foreach ($this->catalogManufacturers() as $canonical) {
                if ($this->startsWithWords($alias, $canonical) || $this->startsWithWords($canonical, $alias)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /**
     * Czy $text zaczyna się od $head jako od całych słów: „Mapa Professional” od „MAPA”, „Delta-Plus Group” od
     * „Delta Plus”, ale „PROSTY” nie od „PROS”, a „UVEX9160” nie od „UVEX”. Litery z akcentem jako zwykłe („Bollé” to
     * „Bolle”, „Sundstrom” to „Sundström”) — compact() je wycina, a „boll” trafiało w „bolle” tylko jako podciąg.
     */
    private function startsWithWords(string $text, string $head): bool
    {
        $want = $this->fold($head);
        if ($want === '') {
            return false;
        }
        $joined = '';
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $joined .= $this->fold($word);
            if ($joined === $want) {
                return true;
            }
            if (! str_starts_with($want, $joined)) {
                return false;
            }
        }

        return false;
    }

    /** Jak compact(), ale litery z akcentem zamienione na zwykłe, nie wycięte. Tylko do porównań, nie do kluczy słownika. */
    private function fold(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($text)));
    }

    public function hasProductsForManufacturer(string $canonical): bool
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return false;
        }
        $compact = $this->compact($canonical);

        return Product::query()
            ->where(function ($q) use ($canonical, $compact): void {
                $q->where('manufacturer', $canonical)
                    ->orWhere('manufacturer', 'like', '%'.addcslashes($canonical, '%_\\').'%');
                if ($compact !== '' && $compact !== $this->compact($canonical)) {
                    $like = '%'.addcslashes($compact, '%_\\').'%';
                    $q->orWhereRaw('LOWER(manufacturer) LIKE ?', [mb_strtolower($like)]);
                }
            })
            ->exists();
    }

    /**
     * Klucze z konfiguracji w oryginalnym zapisie, z separatorami słów („msa-safety”, „coba-europe”).
     *
     * @return list<string>
     */
    private function configAliasSpellings(): array
    {
        return array_map('strval', array_keys((array) config('enrichment.manufacturer_domains', [])));
    }

    /** Kanoniczny producent z katalogu dla marki ze słownika; null, gdy to nie marka albo producenta nie ma w katalogu. */
    private function producerFromDictionary(string $key): ?string
    {
        try {
            $producer = app(BrandDictionary::class)->producerFor($key);
        } catch (Throwable) {
            return null;
        }
        if ($producer === null) {
            return null;
        }
        $wanted = $this->compact($producer);
        foreach ($this->catalogManufacturers() as $canonical) {
            if ($this->compact($canonical) === $wanted) {
                return $canonical;
            }
        }

        return null;
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function compact(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', ' ' => '', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return (string) preg_replace('/[^a-z0-9]/u', '', strtr($t, $map));
    }
}
