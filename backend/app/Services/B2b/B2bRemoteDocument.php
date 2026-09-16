<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\ProductDocument;

/**
 * Plik do pobrania ze strony produktu u dostawcy (karta techniczna, instrukcja). Sama pozycja listy — bez
 * zawartości: łącznik pobiera bajty dopiero dla plików, których karta jeszcze nie ma (B2bDocumentSource).
 */
final readonly class B2bRemoteDocument
{
    /**
     * @param  string  $title  nazwa pliku dosłownie ze strony dostawcy
     * @param  string  $sourceUrl  adres pliku zapisywany jako źródło — bez tokenów sesji
     * @param  string  $kind  ProductDocument::KIND_*
     */
    public function __construct(
        public string $title,
        public string $sourceUrl,
        public string $kind = ProductDocument::KIND_OTHER,
    ) {}
}
