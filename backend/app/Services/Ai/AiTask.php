<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Zadania, którym można przypisać osobny profil modelu w Ustawieniach AI.
 * Klucze trafiają do JSON-a w bazie, więc nie zmieniamy ich po wdrożeniu.
 */
enum AiTask: string
{
    case ProductSearch = 'product_search';

    case TenderMatch = 'tender_match';

    case Enrichment = 'enrichment';

    case WebSearch = 'web_search';

    case PriceListPdf = 'price_list_pdf';

    case ImageVerification = 'image_verification';

    case TenderDocument = 'tender_document';

    case SpreadsheetExtract = 'spreadsheet_extract';

    case ClientInquiry = 'client_inquiry';

    public function label(): string
    {
        return match ($this) {
            self::ProductSearch => 'Wyszukiwarka AI produktów',
            self::TenderMatch => 'Dopasowanie pozycji SIWZ',
            self::Enrichment => 'Opisy produktów',
            self::WebSearch => 'Szukanie w internecie',
            self::PriceListPdf => 'Analiza cennika',
            self::ImageVerification => 'Weryfikacja zdjęć',
            self::TenderDocument => 'Analiza dokumentów przetargu',
            self::SpreadsheetExtract => 'Ekstrakcja pozycji z arkusza',
            self::ClientInquiry => 'Odpowiedzi na zapytania mailowe',
        };
    }

    /** Podpowiedź w UI — czego dane zadanie wymaga od modelu. */
    public function hint(): string
    {
        return match ($this) {
            self::ProductSearch => 'Dwa wywołania na zapytanie, długi prompt z kartami katalogu. Zyskuje na szybkim modelu.',
            self::TenderMatch => 'Największy wolumen — pozycje przetargu lecą równolegle.',
            self::Enrichment => 'Bez własnego profilu działa pole „Model do opisów”.',
            self::WebSearch => 'Wymaga dostawcy z pluginem web (OpenRouter).',
            self::PriceListPdf => 'Wymaga modelu multimodalnego (odczyt PDF).',
            self::ImageVerification => 'Wymaga modelu z obsługą obrazu.',
            self::TenderDocument => 'Długie dokumenty — liczy się duży kontekst.',
            self::SpreadsheetExtract => 'Długie arkusze — liczy się duży kontekst.',
            self::ClientInquiry => 'Krótki list handlowy + karty niuansów. Zyskuje na sprawnym modelu z JSON.',
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $task): string => $task->value, self::cases());
    }

    /**
     * Opis zadań dla frontu — UI nie duplikuje wtedy listy ani etykiet.
     *
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function catalog(): array
    {
        return array_map(static fn (self $task): array => [
            'key' => $task->value,
            'label' => $task->label(),
            'hint' => $task->hint(),
        ], self::cases());
    }
}
