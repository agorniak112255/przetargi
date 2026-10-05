<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ManufacturerSite;
use Illuminate\Validation\ValidationException;

/**
 * Lista stron, z których bierzemy opisy (konto B2B „Strony z opisami”, cennik z pliku „Źródła opisów”): adres strony
 * albo domena → host jak strony producentów (bez www., bez ścieżki), bez powtórzeń, w kolejności wpisania
 * (przy cenniku kolejność to ważność).
 */
final class EnrichmentSiteList
{
    public const MAX = 20;

    /**
     * @param  array<int|string, mixed>  $sites
     * @return list<string>|null pusta lista = null
     *
     * @throws ValidationException
     */
    public static function normalize(array $sites, string $field, string $label): ?array
    {
        $hosts = [];
        foreach ($sites as $site) {
            $raw = trim((string) $site);
            if ($raw === '') {
                continue;
            }
            $host = ManufacturerSite::normalizeHost($raw);
            // ta sama reguła co CatalogSearchHostService::looksLikeHost (Administracja → Strony wyszukiwarka)
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host) !== 1) {
                throw ValidationException::withMessages([
                    $field => "{$label}: „{$raw}” to nie jest poprawny adres strony ani domena (np. sklepbhp.pl).",
                ]);
            }
            $hosts[$host] = true;
        }

        return $hosts === [] ? null : array_keys($hosts);
    }

    /**
     * Zapisana lista → hosty (odczyt, bez walidacji): kolejność zachowana, puste i powtórzenia pominięte.
     *
     * @return list<string>
     */
    public static function hosts(mixed $stored): array
    {
        $hosts = [];
        foreach ((array) ($stored ?? []) as $site) {
            $host = is_string($site) ? ManufacturerSite::normalizeHost($site) : '';
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }
}
