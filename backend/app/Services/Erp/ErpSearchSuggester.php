<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Services\Search\AiProductSearch;
use App\Support\CanonicalBrand;
use App\Support\PpeAssortment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Propozycje kart dla towarów ERP XL bez kodu w nazwie („RĘKAWICE TACTYL”, „TRZEWIKI REDBONE S1”): nazwa XL idzie
 * przez pulę kandydatów wyszukiwarki (AiProductSearch::candidates — tekst i wektory, bez modelu językowego), a sito
 * przepuszcza tylko karty, które są tym samym wyrobem z dużym prawdopodobieństwem:
 * - ten sam rodzaj wyrobu (PpeAssortment::family), gdy znany po obu stronach,
 * - bez sprzecznej marki (marka z nazwy XL ≠ producent karty),
 * - karta ma co najmniej połowę słów charakterystycznych nazwy XL (rzadkich w XL, bez marek, kolorów i słów ogólnych;
 *   skrót XL zgodny z początkiem słowa karty), w tym co najmniej jedno rzadkie w katalogu (≤ RARE_WORD_MAX_CARDS
 *   kart) — nazwę modelu; zostają tylko karty z największą liczbą wspólnych słów (symulacja na 300 towarach
 *   z produkcji 29.09.2026: „ponad połowa” i reguła znaków gubiły trafne propozycje znaków i instrukcji). Podobny opis to jeszcze nie ten sam wyrób: bez tego „rękawice winylowe
 *   białe” trafiałyby w każde rękawice winylowe, a „KOSZULA FLANELOWA” czy „POJEMNIK NA MOCZ” w przypadkowe karty ze
 *   słowem rzadkim tylko w katalogu (przegląd próbki 600 towarów 29.09.2026). Sama marka („FILIP”, „TYCHEM”) nie
 *   wskazuje wyrobu.
 * Wynik to wyłącznie propozycje (suggested, method=search) do decyzji na ekranie „Powiązania z ERP XL”.
 */
final class ErpSearchSuggester
{
    /** Ile kandydatów z wyszukiwarki oglądamy na towar. */
    private const POOL = 15;

    /** Kolory i ich odmiany („POMARAŃCZ.”, „ŻÓŁTE”, „GRANATOWA”) nie odróżniają modeli. */
    private const COLOUR = '/^(CZARN|BIAL|ZOLT|ZIELON|NIEBIESK|GRANAT|CZERWON|POMARANCZ|SZAR|BRAZ|BEZOW|FIOLET|ROZOW|SREBRN|ZLOT|LIMONK|OLIWK|KHAKI|BORDO|BLEKIT|MORSK|GRAFIT|ANTRACYT)/';

    /** Najwięcej propozycji na towar. */
    private const MAX_SUGGESTIONS = 5;

    /** Słowo z nazw najwyżej tylu kart to nazwa modelu (rzadkie); częstsze to opis rodzaju, materiału, koloru. */
    private const RARE_WORD_MAX_CARDS = 40;

    /** …i w nazwach najwyżej tylu aktywnych towarów XL — słowa opisowe XL (FLANELOWA, PASTA, CZEPEK) są tam częste. */
    private const RARE_WORD_MAX_XL_ITEMS = 25;

    /** Bierzemy towary ze stanem albo sprzedane w tylu ostatnich miesiącach. */
    private const ACTIVE_MONTHS = 24;

    /** Wyniki łączenia po kodzie, po których warto szukać po nazwie. */
    public const SEARCHABLE_OUTCOMES = ['no_code', 'no_match', 'family_conflict', 'search_suggested'];

    /** @var array<string, int>|null słowo → w ilu nazwach kart występuje */
    private ?array $wordCards = null;

    /** @var array<string, list<int>>|null słowo rzadkie w nazwach kart (≤ RARE_WORD_MAX_CARDS) → karty */
    private ?array $rareWordCards = null;

    /** @var array<string, int>|null słowo → w ilu nazwach aktywnych towarów XL występuje */
    private ?array $wordXlItems = null;

    public function __construct(
        private readonly AiProductSearch $search,
        private readonly ErpItemMatcher $matcher,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * @param  (callable(int): void)|null  $progress
     * @return array{checked: int, with_suggestions: int, suggestions: int}
     */
    public function run(int $limit, int $recheckDays, ?callable $progress = null): array
    {
        $now = CarbonImmutable::now();
        $stats = ['checked' => 0, 'with_suggestions' => 0, 'suggestions' => 0];
        $ids = ErpItem::query()
            ->whereNull('removed_at')
            ->where('archived', false)
            ->whereIn('match_outcome', self::SEARCHABLE_OUTCOMES)
            ->where(fn ($q) => $q->where('stock_trade', '>', 0)
                ->orWhere('last_sale_at', '>=', $now->subMonths(self::ACTIVE_MONTHS)->toDateString()))
            ->where(fn ($q) => $q->whereNull('search_checked_at')->orWhere('search_checked_at', '<', $now->subDays($recheckDays)))
            // najpierw nigdy nieprzeszukane, potem największy stan i świeża sprzedaż
            ->orderByRaw('case when search_checked_at is null then 0 else 1 end')
            ->orderByDesc('stock_trade')
            ->orderByDesc('last_sale_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids->chunk(200) as $chunk) {
            foreach (ErpItem::query()->whereIn('id', $chunk)->get() as $item) {
                $count = $this->suggestFor($item, $now);
                $stats['checked']++;
                if ($count > 0) {
                    $stats['with_suggestions']++;
                    $stats['suggestions'] += $count;
                }
                if ($progress !== null) {
                    $progress($stats['checked']);
                }
            }
        }
        $this->wordCards = null;
        $this->rareWordCards = null;
        $this->wordXlItems = null;

        return $stats;
    }

    /** @return int liczba propozycji zapisanych dla towaru */
    public function suggestFor(ErpItem $item, CarbonImmutable $now): int
    {
        $xlText = trim($item->name.' '.$item->name1);
        $cards = $this->pick($item, $xlText);

        DB::transaction(function () use ($item, $cards, $now): void {
            $keep = array_column($cards, 'product_id');
            // stare propozycje z wyszukiwarki, których już nie ma — decyzje człowieka zostają
            ErpItemLink::query()
                ->where('erp_item_id', $item->id)
                ->where('method', ErpItemLink::METHOD_SEARCH)
                ->where('status', ErpItemLink::STATUS_SUGGESTED)
                ->whereNotIn('product_id', $keep === [] ? [0] : $keep)
                ->delete();
            foreach ($cards as $rank => $card) {
                $link = ErpItemLink::query()->firstOrNew(['erp_item_id' => $item->id, 'product_id' => $card['product_id']]);
                if ($link->exists && $link->status !== ErpItemLink::STATUS_SUGGESTED) {
                    continue;
                }
                $link->fill([
                    'status' => ErpItemLink::STATUS_SUGGESTED,
                    'method' => ErpItemLink::METHOD_SEARCH,
                    'matched_value' => null,
                    'matched_code' => null,
                    'evidence' => $card['evidence'] + ['rank' => $rank + 1, 'candidates' => count($cards), 'tier' => 'search'],
                    'last_seen_at' => $now,
                ])->save();
            }
            $open = ErpItemLink::query()->where('erp_item_id', $item->id)
                ->where('method', ErpItemLink::METHOD_SEARCH)->where('status', ErpItemLink::STATUS_SUGGESTED)->exists();
            $outcome = $item->match_outcome;
            if ($open) {
                $outcome = 'search_suggested';
            } elseif ($outcome === 'search_suggested') {
                // propozycje zniknęły (odrzucone albo katalog się zmienił) — wraca wynik łączenia po kodzie
                $outcome = $item->match_value !== null ? 'no_match' : 'no_code';
            }
            $item->update(['match_outcome' => $outcome, 'search_checked_at' => $now]);
        });

        return count($cards);
    }

    /**
     * @return list<array{product_id: int, evidence: array<string, mixed>}>
     */
    private function pick(ErpItem $item, string $xlText): array
    {
        $rejected = ErpItemLink::query()->where('erp_item_id', $item->id)
            ->where('status', ErpItemLink::STATUS_REJECTED)->pluck('product_id')->map(fn ($id) => (int) $id)->flip();
        $xlFamily = $this->assortment->family($xlText);
        $xlBrands = $this->matcher->brandKeysIn($xlText);
        // słowa i zapytanie z samej nazwy — Nazwa1 bywa notatką („1 karton = 96”, „200 par”)
        $xlWords = $this->distinctiveWords((string) $item->name);
        if ($xlWords === []) {
            return [];
        }
        // co najmniej połowa słów charakterystycznych nazwy XL w nazwie karty; zostają karty z największą liczbą
        $needed = max(1, intdiv(count($xlWords) + 1, 2));
        $cardsWithWord = $this->wordCards();
        $suppliers = array_values(array_filter(array_map(
            static fn (array $s): string => (string) ($s['supplier'] ?? ''),
            $item->suppliers ?? [],
        )));
        if ($item->last_supplier !== null) {
            $suppliers[] = $item->last_supplier;
        }

        $out = [];
        /** @var Product $product */
        foreach ($this->pool($item, $xlWords) as $product) {
            if (isset($rejected[(int) $product->id])) {
                continue;
            }
            $cardFamily = $this->assortment->family((string) $product->name);
            if ($xlFamily !== null && $cardFamily !== null && $xlFamily !== $cardFamily) {
                continue;
            }
            // nazwa XL nie mówi o rodzaju wyrobu ochronnego („SKARPETA MĘSKA”, „ŚCIERKA TETRA”), a karta tak — inny wyrób;
            // odwrotnie wolno: karty MAPA bywają samą nazwą modelu („ULTRANE 550”)
            if ($xlFamily === null && $cardFamily !== null) {
                continue;
            }
            $cardBrand = CanonicalBrand::key((string) $product->manufacturer);
            if ($xlBrands !== [] && $cardBrand !== '' && ! in_array($cardBrand, $xlBrands, true)) {
                continue;
            }
            [$shared, $rare] = $this->sharedWords($xlWords, $this->matcher->words((string) $product->name), $cardsWithWord);
            // co najmniej jedno wspólne słowo rzadkie w katalogu — nazwa modelu, nie opis powtarzany w wielu kartach
            if (count($shared) < $needed || ! $rare) {
                continue;
            }
            $supplier = null;
            foreach ($suppliers as $s) {
                if ($cardBrand !== '' && CanonicalBrand::key($s) === $cardBrand) {
                    $supplier = $s;
                    break;
                }
            }
            $out[] = [
                'product_id' => (int) $product->id,
                'shared' => count($shared),
                'evidence' => [
                    'shared_words' => array_slice($shared, 0, 5),
                    'brand_in_name' => $cardBrand !== '' && in_array($cardBrand, $xlBrands, true),
                    'supplier_match' => $supplier !== null,
                    'supplier' => $supplier,
                    'same_family' => $xlFamily !== null && $xlFamily === $cardFamily,
                ],
            ];
        }
        if ($out === []) {
            return [];
        }
        // „ZNAK UWAGA ROBOTY BUDOWLANE”: gdy w puli jest karta z oboma słowami, „Roboty drogowe” odpada
        $best = max(array_column($out, 'shared'));
        $out = array_values(array_filter($out, static fn (array $c): bool => $c['shared'] === $best));

        return array_map(
            static fn (array $c): array => ['product_id' => $c['product_id'], 'evidence' => $c['evidence']],
            array_slice($out, 0, self::MAX_SUGGESTIONS),
        );
    }

    /**
     * Wspólne słowa nazwy XL i karty. Słowo XL bywa skrótem („WARSZT.”, „KOTŁ.”, „OPAL.”) — zgodne, gdy jest początkiem
     * słowa karty.
     *
     * @param  list<string>  $xlWords
     * @param  list<string>  $cardWords
     * @param  array<string, int>  $cardsWithWord
     * @return array{0: list<string>, 1: bool} [wspólne słowa XL, czy któreś jest rzadkie w katalogu]
     */
    private function sharedWords(array $xlWords, array $cardWords, array $cardsWithWord): array
    {
        $shared = [];
        $rare = false;
        foreach ($xlWords as $word) {
            foreach ($cardWords as $cardWord) {
                if ($cardWord === $word || str_starts_with($cardWord, $word)) {
                    $shared[] = $word;
                    $rare = $rare || ($cardsWithWord[$cardWord] ?? 0) <= self::RARE_WORD_MAX_CARDS;
                    break;
                }
            }
        }

        return [$shared, $rare];
    }

    /** Nazwa XL do wyszukiwarki: bez skrótów z kropką („OCHR.”, „ROB.”) sklejonych z następnym słowem. */
    private function query(string $xlText): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('.', '. ', $xlText)));
    }

    /**
     * Słowa charakterystyczne nazwy XL: nie ogólne, nie kolor, nie marka, rzadkie w nazwach towarów XL. Słowo, którego
     * nie ma w żadnej karcie, też się liczy („OKULARY AMBRIC Z OSŁ. PIANKOWĄ” — bez AMBRIC w katalogu karta ze słowem
     * „piankowa” to nie ten wyrób).
     *
     * @return list<string>
     */
    private function distinctiveWords(string $text): array
    {
        $xlItems = $this->wordXlItems();
        $generic = array_flip(ErpItemMatcher::GENERIC_WORDS);
        $out = [];
        foreach ($this->matcher->words($text) as $word) {
            if (strlen($word) < 4 || isset($generic[$word]) || preg_match(self::COLOUR, $word) === 1) {
                continue;
            }
            if (($xlItems[$word] ?? 0) > self::RARE_WORD_MAX_XL_ITEMS || $this->matcher->brandKeysIn($word) !== []) {
                continue;
            }
            $out[] = $word;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function wordXlItems(): array
    {
        if ($this->wordXlItems !== null) {
            return $this->wordXlItems;
        }
        $counts = [];
        foreach (ErpItem::query()->whereNull('removed_at')->where('archived', false)->select(['id', 'name', 'name1'])->lazyById(5000) as $row) {
            foreach ($this->matcher->words(trim($row->name.' '.$row->name1)) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }

        return $this->wordXlItems = $counts;
    }

    /**
     * Kandydaci: najpierw karty z rzadkim słowem nazwy XL dokładnie w nazwie (nazwa modelu — wyszukiwarka układa pulę
     * po słowach ogólnych i „CITRIN” spada poza nią), potem pula wyszukiwarki. Bez powtórzeń.
     *
     * @param  list<string>  $xlWords
     * @return list<Product>
     */
    private function pool(ErpItem $item, array $xlWords): array
    {
        $this->wordCards();
        $ids = [];
        foreach ($xlWords as $word) {
            foreach ($this->rareWordCards[$word] ?? [] as $id) {
                $ids[$id] = true;
            }
        }
        $out = [];
        if ($ids !== []) {
            foreach (Product::query()->whereIn('id', array_keys($ids))->orderBy('id')->get(['id', 'sku', 'name', 'manufacturer']) as $product) {
                $out[(int) $product->id] = $product;
            }
        }
        foreach ($this->search->candidates($this->query((string) $item->name), self::POOL) as $product) {
            $out[(int) $product->id] ??= $product;
        }

        return array_values($out);
    }

    /** @return array<string, int> */
    private function wordCards(): array
    {
        if ($this->wordCards !== null) {
            return $this->wordCards;
        }
        $counts = [];
        $postings = [];
        foreach (DB::table('products')->select(['id', 'name'])->lazyById(5000) as $row) {
            foreach ($this->matcher->words((string) $row->name) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
                // listy kart tylko dla słów jeszcze rzadkich — częste odpadają w trakcie, żeby nie trzymać ich w pamięci
                if ($counts[$word] <= self::RARE_WORD_MAX_CARDS) {
                    $postings[$word][] = (int) $row->id;
                } else {
                    unset($postings[$word]);
                }
            }
        }
        $this->rareWordCards = $postings;

        return $this->wordCards = $counts;
    }
}
