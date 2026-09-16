<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Services\PriceListPdfTextExtractor;
use Throwable;

/**
 * Tekst z pliku pobranego z panelu dostawcy (karta techniczna PDF). Odczytany raz — zapisujemy go przy dokumencie
 * karty (product_documents.text), więc kolejne pobranie cennika nie ściąga plików ponownie.
 *
 * Czego w pliku nie ma, tego nie dopisujemy: skan bez warstwy tekstowej i plik innego typu dają pusty tekst.
 */
final class B2bDocumentText
{
    /** Karty techniczne UVEX mają ~2 tys. znaków; dłuższy tekst i tak nie zmieściłby się w opisie karty. */
    public const LIMIT = 8000;

    public function __construct(
        private readonly PriceListPdfTextExtractor $extractor = new PriceListPdfTextExtractor,
    ) {}

    /** Tekst z pliku; '' gdy nie da się go odczytać. */
    public function fromFile(string $bytes, string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if ($mime !== 'application/pdf' || ! str_starts_with($bytes, '%PDF')) {
            return '';
        }

        $path = tempnam(sys_get_temp_dir(), 'b2b-doc-');
        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $bytes);

            return trim($this->extractor->extract($path, self::LIMIT));
        } catch (Throwable) {
            // skan, uszkodzony plik, brak narzędzia — karta zostaje bez sekcji z karty technicznej
            return '';
        } finally {
            @unlink($path);
        }
    }
}
