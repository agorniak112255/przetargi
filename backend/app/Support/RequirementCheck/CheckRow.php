<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Wiersz porównania: jeden parametr z wymagania, wszystkie znaleziska na karcie (każde z polem
 * źródłowym, cytatem i werdyktem) i status wiersza. Wiersz powstaje tylko dla parametru
 * odczytanego z wymagania — nie dopisujemy parametrów, o które przetarg nie pyta.
 *
 * @phpstan-type Required array{text: string, quote: string}&array<string, mixed>
 * @phpstan-type Finding array{text: string, source: string, quote: string, find: ?string, verdict: string}&array<string, mixed>
 * @phpstan-type Position array{name: string, required: string, card: ?string, status: string}
 */
final readonly class CheckRow
{
    private const QUOTE_MAX = 120;

    /**
     * @param  array<string, mixed>  $required  co najmniej text i quote (cytat z wymagania)
     * @param  list<array<string, mixed>>  $card  znaleziska z self::finding()
     * @param  list<array{name: string, required: string, card: ?string, status: string}>|null  $positions  pozycje kodu (EN 388, EN 407)
     * @param  string|null  $gate  bramka dopasowania, która ocenia ten sam parametr (cut_level, ffp, footwear, snr, impact, antistatic)
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $required,
        public array $card,
        public Status $status,
        public ?string $note = null,
        public ?array $positions = null,
        public ?string $gate = null,
    ) {}

    /**
     * Znalezisko na karcie. `find` to fraza do „Szukaj w opisie” — tylko gdy występuje dosłownie
     * w tekście pola, które okno pokazuje (nazwy tam nie ma).
     *
     * @param  array<string, mixed>  $extra  np. value_mm
     * @return array<string, mixed>
     */
    public static function finding(CardSource $source, string $text, Status $verdict, array $extra = []): array
    {
        // Tekst znaleziska to zwykle dosłowny fragment pola — szybkie str_contains, a mb_stripos
        // (zamiana całego opisu na małe litery) tylko, gdy wielkość liter się różni.
        $inText = str_contains($source->text, $text) || mb_stripos($source->text, $text) !== false;
        $find = $source->searchable() && $inText ? $text : null;

        return [
            'text' => $text,
            'source' => $source->source,
            'quote' => self::quote($source->text, $text),
            'find' => $find,
            'verdict' => $verdict->value,
            ...$extra,
        ];
    }

    /**
     * Cytat z wymagania albo karty przycięty wokół wartości, żeby wiersz tabeli został czytelny.
     * finding() woła to przy każdym trafieniu, a opis karty ma do 20 tys. znaków — długie pole
     * najpierw zawężamy do okna wokół wartości, dopiero potem zwijamy spacje (recenzja: ~1,5 s
     * na żądanie, gdy zwijanie szło po całym opisie).
     */
    public static function quote(string $text, string $around = ''): string
    {
        $around = trim((string) preg_replace('/\s+/u', ' ', $around));
        // Okno w bajtach (znak UTF-8 ma do 4): wyszukiwanie bajtowe i mb_strcut są liniowe bez
        // przeliczania całego opisu na znaki; mb_strcut nie przecina polskich liter.
        $window = self::QUOTE_MAX * 3 * 4;
        $cutBefore = false;
        $cutAfter = false;
        if (strlen($text) > $window) {
            $at = false;
            if ($around !== '') {
                // wartość z nową linią w środku nie znajdzie się dosłownie — kotwica na pierwszym słowie
                $at = strpos($text, $around);
                $at = $at !== false ? $at : stripos($text, $around);
                $at = $at !== false ? $at : strpos($text, (string) strtok($around, ' '));
            }
            $from = $at === false ? 0 : max(0, $at - self::QUOTE_MAX * 4);
            $cutBefore = $from > 0;
            $cutAfter = $from + $window < strlen($text);
            $text = mb_strcut($text, $from, $window);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= self::QUOTE_MAX) {
            return ($cutBefore ? '…' : '').$text.($cutAfter ? '…' : '');
        }
        $at = $around !== '' ? mb_stripos($text, $around) : false;
        $start = $at === false ? 0 : max(0, $at - max(0, intdiv(self::QUOTE_MAX - mb_strlen($around), 2)));
        $slice = mb_substr($text, $start, self::QUOTE_MAX);

        return ($cutBefore || $start > 0 ? '…' : '').trim($slice)
            .($cutAfter || $start + self::QUOTE_MAX < mb_strlen($text) ? '…' : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'card' => $this->card,
            'status' => $this->status->value,
            'note' => $this->note,
            'positions' => $this->positions,
            'gate' => $this->gate,
        ];
    }
}
