<?php

declare(strict_types=1);

namespace App\Services\Norms;

use App\Services\Enrichment\ProductPageFetcher;

/**
 * Czytnik ogólny: ramka norm strony (lista z klasą „norms”/„normy” — MAPA, pros.pl) przez ten sam odczyt co opis
 * (ProductPageFetcher::normFacts). Strona bez takiej ramki (Portwest trzyma normy w zakładce bez klasy) — null,
 * wtedy decyduje czytnik witryny.
 */
final class GenericNormFrameReader implements ManufacturerNormPageReader
{
    private const BLOCK_LIMIT = 1000;

    public function __construct(private readonly ProductPageFetcher $pages) {}

    public function supports(string $host): bool
    {
        return true;
    }

    public function read(string $html, string $url): ?NormPageReading
    {
        $rows = [];
        $lines = [];
        foreach ($this->pages->normFactsFromHtml($html) as $fact) {
            $value = isset($fact['value']) ? (string) $fact['value'] : null;
            $rows[] = ['label' => (string) $fact['label'], 'value' => $value];
            // Etykieta i wartość w jednej linii, spacją — tak normFacts skleja wiersze wartości. „EN 388\n1121X”
            // z nową linią nie dałoby się przeczytać jako kodu EN 388 (En388Code czyta kod w tej samej linii),
            // więc sprawdzenie strony rodziny nie widziałoby kodów.
            $lines[] = $value !== null ? $fact['label'].' '.$value : (string) $fact['label'];
        }
        if ($rows === []) {
            return null;
        }

        return new NormPageReading($rows, mb_substr(implode("\n", $lines), 0, self::BLOCK_LIMIT), 'ramka-norm');
    }
}
