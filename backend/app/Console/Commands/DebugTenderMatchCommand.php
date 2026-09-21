<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use App\Services\Search\AiProductSearch;
use Illuminate\Console\Command;

/**
 * Diagnostyka jednej pozycji przetargu: ścieżka „Dopasuj wszystkie” (searchMany) obok wyszukiwarki
 * (search) — linia „Szukany produkt” i warunki wysłane do rankingu, karty w rankingu, oceny modelu
 * i wynik. Tylko odczyt: nic nie zapisuje do przetargu (model jest wywoływany, więc zużywa tokeny).
 * Przetarg 1, poz. 3: wyszukiwarka dawała ARSO 701 S1 P ESD 95%, przebieg przetargu nie.
 */
final class DebugTenderMatchCommand extends Command
{
    protected $signature = 'tenders:debug-match
                            {tender : ID przetargu}
                            {line : Numer pozycji (line_no)}
                            {--sku= : SKU karty, której obecność sprawdzić na każdym etapie}';

    protected $description = 'Porównuje dopasowanie pozycji w „Dopasuj wszystkie” i w wyszukiwarce (tylko odczyt)';

    public function handle(AiSettingsService $settings): int
    {
        $item = TenderItem::query()
            ->where('tender_id', (int) $this->argument('tender'))
            ->where('line_no', (int) $this->argument('line'))
            ->first();
        if (! $item instanceof TenderItem) {
            $this->error('Nie ma takiej pozycji w przetargu.');

            return self::FAILURE;
        }
        if (! $settings->isReady()) {
            $this->error('AI nie jest skonfigurowane — diagnostyka wymaga modelu.');

            return self::FAILURE;
        }

        $requirement = (string) $item->requirement;
        $limit = $settings->catalogSearchLimit();
        $sku = trim((string) $this->option('sku'));
        $this->line('Pozycja '.$item->line_no.': '.mb_substr($requirement, 0, 160));

        // Osobne instancje, bo ślad obu przebiegów siedzi jeszcze w stanie silnika i drugi
        // nadpisałby pierwszy. Znika to razem ze śladem przenoszonym do odpowiedzi.
        $many = app()->make(AiProductSearch::class);
        $many->enableSourceTrace();
        $rows = $many->findMany([$requirement], $limit, AiTask::ProductSearch, $settings->matchConcurrency());
        $this->report('„Dopasuj wszystkie” (findMany)', is_array($rows[0] ?? null) ? $rows[0] : [], $many->lastTrace(), $sku);
        $this->reportDecision(app(ProductMatchService::class)->debugPick($item, is_array($rows[0] ?? null) ? $rows[0] : []));

        $single = app()->make(AiProductSearch::class);
        $single->enableSourceTrace();
        $result = $single->find($requirement, $limit, AiTask::ProductSearch);
        $this->report('Wyszukiwarka (find)', $result, is_array($result['trace'] ?? null) ? $result['trace'] : [], $sku);

        return self::SUCCESS;
    }

    /**
     * @param  array{candidates: list<array{sku: string, model: int, source: string, verdict: string}>, pick: array{sku: string, score: int, source: string, heuristic_only: bool}|null, reason: string|null}  $decision
     */
    private function reportDecision(array $decision): void
    {
        $this->newLine();
        $this->info('== Decyzja przetargu dla wyniku „Dopasuj wszystkie” (bez zapisu)');
        $this->table(
            ['SKU', 'model %', 'źródło', 'werdykt'],
            array_map(static fn (array $c): array => [$c['sku'], $c['model'], $c['source'], $c['verdict']], $decision['candidates']),
        );
        $pick = $decision['pick'];
        $this->line('wybór przetargu: '.($pick === null
            ? 'brak'
            : sprintf('%s (%d%%, %s%s%s)', $pick['sku'], $pick['score'], $pick['source'], $pick['heuristic_only'] ? ', po słowach karty' : '', ($pick['proposal'] ?? false) ? ', propozycja do sprawdzenia' : '')));
        if ($decision['reason'] !== null) {
            $this->line('powód braku karty: '.$decision['reason']);
        }
    }

    /**
     * Dlaczego karta (nie) weszła do 24 kart rankingu: ile igieł z warunków rankingu potwierdza na tle najsłabszej
     * karty w rankingu i czy ma opis i normy. Przetarg 1 poz. 7: ATG 44-304 była w kandydatach, a poza kartami
     * rankingu na produkcji, choć na kopii katalogu potwierdzała wszystkie igły.
     *
     * @param  list<int>  $cardIds
     * @param  array<string, mixed>  $trace
     */
    private function constraintEvidenceLine(string $sku, int $skuId, array $cardIds, array $trace): string
    {
        $sets = is_array($trace['rank_constraints'] ?? null) ? $trace['rank_constraints'] : [];
        $last = $sets === [] ? [] : $sets[array_key_last($sets)];
        $constraints = array_values(array_filter(is_array($last) ? $last : [], 'is_string'));
        $product = Product::query()->find($skuId);
        if (! $product instanceof Product) {
            return 'dowód warunków karty '.$sku.': brak karty';
        }
        $search = app(ProductAiSearchService::class);
        $own = $search->debugConstraintEvidence($product, $constraints);
        $weakest = null;
        foreach (Product::query()->whereIn('id', $cardIds)->get() as $card) {
            $hits = count($search->debugConstraintEvidence($card, $constraints)['matched']);
            if ($weakest === null || $hits < $weakest['hits']) {
                $weakest = ['sku' => (string) $card->sku, 'hits' => $hits];
            }
        }
        $total = count($own['needles']);

        return sprintf(
            'dowód warunków karty %s: %d/%d igieł %s · najsłabsza karta w rankingu: %s · opis: %d znaków · normy: %s',
            $sku,
            count($own['matched']),
            $total,
            json_encode($own['matched'], JSON_UNESCAPED_UNICODE),
            $weakest === null ? 'brak' : $weakest['sku'].' ('.$weakest['hits'].'/'.$total.')',
            mb_strlen((string) ($product->description ?? '')),
            json_encode(mb_substr((string) ($product->norms ?? ''), 0, 80), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Miejsce karty w każdym źródle wyszukiwania i w przyciętej fuzji (ostatnie wyszukiwanie w śladzie): „—/300” = brak
     * w źródle, „87/300” = jest na 87. miejscu. Fuzja przycięta do dwukrotności limitu, a bramka zgodności działa dopiero
     * na przyciętej puli — przetarg 1 poz. 1: rękaw przechodził bramki, a nie było go w puli przed bramką.
     *
     * @param  array<string, mixed>  $trace
     */
    private function sourcesLine(string $sku, int $skuId, array $trace): string
    {
        $all = is_array($trace['sources'] ?? null) ? $trace['sources'] : [];
        if ($all === []) {
            return 'źródła wyszukiwania karty '.$sku.': brak danych (wyszukiwanie bez fuzji źródeł)';
        }
        $last = $all[array_key_last($all)];
        $position = static function (mixed $ids) use ($skuId): string {
            $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));
            $at = array_search($skuId, $ids, true);

            return ($at === false ? '—' : (string) ($at + 1)).'/'.count($ids);
        };

        return sprintf(
            'źródła wyszukiwania karty %s: priorytet=%s · kaskada przed bramką=%s · kaskada po bramce=%s · tekst=%s · bez rodziny=%s · wektor=%s · fuzja=%s (przycięta do %d)',
            $sku,
            $position($last['priority'] ?? []),
            $position($last['cascade_found'] ?? []),
            $position($last['cascade'] ?? []),
            $position($last['text'] ?? []),
            $position($last['unclassified'] ?? []),
            $position($last['vector'] ?? []),
            $position($last['fused'] ?? []),
            (int) ($last['fused_cap'] ?? 0),
        );
    }

    /**
     * Czy karta była w puli przed bramką zgodności i które bramki ją odrzuciły. Przetarg 1 poz. 1: HyFlex 11-202
     * z angielskim opisem przechodził bramkę rodziny, a odrzucały go dowód żargonu („rękaw”) i antystatyka („antystatyczn”)
     * — z samego „w kandydatach=nie” nie dało się tego odróżnić od braku karty w wyszukiwaniu.
     *
     * @param  array<string, mixed>  $trace
     */
    private function compatibilityGatesLine(string $sku, int $skuId, string $query, array $trace): string
    {
        $product = Product::query()->find($skuId);
        if (! $product instanceof Product || trim($query) === '') {
            return 'bramki zgodności karty '.$sku.': brak danych';
        }
        $needed = is_array($trace['rank_needed'] ?? null) && $trace['rank_needed'] !== []
            ? $trace['rank_needed'][array_key_last($trace['rank_needed'])]
            : null;
        $gates = app()->make(ProductAiSearchService::class)->debugCompatibilityGates($query, is_string($needed) ? $needed : null, $product);
        $failed = array_keys(array_filter($gates, static fn (bool $ok): bool => ! $ok));
        $pregate = in_array($skuId, array_map('intval', is_array($trace['pregate_ids'] ?? null) ? $trace['pregate_ids'] : []), true);

        return sprintf(
            'bramki zgodności karty %s: w puli przed bramką=%s · %s',
            $sku,
            $pregate ? 'tak' : 'nie',
            $failed === [] ? 'przeszła wszystkie' : 'odrzucona: '.implode(', ', $failed),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $trace
     */
    private function report(string $title, array $result, array $trace, string $sku): void
    {
        $this->newLine();
        $this->info('== '.$title);
        if (is_string($result['model_state'] ?? null)) {
            $this->line('stan modelu: '.$result['model_state']);
        }
        $this->line('needed w rankingu: '.json_encode($trace['rank_needed'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('warunki w rankingu: '.json_encode($trace['rank_constraints'] ?? [], JSON_UNESCAPED_UNICODE));
        foreach (is_array($trace['cascade'] ?? null) ? $trace['cascade'] : [] as $cascade) {
            $this->line(sprintf(
                'kaskada: poziom=%s · kroki=%s · zdjęte kroki=%d · znalazła=%d · po bramce=%d · %s',
                (string) ($cascade['level'] ?? 'brak'),
                json_encode($cascade['steps'] ?? [], JSON_UNESCAPED_UNICODE),
                (int) ($cascade['dropped_steps'] ?? 0),
                (int) ($cascade['found'] ?? 0),
                (int) ($cascade['kept'] ?? 0),
                ($cascade['ended_retrieval'] ?? false) ? 'zakończyła wyszukiwanie (bez wyszukiwania tekstowego)' : 'wyszukiwanie szło dalej',
            ));
        }

        $candidateIds = array_map('intval', is_array($trace['candidate_ids'] ?? null) ? $trace['candidate_ids'] : []);
        $cardIds = array_map('intval', is_array($trace['rank_card_ids'] ?? null) ? $trace['rank_card_ids'] : []);
        $matches = is_array($trace['llm_matches'] ?? null) ? $trace['llm_matches'] : [];
        $ids = array_values(array_unique([...$candidateIds, ...$cardIds, ...array_map(
            static fn (array $m): int => (int) ($m['id'] ?? 0),
            $matches
        )]));
        /** @var array<int, string> $skus */
        $skus = Product::query()->whereIn('id', $ids)->pluck('sku', 'id')->all();
        $label = static fn (int $id): string => (string) ($skus[$id] ?? $id);

        $this->line('kandydaci: '.count($candidateIds).' | karty w rankingu: '.count($cardIds));
        $this->line('karty w rankingu: '.implode(' ; ', array_map($label, $cardIds)));
        $this->line('oceny modelu: '.implode(' ; ', array_map(
            static fn (array $m): string => $label((int) ($m['id'] ?? 0)).'='.(int) ($m['score'] ?? 0),
            $matches
        )));

        if ($sku !== '') {
            $skuId = (int) (Product::query()->where('sku', $sku)->value('id') ?? 0);
            $score = null;
            foreach ($matches as $m) {
                if ((int) ($m['id'] ?? 0) === $skuId) {
                    $score = (int) ($m['score'] ?? 0);
                }
            }
            $this->line(sprintf(
                'karta %s (id %d): w kandydatach=%s, w kartach rankingu=%s, ocena modelu=%s',
                $sku,
                $skuId,
                in_array($skuId, $candidateIds, true) ? 'tak' : 'nie',
                in_array($skuId, $cardIds, true) ? 'tak' : 'nie',
                $score === null ? 'brak' : (string) $score,
            ));
            if ($skuId > 0) {
                $this->line($this->constraintEvidenceLine($sku, $skuId, $cardIds, $trace));
                $this->line($this->compatibilityGatesLine($sku, $skuId, (string) ($result['query'] ?? ''), $trace));
                $this->line($this->sourcesLine($sku, $skuId, $trace));
            }
        }

        $products = is_array($result['products'] ?? null) ? array_slice($result['products'], 0, 10) : [];
        $this->table(
            ['#', 'SKU', '%', 'źródło', 'uzasadnienie'],
            array_map(static fn (array $row, int $i): array => [
                $i + 1,
                (string) ($row['sku'] ?? ''),
                (string) ($row['ai_match_percent'] ?? ''),
                (string) ($row['ai_match_source'] ?? 'model'),
                mb_substr((string) ($row['ai_match_reason'] ?? ''), 0, 140),
            ], $products, array_keys($products)),
        );
    }
}
