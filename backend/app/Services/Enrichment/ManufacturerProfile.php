<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Support\ProductCodeMatch;

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
     * @param  list<string>  $imageUrlBlocklist  wyrażenia regularne adresów grafik witryny producenta, które nie są zdjęciem wyrobu
     * @param  list<string>  $altForms  reguły innych zapisów kodu (letter_suffix, dash_suffix, trailing_words, leading_zeros)
     * @param  list<string>  $sizeLetters  oznaczenia rozmiaru w kodzie wyrobu („S”, „M”, „L”) — sizeSibling
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
        /** wzorzec końcówki kodu kombinacji w mikrodanych strony producenta („103-00033-48/XS” = model 103) */
        public readonly ?string $combinationSuffix = null,
        /** krótki kod (także krótszy niż min_length, od 3 znaków) liczy się z etykietą „model 103” / „REF: 6036” */
        public readonly bool $labelledShortCodes = false,
        /** grupowanie kart w model (opis wspólny): null = karta jest modelem; 'name_stem' = ProductModelKey */
        public readonly ?string $modelGroup = null,
        /** od ilu kart modelu w partii lider dostaje notę modelu w poleceniu */
        public readonly int $modelMinMembers = 2,
        /** grafiki reklamowe witryny producenta (coba: StandUpforHealth, Modal_Elephant) — App\Support\ImageUrlBlocklist */
        public readonly array $imageUrlBlocklist = [],
        /** inne zapisy kodu do drugiej próby na hostach producenta (ManufacturerCodeForms::RULES) */
        public readonly array $altForms = [],
        /** najdłuższy kod z katalogu marki decyduje (CardCodeArbiter); false = arbiter i nowe reguły kodu wyłączone */
        public readonly bool $longestCodeWins = false,
        /** etykieta pola kodu w treści strony producenta („Indeks” na securabc.com); null = bez tej reguły */
        public readonly ?string $indexLabel = null,
        /** oznaczenia rozmiaru w kodzie (SECURA „S56T0SM0” = półmaska 3000 w rozmiarze M); [] = bez tej reguły */
        public readonly array $sizeLetters = [],
    ) {}

    /**
     * Dwa kody to ten sam model w innym rozmiarze: ta sama długość, a różnią się tylko oznaczeniem rozmiaru z profilu
     * w tym samym miejscu, nie na początku kodu („S56T0SL0” ↔ „S56T0SM0”; „S56T1SM0” — inna cyfra, inny model).
     * Porównanie po ProductCodeMatch::key (bez separatorów, małe litery).
     */
    public function sizeSibling(string $a, string $b): bool
    {
        $a = ProductCodeMatch::key($a);
        $b = ProductCodeMatch::key($b);
        if ($this->sizeLetters === [] || $a === $b || strlen($a) !== strlen($b)) {
            return false;
        }
        $letters = array_values(array_unique(array_map(static fn (string $l): string => ProductCodeMatch::key($l), $this->sizeLetters)));
        foreach ($letters as $ours) {
            $len = strlen($ours);
            for ($pos = strpos($a, $ours, 1); $len > 0 && $pos !== false; $pos = strpos($a, $ours, $pos + 1)) {
                foreach ($letters as $theirs) {
                    if ($theirs !== $ours && strlen($theirs) === $len && substr_replace($a, $theirs, $pos, $len) === $b) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Kod z mikrodanych strony producenta bez końcówki kombinacji (kolor, rozmiar) według profilu; bez wzorca — bez
     * zmian.
     */
    public function withoutCombinationSuffix(string $code): string
    {
        if ($this->combinationSuffix === null) {
            return $code;
        }
        $stripped = trim((string) preg_replace($this->combinationSuffix, '', $code));

        return $stripped !== '' ? $stripped : $code;
    }

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
