<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\RequirementCheck\CardSource;
use App\Support\RequirementCheck\CardSources;
use App\Support\RequirementCheck\CheckRow;
use App\Support\RequirementCheck\Status;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * „Sprawdź modelem” w oknie „Weryfikacja karty”: model wskazuje miejsca, w których karta mówi
 * dwie niezgodne rzeczy o tym samym parametrze. To tylko wniosek do sprawdzenia przez człowieka —
 * sprzeczność przechodzi dalej wyłącznie wtedy, gdy każdy jej cytat da się znaleźć dosłownie
 * we wskazanym polu karty. Wymagań przetargu do modelu nie wysyłamy.
 */
final class CardConflictAiService
{
    public const PROMPT_VERSION = 'conflicts-2026-09-15';

    /** Łączny limit znaków pól karty w zapytaniu; przycinamy opis na końcu. */
    private const INPUT_BUDGET = 24000;

    private const MAX_CONFLICTS = 10;

    private const MAX_QUOTES = 6;

    private const QUOTE_MIN = 2;

    private const QUOTE_MAX = 200;

    private const PARAMETER_MAX = 80;

    private const EXPLANATION_MAX = 300;

    private const CACHE_DAYS = 30;

    private const FIELDS = [
        CardSource::NAME,
        CardSource::NORMS,
        CardSource::SPECS,
        CardSource::FEATURES,
        CardSource::PAYLOAD_NORMS,
        CardSource::MATERIALS,
        CardSource::DESCRIPTION,
    ];

    /** Pola, które na karcie są listą pozycji — w zapytaniu zostają listą. */
    private const LIST_FIELDS = [
        CardSource::SPECS,
        CardSource::FEATURES,
        CardSource::PAYLOAD_NORMS,
        CardSource::MATERIALS,
    ];

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
    ) {}

    /**
     * @return array{
     *     conflicts: list<array{parameter: string, explanation: string, quotes: list<array<string, mixed>>}>,
     *     rejected: int,
     *     checked_at: string,
     *     cached: bool,
     *     prompt_version: string
     * }
     */
    public function find(Product $product, bool $refresh = false): array
    {
        $key = $this->cacheKey($product);
        if (! $refresh) {
            $hit = Cache::get($key);
            if (is_array($hit) && isset($hit['conflicts'], $hit['checked_at'])) {
                return [...$hit, 'cached' => true];
            }
        }

        $sources = CardSources::fromProduct($product);
        $payload = [
            'conflicts' => [],
            'rejected' => 0,
            'checked_at' => now()->toIso8601String(),
            'prompt_version' => self::PROMPT_VERSION,
        ];

        // Karta z samą nazwą nie ma czego ze sobą porównać — nie płacimy za wywołanie modelu.
        $comparable = array_filter($sources, static fn (CardSource $s): bool => $s->source !== CardSource::NAME);
        if ($comparable !== []) {
            $raw = $this->llm->chatJson($this->messages($sources), 0.0, 1500, null, AiTask::CardConflicts);
            [$payload['conflicts'], $payload['rejected']] = $this->validate($raw, $sources);
        }

        // Do cache trafia tylko udana odpowiedź (także pusta lista) — wyjątek z klienta tu nie dochodzi.
        Cache::put($key, $payload, now()->addDays(self::CACHE_DAYS));

        return [...$payload, 'cached' => false];
    }

    public function cacheKey(Product $product): string
    {
        return 'card-conflicts-ai:'.self::PROMPT_VERSION.':'.(int) $product->id.':'
            .(int) ($product->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * @param  list<CardSource>  $sources
     * @return list<array{role: string, content: string}>
     */
    public function messages(array $sources): array
    {
        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($sources)],
        ];
    }

    /**
     * Sprzeczność przechodzi tylko, gdy: każdy cytat ma znane pole i występuje w nim dosłownie
     * (po zwinięciu białych znaków, bez rozróżniania wielkości liter), a różnych cytatów są co
     * najmniej dwa. Jeden niepotwierdzony cytat odrzuca całą sprzeczność.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<CardSource>  $sources
     * @return array{0: list<array{parameter: string, explanation: string, quotes: list<array<string, mixed>>}>, 1: int}
     */
    public function validate(array $raw, array $sources): array
    {
        if (! array_key_exists('conflicts', $raw) || ! is_array($raw['conflicts'])) {
            // Bez tego „brak klucza” zapisałby się w cache jako „karta bez sprzeczności”.
            throw new RuntimeException('Model zwrócił odpowiedź bez listy sprzeczności. Ponów próbę.');
        }

        $accepted = [];
        $rejected = 0;
        foreach ($raw['conflicts'] as $row) {
            if (count($accepted) >= self::MAX_CONFLICTS) {
                break;
            }
            $conflict = is_array($row) ? $this->validateConflict($row, $sources) : null;
            if ($conflict === null) {
                $rejected++;

                continue;
            }
            $accepted[] = $conflict;
        }

        return [$accepted, $rejected];
    }

    /**
     * @param  array<mixed>  $row
     * @param  list<CardSource>  $sources
     * @return array{parameter: string, explanation: string, quotes: list<array<string, mixed>>}|null
     */
    private function validateConflict(array $row, array $sources): ?array
    {
        $parameter = $this->clean($row['parameter'] ?? null, self::PARAMETER_MAX);
        $explanation = $this->clean($row['explanation'] ?? null, self::EXPLANATION_MAX);
        $rawQuotes = $row['quotes'] ?? null;
        if ($parameter === '' || $explanation === '' || ! is_array($rawQuotes)) {
            return null;
        }

        $quotes = [];
        $seen = [];
        foreach ($rawQuotes as $quote) {
            $confirmed = is_array($quote) ? $this->confirmQuote($quote, $sources) : null;
            if ($confirmed === null) {
                return null;
            }
            [$source, $fragment] = $confirmed;
            $norm = $this->normalize($fragment);
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $quotes[] = CheckRow::finding($source, $fragment, Status::Unclear);
        }

        if (count($quotes) < 2) {
            return null;
        }

        return [
            'parameter' => $parameter,
            'explanation' => $explanation,
            'quotes' => array_slice($quotes, 0, self::MAX_QUOTES),
        ];
    }

    /**
     * Zwraca fragment wycięty z tekstu karty (oryginalna wielkość liter i odstępy), żeby
     * „Szukaj w opisie” znalazło go w oknie.
     *
     * @param  array<mixed>  $quote
     * @param  list<CardSource>  $sources
     * @return array{0: CardSource, 1: string}|null
     */
    private function confirmQuote(array $quote, array $sources): ?array
    {
        $field = is_string($quote['field'] ?? null) ? trim($quote['field']) : '';
        $text = is_string($quote['text'] ?? null) ? $quote['text'] : '';
        if (! in_array($field, self::FIELDS, true)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $length = mb_strlen($text);
        if ($length < self::QUOTE_MIN || $length > self::QUOTE_MAX) {
            return null;
        }

        $words = array_map(static fn (string $w): string => preg_quote($w, '/'), explode(' ', $text));
        $pattern = '/'.implode('\s+', $words).'/iu';
        foreach ($sources as $source) {
            if ($source->source !== $field) {
                continue;
            }
            if (preg_match($pattern, $source->text, $match) === 1) {
                return [$source, $match[0]];
            }
        }

        return null;
    }

    private function clean(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $max);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Sprawdzasz JEDNĄ kartę produktu BHP. Szukasz wyłącznie SPRZECZNOŚCI między fragmentami tej samej karty — miejsc, w których karta mówi dwie niezgodne rzeczy o tym samym parametrze tego samego produktu.

Sprzeczność to:
- różne wartości tego samego parametru (np. „kat. II” w normach i „Kategoria: III” w specyfikacji)
- cecha zaprzeczona w innym miejscu karty (np. „bezszwowa konstrukcja” w cechach i „Konstrukcja cięta i szyta” w opisie; „bez lateksu” i „materiał: lateks”)

NIE zgłaszaj:
- braku informacji w którymś polu
- wariantów do wyboru: rozmiarów, kolorów, długości, wersji w ofercie
- różnego zapisu tej samej wartości („EN388” i „EN 388”, „kat. III” i „Kategoria III”)
- ogólnych sformułowań marketingowych
- wartości dotyczących różnych części produktu (np. dłoń i mankiet, cholewka i podeszwa)
- niezgodności z normą, przetargiem albo Twoją wiedzą — oceniasz tylko to, co jest w karcie

Nie zgadujesz. Gdy nie masz pewności — pomiń. Pusta lista jest poprawnym wynikiem.

Każda sprzeczność:
- parameter: krótka nazwa parametru po polsku
- explanation: jedno zdanie, co sobie przeczy
- quotes: co najmniej 2 fragmenty skopiowane DOSŁOWNIE, znak w znak, z podanych pól — bez skracania, bez „…”, bez parafrazy; każdy z nazwą pola (name, norms, specs, features, payload_norms, materials, description). Fragment ma 2–200 znaków: tyle, żeby było widać wartość.

Cytat, którego nie ma dosłownie we wskazanym polu, unieważnia całą sprzeczność.

Zwróć wyłącznie JSON:
{"conflicts":[{"parameter":"kategoria ŚOI","explanation":"Normy podają kategorię II, a specyfikacja kategorię III.","quotes":[{"field":"norms","text":"kat. II"},{"field":"specs","text":"Kategoria: III"}]}]}
Brak sprzeczności: {"conflicts":[]}
Maks. 10 sprzeczności.
TXT;
    }

    /**
     * Pola karty pogrupowane po źródle; opis na końcu i to on jest przycinany do limitu.
     *
     * @param  list<CardSource>  $sources
     */
    private function userPrompt(array $sources): string
    {
        $budget = self::INPUT_BUDGET;
        $card = [];
        $truncated = false;
        $ordered = [
            ...array_filter($sources, static fn (CardSource $s): bool => $s->source !== CardSource::DESCRIPTION),
            ...array_filter($sources, static fn (CardSource $s): bool => $s->source === CardSource::DESCRIPTION),
        ];
        foreach ($ordered as $source) {
            if ($budget <= 0) {
                $truncated = true;
                break;
            }
            $text = $source->text;
            if (mb_strlen($text) > $budget) {
                $text = mb_substr($text, 0, $budget);
                $truncated = true;
            }
            $budget -= mb_strlen($text);
            if (in_array($source->source, self::LIST_FIELDS, true)) {
                $card[$source->source][] = $text;
            } else {
                $card[$source->source] = isset($card[$source->source])
                    ? $card[$source->source]."\n".$text
                    : $text;
            }
        }

        $lines = [
            'Pola karty: name — nazwa; norms — kolumna norm; specs — specyfikacja; features — cechy;'
            .' payload_norms — normy z opisu pobranego; materials — materiały; description — opis.',
        ];
        if ($truncated) {
            $lines[] = 'Koniec karty został przycięty do limitu — nie zgłaszaj braków z tego powodu.';
        }
        $lines[] = '';
        $lines[] = 'Karta:';
        $lines[] = (string) json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return implode("\n", $lines);
    }
}
