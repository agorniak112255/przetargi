<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Services\Ai\AiTask;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\OpenAiCompatibleClient;
use RuntimeException;

/**
 * Pozycje towarów z TREŚCI ogłoszenia o zamówieniu (opisy części, sekcja „Przedmiot zamówienia”) — dla postępowań,
 * w których zamawiający wymienia towary i ilości w samym ogłoszeniu („- hełm strażacki – 23 szt.”, „dostawa …
 * ubrań specjalnych … w ilości 190 kpl.”). Model wypisuje towary z cytatem; aplikacja sprawdza cytat w tekście:
 *  - quote_found — cytat jest w tekście ogłoszenia (bez cytatu w tekście pozycja jest tylko propozycją modelu),
 *  - quantity — tylko gdy liczba stoi w znalezionym cytacie; inaczej null (ilość do uzupełnienia, nie zgadujemy),
 *  - bhp — ocena modelu, czy to odzież robocza / środek ochrony indywidualnej (wnioskowanie, nie fakt z ogłoszenia).
 * Nic nie trafia do przetargu — wynik idzie do podglądu kreatora („Dodaj do przetargu” robi człowiek).
 */
final class NoticeItemsReader
{
    private const MAX_TEXT = 60000;

    private const MAX_ITEMS = 300;

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly JsonResponseParser $jsonParser,
        private readonly NoticeSections $sections,
    ) {}

    /**
     * Tekst do odczytu: sekcja „Przedmiot zamówienia” z pełnej treści ogłoszenia; gdy treść już skasowana (system:prune)
     * albo bez tej sekcji — opisy części z odczytu ogłoszenia (opis części ucięty do 2000 znaków przy pobieraniu).
     */
    public function sourceText(ProcurementNotice $notice): string
    {
        foreach ($this->sections->extract($notice->html_body) as $section) {
            if ($section['key'] === 'subject' && trim($section['text']) !== '') {
                return $section['text'];
            }
        }

        $parts = [];
        foreach ((array) ($notice->parsed['lots'] ?? []) as $lot) {
            if (! is_array($lot)) {
                continue;
            }
            $name = trim((string) ($lot['name'] ?? ''));
            $description = trim((string) ($lot['description'] ?? ''));
            $header = isset($lot['lot_no']) && is_numeric($lot['lot_no']) ? 'Część '.(int) $lot['lot_no'] : '';
            // opis części zwykle zaczyna się od jej nazwy — bez powtórzenia
            $body = $description !== '' && $name !== '' && ! str_starts_with($description, $name)
                ? $name."\n".$description
                : ($description !== '' ? $description : $name);
            $parts[] = trim($header."\n".$body);
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * @return array{text: string, items: list<array{lot_no: ?int, name: string, quantity: ?int, unit: ?string, quote: string, quote_found: bool, bhp: bool}>}
     */
    public function read(ProcurementNotice $notice): array
    {
        $text = $this->sourceText($notice);
        if ($text === '') {
            throw new RuntimeException('Ogłoszenie nie ma opisu przedmiotu zamówienia — pozycje są w dokumentach postępowania.');
        }
        $excerpt = mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT) : $text;

        $schema = ['items' => [[
            'lot_no' => 'numer części albo null',
            'name' => 'nazwa towaru',
            'quantity' => 'liczba albo null',
            'unit' => 'jednostka albo null',
            'quote' => 'fragment ogłoszenia',
            'bhp' => true,
        ]]];
        $messages = [
            [
                'role' => 'system',
                'content' => 'Jesteś asystentem przetargowym firmy sprzedającej odzież roboczą, obuwie robocze i środki ochrony indywidualnej (BHP). Odpowiadasz wyłącznie poprawnym JSON.',
            ],
            [
                'role' => 'user',
                'content' => "Poniżej opis przedmiotu zamówienia z ogłoszenia o zamówieniu publicznym. Wypisz każdy towar, który zamawiający chce kupić.\n"
                    ."Zasady:\n"
                    ."- jeden towar = jedna pozycja; komplet opisany jako całość (np. „ubranie specjalne składające się z kurtki i spodni”) to jedna pozycja;\n"
                    ."- name: nazwa towaru z ogłoszenia, w mianowniku, z cechami podanymi przy nim (np. norma, rodzaj);\n"
                    ."- quantity: liczba sztuk/kompletów/par z ogłoszenia; null, gdy ogłoszenie jej nie podaje;\n"
                    ."- unit: jednostka z ogłoszenia (szt., kpl., par, op.); null, gdy brak;\n"
                    ."- lot_no: numer części zamówienia, w której jest towar; null, gdy zamówienie nie ma części;\n"
                    ."- quote: fragment ogłoszenia z nazwą i ilością tego towaru, przepisany znak w znak (bez zmian, bez skrótów);\n"
                    ."- bhp: true dla odzieży roboczej, ochronnej, specjalnej i służbowej, obuwia roboczego i ochronnego, rękawic, ochrony głowy, oczu, twarzy, słuchu i dróg oddechowych, sprzętu chroniącego przed upadkiem z wysokości i innych środków ochrony indywidualnej; false dla pojazdów, maszyn, narzędzi, elektroniki, łączności, mebli, leków i wszystkiego innego.\n"
                    ."Nie wypisuj usług, warunków, terminów, kryteriów ani informacji o trybie postępowania. Czego nie ma w tekście, tego nie wpisuj. Gdy ogłoszenie nie wymienia żadnego towaru, zwróć pustą listę.\n"
                    .'Schemat: '.json_encode($schema, JSON_UNESCAPED_UNICODE)."\n\n---\n".$excerpt,
            ],
        ];

        $raw = $this->llm->chat($messages, null, true, null, AiTask::TenderDocument);
        $parsed = $this->jsonParser->parse((string) ($raw['content'] ?? ''));
        $rows = $parsed['items'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('Nie udało się odczytać pozycji z treści ogłoszenia. Spróbuj ponownie.');
        }

        return ['text' => $text, 'items' => $this->verify($rows, $text, $this->lotNumbers($notice))];
    }

    /**
     * @param  array<mixed>  $rows
     * @param  list<int>  $lotNumbers
     * @return list<array{lot_no: ?int, name: string, quantity: ?int, unit: ?string, quote: string, quote_found: bool, bhp: bool}>
     */
    public function verify(array $rows, string $text, array $lotNumbers): array
    {
        $haystack = self::normalize($text);
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $quote = trim((string) ($row['quote'] ?? ''));
            // przepisany opis pola ze schematu to nie dane z ogłoszenia
            if ($name === '' || $name === 'nazwa towaru' || $quote === 'fragment ogłoszenia') {
                continue;
            }
            $quoteFound = $quote !== '' && str_contains($haystack, self::normalize($quote));

            $quantity = null;
            $modelQuantity = $row['quantity'] ?? null;
            if ($quoteFound && is_numeric($modelQuantity) && (int) $modelQuantity >= 1 && (float) $modelQuantity === (float) (int) $modelQuantity) {
                // liczba musi stać w cytacie jako osobna liczba („1 000” = 1000)
                $digits = (string) (int) $modelQuantity;
                $quoteNumbers = preg_match_all('/\d(?:[\d\x{00A0} ]*\d)?/u', $quote, $m) ? array_map(
                    static fn (string $n): string => (string) preg_replace('/\D/u', '', $n),
                    $m[0],
                ) : [];
                if (in_array($digits, $quoteNumbers, true)) {
                    $quantity = (int) $modelQuantity;
                }
            }

            $unit = trim((string) ($row['unit'] ?? ''));
            $unit = $unit === '' || $unit === 'jednostka albo null' ? null : mb_substr($unit, 0, 16);
            $lotNo = is_numeric($row['lot_no'] ?? null) ? (int) $row['lot_no'] : null;
            if ($lotNo !== null && ! in_array($lotNo, $lotNumbers, true)) {
                $lotNo = null;
            }

            $key = mb_strtolower($name).'|'.($lotNo ?? '').'|'.($quantity ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'lot_no' => $lotNo,
                'name' => mb_substr($name, 0, 1000),
                'quantity' => $quantity,
                'unit' => $unit,
                'quote' => mb_substr($quote, 0, 2000),
                'quote_found' => $quoteFound,
                'bhp' => ($row['bhp'] ?? false) === true,
            ];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    /** @return list<int> */
    private function lotNumbers(ProcurementNotice $notice): array
    {
        $out = [];
        foreach ((array) ($notice->parsed['lots'] ?? []) as $lot) {
            if (is_array($lot) && is_numeric($lot['lot_no'] ?? null)) {
                $out[] = (int) $lot['lot_no'];
            }
        }

        return $out;
    }

    /** Porównanie cytatu z tekstem: bez różnic w białych znakach, myślnikach, cudzysłowach i wielkości liter. */
    private static function normalize(string $text): string
    {
        $text = str_replace(['–', '—', '‒', '−', '„', '”', '“', '«', '»', '’', '‘'], ['-', '-', '-', '-', '"', '"', '"', '"', '"', "'", "'"], $text);
        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }
}
