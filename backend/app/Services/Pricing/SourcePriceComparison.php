<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bManufacturerRules;
use App\Services\B2b\B2bOrderQuantity;
use App\Services\NbpExchangeRateService;
use App\Support\BrandKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Porównanie cen zakupu ze źródeł karty (etap E planu łączenia kart, 23.09.2026): od najtańszej ceny zakupu netto
 * w PLN (kurs NBP). To tylko informacja — cenę karty dalej ustala ProductEffectivePrice (pierwszeństwo producenta,
 * decyzja 3). Przykład z produkcji: karta 9669 ATG „MaxiChem Cut” — Ardon (dystrybutor) 33,50 zł obowiązuje,
 * cennik ATG z pliku 52,72 zł jest cennikiem sugerowanym i do porównania nie wchodzi.
 *
 * Porównujemy wyłącznie cenę zakupu (purchase_price > 0) — bez zastępowania jej ceną katalogową jak w resolve():
 * katalogowa to nie jest cena, za którą kupimy. Źródło poza porównaniem dostaje krótki powód po polsku.
 *
 * Row: purchase_price_pln, comparable, not_comparable_reason, price_rank, is_cheapest, diff_to_effective_pct.
 * CheaperSource: source_key, label, purchase_price_pln, diff_pct — tylko gdy najtańsze porównywalne źródło nie jest
 * obowiązującym i jest tańsze od ceny karty co najmniej o 1%.
 */
final class SourcePriceComparison
{
    /** Slot B2B niepotwierdzony przez synchronizację tyle dni — cena mogła się zmienić albo pozycja zniknąć. */
    public const STALE_DAYS = 30;

    /** Cena tyle razy niższa od obowiązującej to zwykle inna jednostka (sztuka zamiast pary, opakowanie). */
    public const UNIT_RATIO = 3.0;

    /** Różnica poniżej 1% to szum zaokrągleń i kursu — lista i przetarg jej nie pokazują. */
    public const MIN_SAVING_PCT = -1.0;

    public function __construct(
        private readonly NbpExchangeRateService $fx,
        private readonly B2bConnectorRegistry $connectors,
        private readonly B2bManufacturerRules $rules,
    ) {}

    /**
     * Wiersze porównania dla karty wyrobu (GET /products/{id}), po source_key, i kurs, którym liczono.
     *
     * @param  Collection<int, ProductSourcePrice>  $slots  sloty karty z priceList (manufacturer, suggested_prices)
     * @param  array{winner: ProductSourcePrice|null, reasons: array<string, string>}  $explain  ProductEffectivePrice::explain
     * @return array{rows: array<string, array{purchase_price_pln: float|null, comparable: bool, not_comparable_reason: string|null, price_rank: int|null, is_cheapest: bool, diff_to_effective_pct: float|null}>, rates: array{as_of: string|null, source: string}}
     */
    public function forCard(Product $product, Collection $slots, array $explain): array
    {
        $accountIds = $this->accountIds($slots);
        $links = $slots->isEmpty() ? [] : ($this->linksByProduct([(int) $product->id])[(int) $product->id] ?? []);
        $hasVariants = $slots->isNotEmpty()
            && ProductVariant::query()->where('product_id', $product->id)->whereNull('removed_at')->exists();

        return [
            'rows' => $this->rows(
                $product,
                $slots,
                $explain['winner']?->source_key,
                $hasVariants,
                $links,
                $this->rules->priceDisabled($accountIds),
                $explain['reasons'],
            ),
            'rates' => $this->rates($product, $slots),
        ];
    }

    /**
     * Najtańsze tańsze źródło dla wielu kart (lista produktów, przetarg) — stała liczba zapytań na każde 1000 kart
     * (sloty, konta, powiązania, reguły „Producenci”, wersje), bez explain() per karta.
     *
     * @param  Collection<int, Product>  $products  karty z id, manufacturer, purchase_price, currency
     * @return array<int, array{source_key: string, label: string, purchase_price_pln: float, diff_pct: float}|null>
     */
    public function cheaperSources(Collection $products): array
    {
        $out = [];
        $candidates = [];
        foreach ($products as $product) {
            $out[(int) $product->id] = null;
            // bez ceny zakupu karty nie ma różnicy do czego liczyć (diff_pct), więc nie ma i „taniej u …”
            if ((float) ($product->purchase_price ?? 0) > 0) {
                $candidates[(int) $product->id] = $product;
            }
        }

        foreach (array_chunk(array_keys($candidates), 1000) as $chunk) {
            // karta z jednym slotem nie ma tańszego źródła: jedyny slot obowiązuje albo jest poza porównaniem
            $multi = ProductSourcePrice::query()
                ->whereIn('product_id', $chunk)
                ->groupBy('product_id')
                ->havingRaw('count(*) > 1')
                ->pluck('product_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($multi === []) {
                continue;
            }

            [$slotsByProduct, $accounts, $links, $disabled, $withVariants] = $this->batch($multi);

            foreach ($slotsByProduct as $productId => $slots) {
                $product = $candidates[(int) $productId];
                $productLinks = $links[(int) $productId] ?? [];
                $hasVariants = isset($withVariants[(int) $productId]);
                $effectiveKey = $hasVariants ? null : $this->winnerKey($product, $slots, $accounts, $productLinks, $disabled);
                $rows = $this->rows($product, $slots, $effectiveKey, $hasVariants, $productLinks, $disabled);
                $out[(int) $productId] = $this->cheaperFromRows(
                    $rows,
                    $effectiveKey,
                    fn (string $key): string => $this->sourceLabel(
                        $slots->first(static fn (ProductSourcePrice $s): bool => (string) $s->source_key === $key),
                        $accounts,
                    ),
                );
            }
        }

        return $out;
    }

    /**
     * Warunek zamawiania obowiązującego źródła (od niego kupujemy) dla wielu kart — lista, przetarg, zapytanie.
     * Tylko karty, których obowiązujący slot ogranicza zamówienie (minimum > 1 albo krok > 1, np. UVEX „po 10 szt.”)
     * albo ma warunek zależny od rozmiaru (varies), albo warunek ceny (price_note, np. Delta Plus: pełny karton);
     * warunek przegranego źródła tu nie trafia (jest w wierszach „Ceny ze źródeł” na karcie). Karta z aktywnymi
     * wersjami nie ma obowiązującego slotu. Stała liczba zapytań na 1000 kart; slotów bez warunku nie czytamy.
     *
     * @param  Collection<int, Product>  $products  karty z id i manufacturer
     * @return array<int, array{min: float|null, step: float|null, unit: string|null, varies: bool, price_note: string|null, price_carton_qty: float|null, source_key: string, source_label: string}>
     */
    public function orderQuantities(Collection $products): array
    {
        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product->id] = $product;
        }
        $out = [];
        foreach (array_chunk(array_keys($byId), 1000) as $chunk) {
            $restricted = ProductSourcePrice::query()
                ->whereIn('product_id', $chunk)
                ->where(static fn ($q) => $q->where('order_min_qty', '>', 1)
                    ->orWhere('order_step_qty', '>', 1)
                    ->orWhere('order_varies', true)
                    ->orWhereNotNull('price_note'))
                ->distinct()
                ->pluck('product_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($restricted === []) {
                continue;
            }

            [$slotsByProduct, $accounts, $links, $disabled, $withVariants] = $this->batch($restricted);
            foreach ($slotsByProduct as $productId => $slots) {
                if (isset($withVariants[(int) $productId])) {
                    continue;
                }
                $key = $this->winnerKey($byId[(int) $productId], $slots, $accounts, $links[(int) $productId] ?? [], $disabled);
                $slot = $key === null ? null : $slots->first(static fn (ProductSourcePrice $s): bool => (string) $s->source_key === $key);
                if ($slot === null) {
                    continue;
                }
                $condition = $this->orderQuantityOf($slot, $accounts);
                if ($condition !== null) {
                    $out[(int) $productId] = $condition;
                }
            }
        }

        return $out;
    }

    /**
     * Warunki zakupu jednego slotu w kształcie dla widoków (order_quantity): warunek zamawiania i warunek ceny
     * (price_note, price_carton_qty — Delta Plus: cena za pełny karton). null, gdy slot nie ogranicza zamówienia,
     * nie ma warunku zależnego od rozmiaru ani warunku ceny. Karta wyrobu podaje tu zwycięzcę explain().
     *
     * @param  Collection<int, B2bAccount>|null  $accounts  konta po id (lista); null = relacja account slotu
     * @return array{min: float|null, step: float|null, unit: string|null, varies: bool, price_note: string|null, price_carton_qty: float|null, source_key: string, source_label: string}|null
     */
    public function orderQuantityOf(ProductSourcePrice $slot, ?Collection $accounts = null): ?array
    {
        $varies = (bool) $slot->order_varies;
        $restricts = B2bOrderQuantity::restricting($slot->order_min_qty, $slot->order_step_qty);
        if (! $varies && ! $restricts && $slot->price_note === null) {
            return null;
        }

        return [
            // warunek zamawiania tylko, gdy ogranicza (albo zależy od rozmiaru) — sam warunek ceny go nie wnosi
            'min' => $varies || ! $restricts ? null : $slot->order_min_qty,
            'step' => $varies || ! $restricts ? null : $slot->order_step_qty,
            'unit' => $slot->order_unit,
            'varies' => $varies,
            'price_note' => $slot->price_note,
            'price_carton_qty' => $slot->price_carton_qty,
            'source_key' => (string) $slot->source_key,
            'source_label' => $this->sourceLabel($slot, $accounts),
        ];
    }

    /**
     * Sloty kart (w kolejności id, jak explain()) i wszystko, czego potrzebuje winnerKey(): konta, powiązania,
     * reguły „Producenci”, karty z aktywnymi wersjami.
     *
     * @param  list<int>  $productIds
     * @return array{0: Collection<int|string, Collection<int, ProductSourcePrice>>, 1: Collection<int, B2bAccount>, 2: array<int, array<int, string|null>>, 3: array<int, array<string, true>>, 4: array<int, int>}
     */
    private function batch(array $productIds): array
    {
        $slotsByProduct = ProductSourcePrice::query()
            ->with('priceList:id,manufacturer,version,suggested_prices')
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get([
                'id', 'product_id', 'source_key', 'b2b_account_id', 'price_list_id', 'catalog_price_net',
                'purchase_price', 'currency', 'pack_qty', 'order_min_qty', 'order_step_qty', 'order_unit', 'order_varies', 'price_note', 'price_carton_qty', 'checked_at',
            ])
            ->groupBy('product_id');
        $accountIds = $this->accountIds($slotsByProduct->flatten(1));
        $accounts = $accountIds === []
            ? collect()
            : B2bAccount::query()->whereIn('id', $accountIds)->get(['id', 'connector', 'sites'])->keyBy('id');
        $withVariants = array_flip(ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->whereNull('removed_at')
            ->distinct()
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());

        return [$slotsByProduct, $accounts, $this->linksByProduct($productIds), $this->rules->priceDisabled($accountIds), $withVariants];
    }

    /**
     * Etykieta źródła ceny — ta sama na karcie (source_label) i przy „taniej u …” na liście i w przetargu.
     * Konto bez loginu i hasła.
     *
     * @param  Collection<int, B2bAccount>|null  $accounts  konta po id (lista); null = relacja account slotu
     */
    public function sourceLabel(ProductSourcePrice $slot, ?Collection $accounts = null): string
    {
        if ($slot->source_key === ProductSourcePrice::SOURCE_FILE) {
            $list = $slot->priceList;
            $listLabel = $list !== null ? trim(trim((string) $list->manufacturer).' '.trim((string) $list->version)) : '';

            return $listLabel !== '' ? 'Cennik z pliku · '.$listLabel : 'Cennik z pliku';
        }
        if (! $slot->isB2b()) {
            return (string) $slot->source_key;
        }

        return $this->accountLabel($accounts !== null ? $accounts->get(self::accountIdOf($slot)) : $slot->account);
    }

    /**
     * Etykieta konta B2B wspólna dla cen ze źródeł i danych ze sklepu dostawcy — ta sama nazwa konta ma się
     * pokazywać w obu miejscach karty.
     */
    public function accountLabel(?B2bAccount $account): string
    {
        if ($account === null) {
            return 'B2B (usunięte konto)';
        }
        // b2b_accounts nie ma nazwy — bez łącznika pierwsza witryna konta (nigdy login ani notatka)
        $name = $this->connectors->label($account->connector)
            ?? (is_array($account->sites) && isset($account->sites[0]) ? trim((string) $account->sites[0]) : '');

        return $name !== '' ? 'B2B '.$name : 'B2B konto #'.$account->id;
    }

    /**
     * Kurs, którym liczono porównanie karty. Same ceny w PLN nie potrzebują kursu i nie pytają NBP (jak
     * appendPricePln) — wtedy as_of null i source „nbp”: kurs zastępczy nie został użyty, więc bez ostrzeżenia.
     *
     * @param  Collection<int, ProductSourcePrice>  $slots
     * @return array{as_of: string|null, source: string}
     */
    private function rates(Product $product, Collection $slots): array
    {
        $foreign = self::currencyCode($product->currency) !== 'PLN'
            || $slots->contains(fn (ProductSourcePrice $s): bool => self::currencyCode($s->currency ?? $product->currency) !== 'PLN');
        if (! $foreign) {
            return ['as_of' => null, 'source' => 'nbp'];
        }
        $snapshot = $this->fx->snapshot();

        return ['as_of' => $snapshot['as_of'], 'source' => $snapshot['source'] === 'nbp' ? 'nbp' : 'fallback'];
    }

    /**
     * Kurs waluty do PLN; null = waluty nie ma w tabeli (NBP albo zastępczej). PLN bez pytania NBP — lista
     * i karta w samych złotych nie wysyłają żądania do api.nbp.pl (dotychczasowe appendPricePln też nie).
     */
    private function rateFor(string $code): ?float
    {
        if ($code === 'PLN') {
            return 1.0;
        }

        return $this->fx->rates()[$code] ?? null;
    }

    /**
     * @param  iterable<ProductSourcePrice>  $slots
     * @param  array<int, string|null>  $links  id konta => producent z powiązania (klucz = konto ma powiązanie z kartą)
     * @param  array<int, array<string, true>>  $disabled  B2bManufacturerRules::priceDisabled
     * @param  array<string, string>  $explainReasons  powody z explain() — ten sam tekst przy wyłączonej cenie
     * @return array<string, array{purchase_price_pln: float|null, comparable: bool, not_comparable_reason: string|null, price_rank: int|null, is_cheapest: bool, diff_to_effective_pct: float|null}>
     */
    private function rows(Product $product, iterable $slots, ?string $effectiveKey, bool $hasVariants, array $links, array $disabled, array $explainReasons = []): array
    {
        $effectivePln = $this->toPlnKnown($product->purchase_price, $product->currency);

        $rows = [];
        $comparable = [];
        foreach ($slots as $slot) {
            $key = (string) $slot->source_key;
            // slot bez waluty ma walutę karty (ProductEffectivePrice::resolve)
            $currency = self::currencyCode($slot->currency ?? $product->currency);
            $pln = $this->toPlnKnown($slot->purchase_price, $currency);
            $reason = $this->notComparableReason($product, $slot, $pln, $currency, $effectivePln, $hasVariants, $links, $disabled, $explainReasons);
            $diff = null;
            if ($pln !== null && $effectivePln !== null) {
                $diff = round(($pln - $effectivePln) / $effectivePln * 100, 1);
                // -0.0 === 0.0 w PHP — podmiana na +0.0, żeby JSON nie pokazał „-0.0”
                $diff = $diff === 0.0 ? 0.0 : $diff;
            }
            $rows[$key] = [
                'purchase_price_pln' => $pln,
                'comparable' => $reason === null,
                'not_comparable_reason' => $reason,
                'price_rank' => null,
                'is_cheapest' => false,
                'diff_to_effective_pct' => $diff,
            ];
            if ($reason === null) {
                $comparable[$key] = (float) $pln;
            }
        }

        // remis ceny: obowiązujący pierwszy, potem po source_key — ten sam wynik na karcie i na liście
        $keys = array_map('strval', array_keys($comparable));
        usort($keys, static fn (string $a, string $b): int => [$comparable[$a], $a === $effectiveKey ? 0 : 1, $a]
            <=> [$comparable[$b], $b === $effectiveKey ? 0 : 1, $b]);
        foreach ($keys as $i => $key) {
            $rows[$key]['price_rank'] = $i + 1;
            $rows[$key]['is_cheapest'] = $i === 0;
        }

        return $rows;
    }

    /**
     * Powód, dla którego źródło nie wchodzi do porównania; null = porównywalne. Pierwszy pasujący wygrywa.
     *
     * @param  array<int, string|null>  $links
     * @param  array<int, array<string, true>>  $disabled
     * @param  array<string, string>  $explainReasons
     */
    private function notComparableReason(
        Product $product,
        ProductSourcePrice $slot,
        ?float $pln,
        string $currency,
        ?float $effectivePln,
        bool $hasVariants,
        array $links,
        array $disabled,
        array $explainReasons,
    ): ?string {
        if ($hasVariants) {
            return 'ceny w wersjach karty';
        }
        $isFile = (string) $slot->source_key === ProductSourcePrice::SOURCE_FILE;
        // ceny sugerowane (np. „ATG-sugerowany”: katalogowa = zakup 52,72 zł) nie są ceną, za którą kupujemy
        if ($isFile && (bool) ($slot->priceList?->suggested_prices ?? false)) {
            return 'cennik sugerowany';
        }
        if ((float) ($slot->purchase_price ?? 0) <= 0) {
            return 'brak ceny zakupu';
        }
        if ($this->rateFor($currency) === null) {
            return 'nieznana waluta '.$currency;
        }
        if ($slot->isB2b()) {
            $accountId = self::accountIdOf($slot);
            $manufacturer = (string) (($links[$accountId] ?? null) ?? $product->manufacturer);
            if (isset($disabled[$accountId][B2bManufacturerRules::key($manufacturer)])) {
                return $explainReasons[(string) $slot->source_key] ?? 'cena producenta '.$manufacturer.' wyłączona w tym cenniku';
            }
            if (! array_key_exists($accountId, $links)) {
                return 'dostawca nie ma już tej pozycji';
            }
            if ($slot->checked_at === null) {
                return 'cena niepotwierdzona (brak daty sprawdzenia)';
            }
            $days = (int) floor($slot->checked_at->diffInDays(Carbon::now(), true));
            if ($days >= self::STALE_DAYS) {
                return 'cena niepotwierdzona od '.$days.' dni';
            }
        }
        // pack_qty z cennika to liczba sztuk w kartonie, a cena w wierszu jest za sztukę albo parę (produkcja 23.09.2026:
        // Ansell 373 z 682 i Bolle 248 z 254 wierszy mają karton > 1) — sama ilość w opakowaniu nie wyklucza ceny.
        // Cenę za karton zamiast za sztukę łapie próg poniżej.
        if ($pln !== null && $effectivePln !== null && $pln * self::UNIT_RATIO < $effectivePln) {
            return 'sprawdź jednostkę (ponad 3× taniej)';
        }

        return null;
    }

    /**
     * @param  array<string, array{purchase_price_pln: float|null, comparable: bool, not_comparable_reason: string|null, price_rank: int|null, is_cheapest: bool, diff_to_effective_pct: float|null}>  $rows
     * @param  callable(string): string  $label
     * @return array{source_key: string, label: string, purchase_price_pln: float, diff_pct: float}|null
     */
    private function cheaperFromRows(array $rows, ?string $effectiveKey, callable $label): ?array
    {
        foreach ($rows as $key => $row) {
            $key = (string) $key;
            if (! $row['is_cheapest']) {
                continue;
            }
            if ($key === $effectiveKey || $row['diff_to_effective_pct'] === null || $row['diff_to_effective_pct'] > self::MIN_SAVING_PCT) {
                return null;
            }

            return [
                'source_key' => $key,
                'label' => $label($key),
                'purchase_price_pln' => (float) $row['purchase_price_pln'],
                'diff_pct' => (float) $row['diff_to_effective_pct'],
            ];
        }

        return null;
    }

    /**
     * Slot obowiązujący liczony na danych wczytanych hurtem — ta sama kolejność co ProductEffectivePrice::explain()
     * (konto B2B producenta najświeżej sprawdzone → plik producenta → dystrybutor najświeżej sprawdzony → inny plik;
     * pomijane: slot bez ceny, konto z wyłączoną ceną producenta). Test SourcePriceComparisonTest pilnuje zgodności
     * z explain(); zmiana reguł tam = zmiana tutaj.
     *
     * @param  Collection<int, ProductSourcePrice>  $slots  w kolejności id (jak zapytanie explain())
     * @param  Collection<int, B2bAccount>  $accounts
     * @param  array<int, string|null>  $links
     * @param  array<int, array<string, true>>  $disabled
     */
    private function winnerKey(Product $product, Collection $slots, Collection $accounts, array $links, array $disabled): ?string
    {
        $cardManufacturer = (string) $product->manufacturer;
        $own = [];
        $ownFile = null;
        $distributors = [];
        $otherFile = null;
        foreach ($slots as $slot) {
            $key = (string) $slot->source_key;
            if ((float) ($slot->purchase_price ?? 0) <= 0 && (float) ($slot->catalog_price_net ?? 0) <= 0) {
                continue;
            }
            if (! $slot->isB2b()) {
                if ($key !== ProductSourcePrice::SOURCE_FILE) {
                    continue;
                }
                $listManufacturer = (string) ($slot->priceList?->manufacturer ?? '');
                $suggested = (bool) ($slot->priceList?->suggested_prices ?? false);
                if (! $suggested && $listManufacturer !== '' && BrandKey::same($listManufacturer, $cardManufacturer)) {
                    $ownFile = $slot;
                } else {
                    $otherFile = $slot;
                }

                continue;
            }
            $accountId = self::accountIdOf($slot);
            $manufacturer = (string) (($links[$accountId] ?? null) ?? $cardManufacturer);
            if (isset($disabled[$accountId][B2bManufacturerRules::key($manufacturer)])) {
                continue;
            }
            $account = $accounts->get($accountId);
            $brands = $account !== null ? $this->connectors->brandsForKey($this->connectors->keyForAccount($account)) : [];
            $isOwn = false;
            foreach ($brands as $brand) {
                $isOwn = $isOwn || BrandKey::same($brand, $manufacturer);
            }
            if ($isOwn) {
                $own[] = $slot;
            } else {
                $distributors[] = $slot;
            }
        }

        $freshest = static fn (array $list): ?ProductSourcePrice => collect($list)
            ->sortByDesc(static fn (ProductSourcePrice $s): int => $s->checked_at?->getTimestamp() ?? 0)
            ->first();
        $winner = $freshest($own) ?? $ownFile ?? $freshest($distributors) ?? $otherFile;

        return $winner?->source_key;
    }

    /**
     * Powiązania kart z kontami: id karty => [id konta => producent w brzmieniu konta albo null]. Producent jak
     * w explain(): ostatnie niepuste brzmienie, inaczej null (= producent karty).
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, string|null>>
     */
    private function linksByProduct(array $productIds): array
    {
        $out = [];
        $rows = B2bProductLink::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get(['product_id', 'b2b_account_id', 'manufacturer']);
        foreach ($rows as $link) {
            $productId = (int) $link->product_id;
            $accountId = (int) $link->b2b_account_id;
            $manufacturer = $link->manufacturer !== null ? (string) $link->manufacturer : null;
            $out[$productId][$accountId] = $manufacturer ?? ($out[$productId][$accountId] ?? null);
        }

        return $out;
    }

    /**
     * @param  Collection<int, ProductSourcePrice>  $slots
     * @return list<int>
     */
    private function accountIds(Collection $slots): array
    {
        return $slots->filter(static fn (ProductSourcePrice $s): bool => $s->isB2b())
            ->map(static fn (ProductSourcePrice $s): int => self::accountIdOf($s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Kwota w PLN, gdy dodatnia i waluta jest w tabeli kursów; nieznana waluta = null (toPln liczyłby ją kursem 1,0).
     */
    private function toPlnKnown(mixed $amount, mixed $currency): ?float
    {
        $value = (float) ($amount ?? 0);
        $code = self::currencyCode($currency);
        if ($value <= 0 || $this->rateFor($code) === null) {
            return null;
        }

        return $this->fx->toPln($value, $code);
    }

    private static function currencyCode(mixed $currency): string
    {
        $code = strtoupper(trim((string) $currency));

        return $code !== '' ? $code : 'PLN';
    }

    private static function accountIdOf(ProductSourcePrice $slot): int
    {
        return (int) ($slot->b2b_account_id ?? (int) substr((string) $slot->source_key, 4));
    }
}
