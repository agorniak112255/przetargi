<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Profil producenta karty (ManufacturerProfiles::for): hosty, katalogi i „tylko producent” z config/enrichment.php,
 * reguły tożsamości z config/manufacturer_profiles.php.
 */
final class ManufacturerProfile
{
    /**
     * @param  list<string>  $hosts  hosty producenta bez schematu, małymi literami
     * @param  list<string>  $catalogs  adresy katalogów PDF marki
     * @param  list<string>  $identityIn  url | title | markup | text
     * @param  list<string>  $variantSuffixes  litery po kodzie, które znaczą ten sam wyrób w innej postaci sprzedaży
     */
    public function __construct(
        public readonly string $brandKey,
        public readonly array $hosts,
        public readonly bool $onlyManufacturer,
        public readonly array $catalogs,
        public readonly array $identityIn,
        public readonly string $codeNormalize,
        public readonly int $minLength,
        public readonly ?string $modelRegex,
        public readonly bool $modelAliasIsKey,
        public readonly ?string $resolver,
        /** klucz wpisu w manufacturer_profiles.profiles; null = sam profil domyślny */
        public readonly ?string $profileKey = null,
        /** końcówka „-5” w kodzie cennika = „05” doklejone na stronie (0 = bez tej reguły) */
        public readonly int $dashSuffixPad = 0,
        public readonly array $variantSuffixes = [],
    ) {}

    /**
     * Adres na hoście producenta z profilu (host równy albo poddomena — jak ManufacturerDomainResolver::hostMatchesAny).
     * Profil bez hostów: zawsze false.
     */
    public function ownsUrl(string $url): bool
    {
        $host = mb_strtolower(trim((string) (parse_url(trim($url), PHP_URL_HOST) ?? '')), 'UTF-8');
        if ($host === '') {
            return false;
        }
        $host = (string) preg_replace('/^www\./', '', rtrim($host, '.'));
        foreach ($this->hosts as $domain) {
            $d = (string) preg_replace('/^www\./', '', rtrim(mb_strtolower(trim($domain), 'UTF-8'), '.'));
            if ($d !== '' && ($host === $d || str_ends_with($host, '.'.$d))) {
                return true;
            }
        }

        return false;
    }
}
