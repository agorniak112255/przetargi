<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\OpenAiCompatibleClient;
use Mockery;
use RuntimeException;

/**
 * Stub modelu dla wyszukiwarki i dopasowania przetargu. Rozpoznaje rodzaj promptu po tekście
 * systemowym z ProductAiSearchService i odpowiada bez wywołania modelu.
 *
 * Deklaruje `chatJson` i `chatJsonMany` (`byDefault`, więc test może nadpisać) oraz
 * `shouldNotReceive('chat')` — przypadkowe zejście do niższej warstwy klienta ma być
 * widoczne, a nie zwracać po cichu `null`.
 */
final class FakeSearchLlm
{
    public const KIND_UNDERSTAND = 'understand';

    public const KIND_REWRITE = 'rewrite';

    public const KIND_RANK = 'rank';

    public const KIND_OTHER = 'other';

    /**
     * Markery promptów — fragmenty tekstu z ProductAiSearchService::understandSystemPrompt()
     * i analyzeAndRankMessages(). Gdy prompt się zmieni, stub przestanie rozpoznawać rodzaj
     * i odpowie jak na nieznany prompt (intencja lokalna / pusty ranking) — test to pokaże.
     */
    private const MARKER_UNDERSTAND = 'Najpierw ZROZUM';

    private const MARKER_REWRITE = 'Pierwsze wyszukiwanie nic nie dało';

    private const MARKER_RANK = 'Ranking w dwóch krokach';

    /** Tyle znaków wymagania wystarcza, żeby rozpoznać pozycję w wiadomości użytkownika. */
    private const REQUIREMENT_PREFIX = 60;

    /**
     * Model nic nie wnosi: „zrozum”/„przepisz” kończą się intencją lokalną (wyjątek w chatJson,
     * pusta odpowiedź w chatJsonMany — dokładnie tak sygnalizuje awarię prawdziwy klient),
     * a ranking jest pusty. Odtwarza ścieżkę deterministyczną dopasowania (AUDYT_D §4.2).
     */
    public static function empty(): OpenAiCompatibleClient
    {
        return self::build(
            static function (string $kind): array {
                if ($kind === self::KIND_RANK) {
                    return ['matches' => []];
                }

                throw new RuntimeException('FakeSearchLlm::empty — model nie odpowiada ('.$kind.').');
            },
            static fn (string $kind): array => $kind === self::KIND_RANK ? ['matches' => []] : [],
        );
    }

    /**
     * Model „jak produkcja”: na „zrozum”/„przepisz” oddaje intencję zapisaną w `prod_search`
     * pozycji (needed, search_steps, manufacturer_requested, search_phrases), a ranking buduje
     * `$rank(array $line, list<int> $cardIds, array<string,int> $idBySku): list<array{id,score,reason}>`
     * z id kart, które serwis wysłał w prompcie.
     *
     * @param  list<array<string, mixed>>  $items  pozycje z Opisowy15Fixture::items()
     * @param  array<string, int>  $idBySku  mapa sku => id z Opisowy15Fixture::seed()
     */
    public static function replay(array $items, array $idBySku, callable $rank): OpenAiCompatibleClient
    {
        $answer = static function (string $kind, array $messages, bool $single) use ($items, $idBySku, $rank): array {
            $user = (string) ($messages[1]['content'] ?? '');
            $line = self::lineFor($items, $user);

            if ($kind === self::KIND_RANK) {
                if ($line === null) {
                    return ['matches' => []];
                }
                preg_match_all('/"id":(\d+)/', $user, $m);
                $cardIds = array_values(array_unique(array_map('intval', $m[1] ?? [])));

                return [
                    'needed' => (string) ($line['prod_search']['needed'] ?? ''),
                    'search_phrases' => [],
                    'constraints' => [],
                    'matches' => array_values($rank($line, $cardIds, $idBySku)),
                ];
            }

            $intent = $line !== null ? self::productionIntent($line) : null;
            if ($intent === null) {
                if ($single) {
                    throw new RuntimeException('FakeSearchLlm::replay — brak intencji produkcyjnej dla tego wymagania.');
                }

                return [];
            }

            return $intent;
        };

        return self::build(
            static fn (string $kind, array $messages): array => $answer($kind, $messages, true),
            static fn (string $kind, array $messages): array => $answer($kind, $messages, false),
        );
    }

    /**
     * Ranking z sondy audytu (wariant A): właściwa karta 90, każda zakazana 92 — model
     * „widzi” obie, a wybór między nimi należy do dopasowania przetargu.
     */
    public static function rankExpectedAndForbidden(int $expectedScore = 90, int $forbiddenScore = 92): callable
    {
        return static function (array $line, array $cardIds, array $idBySku) use ($expectedScore, $forbiddenScore): array {
            $matches = [];
            $expected = $idBySku[(string) $line['expected_sku']] ?? null;
            if ($expected !== null && in_array($expected, $cardIds, true)) {
                $matches[] = ['id' => $expected, 'score' => $expectedScore, 'reason' => 'stub: karta właściwa'];
            }
            foreach ((array) ($line['forbidden_skus'] ?? []) as $sku) {
                $id = $idBySku[(string) $sku] ?? null;
                if ($id !== null && in_array($id, $cardIds, true)) {
                    $matches[] = ['id' => $id, 'score' => $forbiddenScore, 'reason' => 'stub: karta błędna z produkcji'];
                }
            }

            return $matches;
        };
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     */
    public static function kind(array $messages): string
    {
        $system = (string) ($messages[0]['content'] ?? '');
        if (str_contains($system, self::MARKER_RANK)) {
            return self::KIND_RANK;
        }
        if (str_contains($system, self::MARKER_REWRITE)) {
            return self::KIND_REWRITE;
        }
        if (str_contains($system, self::MARKER_UNDERSTAND)) {
            return self::KIND_UNDERSTAND;
        }

        return self::KIND_OTHER;
    }

    /**
     * @param  callable(string, array): array  $single  odpowiedź na chatJson
     * @param  callable(string, array): array  $many  odpowiedź na jeden zestaw w chatJsonMany
     */
    private static function build(callable $single, callable $many): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(static fn (array $messages): array => $single(self::kind($messages), $messages))
            ->byDefault();
        $llm->shouldReceive('chatJsonMany')
            ->andReturnUsing(static function (array $messageSets) use ($many): array {
                $out = [];
                foreach ($messageSets as $messages) {
                    $out[] = $many(self::kind($messages), $messages);
                }

                return $out;
            })
            ->byDefault();
        $llm->shouldNotReceive('chat');

        return $llm;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private static function lineFor(array $items, string $userMessage): ?array
    {
        foreach ($items as $item) {
            $prefix = mb_substr((string) ($item['requirement'] ?? ''), 0, self::REQUIREMENT_PREFIX);
            if ($prefix !== '' && str_contains($userMessage, $prefix)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>|null null, gdy produkcja nie zapisała intencji (poz. 2: błąd zapytania)
     */
    private static function productionIntent(array $line): ?array
    {
        $search = is_array($line['prod_search'] ?? null) ? $line['prod_search'] : [];
        $needed = trim((string) ($search['needed'] ?? ''));
        if ($needed === '') {
            return null;
        }
        $parsed = is_array($search['parsed_intent'] ?? null) ? $search['parsed_intent'] : [];
        $phrases = [];
        foreach ((array) ($search['search_phrases'] ?? []) as $phrase) {
            if (is_string($phrase) && mb_strlen($phrase) < 120) {
                $phrases[] = $phrase;
            }
        }

        return [
            'needed' => $needed,
            'search_steps' => array_values((array) ($parsed['search_steps'] ?? [])),
            'manufacturer' => $parsed['manufacturer_requested'] ?? null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => $phrases,
            'constraints' => [],
        ];
    }
}
