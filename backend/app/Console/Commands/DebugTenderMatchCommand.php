<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
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

        $many = app()->make(ProductAiSearchService::class);
        $rows = $many->searchMany([$requirement], $limit, false, AiTask::ProductSearch, $settings->matchConcurrency());
        $this->report('„Dopasuj wszystkie” (searchMany)', is_array($rows[0] ?? null) ? $rows[0] : [], $many->lastTrace(), $sku);
        $this->reportDecision(app(ProductMatchService::class)->debugPick($item, is_array($rows[0] ?? null) ? $rows[0] : []));

        $single = app()->make(ProductAiSearchService::class);
        $result = $single->search($requirement, $limit, false, AiTask::ProductSearch);
        $this->report('Wyszukiwarka (search)', $result, $single->lastTrace(), $sku);

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
            : sprintf('%s (%d%%, %s%s)', $pick['sku'], $pick['score'], $pick['source'], $pick['heuristic_only'] ? ', po słowach karty' : '')));
        if ($decision['reason'] !== null) {
            $this->line('powód braku karty: '.$decision['reason']);
        }
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
