<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ManufacturerPart;
use App\Support\ProductSizeVariant;

/**
 * Porównanie pozycji pliku cennika z kartami tego cennika — czysta logika (bez bazy), wspólna dla
 * products:repair-price-list-codes (plan naprawy kart) i products:price-list-file-audit (klasy kart wobec żetonów pliku).
 *
 * Tło: import cennika Coba z 12.09.2026 uciął 167 kodów regułą „rozmiar na końcu kodu” (AF010005 → AF0100,
 * DP010004 → DP0100-4), zlał pozycje o tej samej cenie w jedną kartę i dał części kart nazwę z nagłówka grupy albo
 * z sąsiedniego wiersza (plan SUPON_AI_Coba_tabela_czesci/PLAN_naprawa_importu_2026-10-10.md, część A i C).
 *
 * Dopasowanie (każdy wiersz pliku raz, każda karta raz — poza kartą z mapy połączeń):
 *  1) mapa połączeń (card_redirects file:{cennik}) — karta bez zmiany kodu i nazwy;
 *  2) kod dokładny (ManufacturerPart::codeKey) — kod karty zostaje, nazwa jak niżej; ten sam kod innym zapisem
 *     (DP0100-11 przy DP010011 — dawna reguła wstawiała myślnik) dostaje kod z pliku;
 *  3) kod ucięty dawną regułą (ProductSizeVariant::legacyCutCodes: rdzeń albo rdzeń-rozmiar) z tą samą ceną z pliku —
 *     karta dostaje kod wiersza; karta zebrana z kilku wierszy idzie do wiersza o tej samej nazwie (także po zdjęciu
 *     rozmiaru), a bez takiego — do pierwszego wiersza w kolejności pliku; reszta wierszy dostaje nowe karty;
 *     3b) karta bez wiersza, której kod jest początkiem kodu dokładnie jednego wolnego wiersza o tej samej nazwie
 *     i cenie (starsza reguła: VP01 ← VP010604);
 *  4) reszta wierszy — nowe karty, chyba że kod zajęty przez inną kartę (kolizja), wiersz zablokowany „Usuń i pomijaj
 *     przy imporcie” albo kandydat z uciętym kodem nie dał się rozstrzygnąć (niejednoznaczne);
 *  5) karty bez wiersza — tylko w raporcie.
 * Nazwa: tylko gdy obecna nazwa karty pochodzi z pliku (nazwa któregoś wiersza albo nagłówka grupy, także po zdjęciu
 * rozmiaru); inaczej zostaje („nazwa nie z pliku”). Niczego nie zgadujemy i niczego nie usuwamy.
 */
final class PriceListFileReconciler
{
    public const ACTION_UPDATE = 'update';

    public const ACTION_KEEP = 'keep';

    public const ACTION_NEW = 'new';

    public const ACTION_COLLISION = 'collision';

    public const ACTION_AMBIGUOUS = 'ambiguous';

    public const ACTION_BLOCKED = 'blocked';

    public const ACTION_NO_ROW = 'no_row';

    public const MATCH_REDIRECT = 'redirect';

    public const MATCH_EXACT = 'exact';

    public const MATCH_CUT = 'cut';

    public const CLASS_IN_FILE = 'in_file';

    public const CLASS_SUSPECTED = 'suspected_cut';

    public const CLASS_MISSING = 'missing';

    /** Najmniejszy udział kart z dokładnym kodem, których cena z pliku zgadza się z wierszem (bezpiecznik mapowania). */
    public const MIN_PRICE_AGREEMENT = 0.95;

    public function __construct(
        private readonly ProductSizeVariant $sizes = new ProductSizeVariant,
    ) {}

    public static function codeKey(string $code): string
    {
        return ManufacturerPart::codeKey($code);
    }

    /**
     * @param  list<array{sku: string, name: string, payload: array<string, mixed>, identifiers: list<array<string, mixed>>, sheet: ?string, row: ?int}>  $rows
     *                                                                                                                                                           pozycje pliku (PriceListImportService::fileRowsForRepair), w kolejności pliku
     * @param  array<int, array{id: int, sku: string, name: string, price: string, list_card: bool}>  $cards
     *                                                                                                        karty tego cennika (list_card) i karty docelowe mapy połączeń; price = koszyk ceny slotu pliku (ProductSizeVariant::priceBucket)
     * @param  list<string>  $fileNames  nazwy z pliku (fileRowsForRepair file_names)
     * @param  array<string, int|null>  $redirects  CardRedirectStore::key(file:{cennik}, pozycja) => id karty (null = karta usunięta)
     * @param  array<string, int>  $takenSkus  kod małymi literami => id karty, która go ma (kody wierszy zajęte w bazie)
     * @return array{
     *     entries: list<array{action: string, match: ?string, card_id: ?int, row: ?array<string, mixed>, new_sku: ?string, new_name: ?string, notes: list<string>, candidates: list<int>}>,
     *     price_check: array{exact: int, agree: int, ratio: float}
     * }
     */
    public function plan(
        array $rows,
        array $cards,
        array $fileNames,
        string $redirectSourceKey,
        array $redirects,
        ImportExclusionSet $exclusions,
        array $takenSkus,
    ): array {
        $names = [];
        foreach ($fileNames as $name) {
            $names[$this->norm($name)] = true;
        }

        /** @var array<string, list<int>> $listByKey codeKey kodu karty cennika => id kart */
        $listByKey = [];
        /** @var array<string, list<int>> $listBySku kod karty cennika małymi literami => id kart */
        $listBySku = [];
        foreach ($cards as $id => $card) {
            if (! $card['list_card']) {
                continue;
            }
            $listByKey[self::codeKey($card['sku'])][] = $id;
            $listBySku[mb_strtolower(trim($card['sku']))][] = $id;
        }

        /** @var array<int, string> $rowPrices indeks wiersza => koszyk ceny */
        $rowPrices = array_map(fn (array $row): string => $this->rowPrice($row), $rows);

        /** @var array<int, array<string, mixed>> $entries indeks wiersza => wpis */
        $entries = [];
        /** @var array<int, int> $cardOwner id karty => indeks wiersza (karta przydzielona) */
        $cardOwner = [];
        $exact = 0;
        $agree = 0;

        // 0) blokady i 1) mapa połączeń
        foreach ($rows as $i => $row) {
            $blocked = $this->blockReason($row, $exclusions);
            if ($blocked !== null) {
                $entries[$i] = $this->entry(self::ACTION_BLOCKED, null, null, $row, notes: [$blocked]);

                continue;
            }
            $targets = [];
            foreach ($this->positions($row) as $position) {
                $target = $redirects[CardRedirectStore::key($redirectSourceKey, $position)] ?? null;
                // wpis z usuniętą kartą (decyzja bez karty) — pozycja idzie zwykłą drogą, jak przy imporcie
                if ($target !== null && isset($cards[$target])) {
                    $targets[$target] = true;
                }
            }
            if (count($targets) > 1) {
                $ids = array_keys($targets);
                sort($ids);
                $entries[$i] = $this->entry(self::ACTION_AMBIGUOUS, self::MATCH_REDIRECT, null, $row,
                    notes: ['pozycje wiersza połączone z różnymi kartami (#'.implode(', #', $ids).') — sprawdź w Łączenie kart'],
                    candidates: $ids);

                continue;
            }
            if (count($targets) === 1) {
                $cardId = (int) array_key_first($targets);
                $entries[$i] = $this->entry(self::ACTION_KEEP, self::MATCH_REDIRECT, $cardId, $row,
                    notes: ['mapa połączeń — kod i nazwa karty bez zmian']);
                // karta z mapy zbiera wiersze z mapy (kilka połączonych rozmiarów), a innych wierszy przez kod nie dostaje
                $cardOwner[$cardId] ??= $i;
            }
        }

        // 2) kod dokładny
        foreach ($rows as $i => $row) {
            if (isset($entries[$i])) {
                continue;
            }
            $ids = array_values(array_filter(
                $listByKey[self::codeKey($row['sku'])] ?? [],
                static fn (int $id): bool => ! isset($cardOwner[$id]),
            ));
            if ($ids === []) {
                continue;
            }
            if (count($ids) > 1) {
                $literal = array_values(array_filter($ids, static fn (int $id): bool => mb_strtolower(trim($cards[$id]['sku'])) === mb_strtolower(trim($row['sku']))));
                if (count($literal) !== 1) {
                    $entries[$i] = $this->entry(self::ACTION_AMBIGUOUS, self::MATCH_EXACT, null, $row,
                        notes: ['kilka kart z tym samym kodem (#'.implode(', #', $ids).')'], candidates: $ids);

                    continue;
                }
                $ids = $literal;
            }
            $cardId = $ids[0];
            $cardOwner[$cardId] = $i;
            $exact++;
            $notes = [];
            if ($cards[$cardId]['price'] === $rowPrices[$i]) {
                $agree++;
            } else {
                $notes[] = 'cena karty z pliku '.$cards[$cardId]['price'].' ≠ wiersz '.$rowPrices[$i];
            }
            // ten sam kod bez separatorów w zapisie dawnej reguły (rdzeń-rozmiar: DP010011 → DP0100-11, MCLIP38 →
            // MCLIP-38) — karta dostaje kod z pliku; inny zapis bez tego dowodu zostaje (kod dokładny)
            $cardSku = mb_strtolower(trim($cards[$cardId]['sku']));
            if ($cardSku !== mb_strtolower(trim($row['sku'])) && in_array($cardSku, array_map('mb_strtolower', $this->legacyCodesOfRow($row)), true)) {
                $notes[] = 'kod karty różni się od pliku tylko separatorem (zapis dawnej reguły)';
                $entries[$i] = $this->matched(self::MATCH_CUT, $cards[$cardId], $row, $row['sku'], $names, $notes, $takenSkus);

                continue;
            }
            $entries[$i] = $this->matched(self::MATCH_EXACT, $cards[$cardId], $row, null, $names, $notes);
        }

        // 3) kod ucięty dawną regułą — kandydaci każdego wiersza, potem przydział kart
        /** @var array<int, list<int>> $candidatesOf indeks wiersza => karty z dawnym kodem wiersza */
        $candidatesOf = [];
        /** @var array<int, list<int>> $claims id karty => wiersze z tą samą ceną, które ją wskazują */
        $claims = [];
        foreach ($rows as $i => $row) {
            if (isset($entries[$i])) {
                continue;
            }
            $ids = [];
            foreach ($this->legacyCodesOfRow($row) as $code) {
                foreach ($listBySku[mb_strtolower($code)] ?? [] as $id) {
                    if (! isset($cardOwner[$id])) {
                        $ids[$id] = true;
                    }
                }
            }
            $candidatesOf[$i] = array_map('intval', array_keys($ids));
            $samePrice = array_values(array_filter(
                $candidatesOf[$i],
                static fn (int $id): bool => $cards[$id]['price'] === $rowPrices[$i],
            ));
            if (count($samePrice) > 1) {
                $byName = array_values(array_filter($samePrice, fn (int $id): bool => $this->sameName($row['name'], $cards[$id]['name'])));
                if (count($byName) !== 1) {
                    $entries[$i] = $this->entry(self::ACTION_AMBIGUOUS, self::MATCH_CUT, null, $row,
                        notes: ['kilka kart z dawnym kodem wiersza i tą samą ceną (#'.implode(', #', $samePrice).')'],
                        candidates: $samePrice);

                    continue;
                }
                $samePrice = $byName;
            }
            if ($samePrice !== []) {
                $claims[$samePrice[0]][] = $i;
            }
        }
        foreach ($claims as $cardId => $claimants) {
            $winner = null;
            if (count($claimants) > 1) {
                $byName = array_values(array_filter($claimants, fn (int $i): bool => $this->sameName($rows[$i]['name'], $cards[$cardId]['name'])));
                if (count($byName) === 1) {
                    $winner = $byName[0];
                }
            }
            // jedyny wiersz albo pierwszy wiersz grupy (kolejność pliku)
            $winner ??= min($claimants);
            $cardOwner[$cardId] = $winner;
            $notes = count($claimants) > 1
                ? ['karta zebrana z '.count($claimants).' wierszy pliku ('.implode(', ', array_map(static fn (int $i): string => $rows[$i]['sku'], $claimants)).') — zostaje przy '.$rows[$winner]['sku'].', pozostałe dostają nowe karty']
                : [];
            $entries[$winner] = $this->matched(self::MATCH_CUT, $cards[$cardId], $rows[$winner], $rows[$winner]['sku'], $names, $notes, $takenSkus);
        }

        // 3b) karta, której kod jest początkiem kodu wiersza, z tą samą ceną i nazwą — kod ucięty regułą starszą niż
        // obecna dawna reguła (Coba VP01 ← VP010604 „Vyna-Plush Czarny/Stalowy 0.9m x 1.2m”). Tylko jeden taki wiersz.
        /** @var array<string, list<int>> $openByPrice koszyk ceny => wolne wiersze */
        $openByPrice = [];
        foreach ($rows as $i => $row) {
            if (! isset($entries[$i])) {
                $openByPrice[$rowPrices[$i]][] = $i;
            }
        }
        foreach ($cards as $cardId => $card) {
            $cardKey = self::codeKey($card['sku']);
            if (! $card['list_card'] || isset($cardOwner[$cardId]) || mb_strlen($cardKey) < 3) {
                continue;
            }
            $hits = [];
            foreach ($openByPrice[$card['price']] ?? [] as $i) {
                $rowKey = self::codeKey($rows[$i]['sku']);
                if (! isset($entries[$i]) && $rowKey !== $cardKey && str_starts_with($rowKey, $cardKey)
                    && $this->sameName($rows[$i]['name'], $card['name'])) {
                    $hits[] = $i;
                }
            }
            if (count($hits) !== 1) {
                continue;
            }
            $cardOwner[$cardId] = $hits[0];
            $entries[$hits[0]] = $this->matched(self::MATCH_CUT, $card, $rows[$hits[0]], $rows[$hits[0]]['sku'], $names,
                ['kod karty to początek kodu wiersza (ta sama nazwa i cena)'], $takenSkus);
        }

        // 4) reszta wierszy
        $newSkus = [];
        foreach ($rows as $i => $row) {
            if (isset($entries[$i])) {
                continue;
            }
            $open = array_values(array_filter($candidatesOf[$i] ?? [], static fn (int $id): bool => ! isset($cardOwner[$id])));
            if ($open !== []) {
                $entries[$i] = $this->entry(self::ACTION_AMBIGUOUS, self::MATCH_CUT, null, $row,
                    notes: ['karta z dawnym kodem wiersza ma inną cenę z pliku (#'.implode(', #', $open).') — bez nowej karty'],
                    candidates: $open);

                continue;
            }
            $owner = $takenSkus[mb_strtolower(trim($row['sku']))] ?? null;
            if ($owner !== null) {
                $entries[$i] = $this->entry(self::ACTION_COLLISION, null, null, $row,
                    notes: ['kod '.$row['sku'].' ma już karta #'.$owner.' (poza tym cennikiem) — bez nowej karty']);

                continue;
            }
            $entries[$i] = $this->entry(self::ACTION_NEW, null, null, $row, $row['sku'], $row['name']);
            $newSkus[mb_strtolower(trim($row['sku']))][] = $i;
        }
        // dwie nowe karty z tym samym kodem (np. ten sam kod różnie zapisany) — żadnej, do sprawdzenia
        foreach ($newSkus as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $i) {
                    $entries[$i] = $this->entry(self::ACTION_AMBIGUOUS, null, null, $rows[$i], notes: ['ten sam kod w kilku wierszach pliku']);
                }
            }
        }

        ksort($entries);
        $out = array_values($entries);
        // 5) karty cennika bez wiersza
        foreach ($cards as $id => $card) {
            if ($card['list_card'] && ! isset($cardOwner[$id])) {
                $out[] = $this->entry(self::ACTION_NO_ROW, null, $id, null, notes: ['karta bez wiersza w pliku — bez zmian']);
            }
        }

        return [
            'entries' => $out,
            'price_check' => ['exact' => $exact, 'agree' => $agree, 'ratio' => $exact > 0 ? $agree / $exact : 0.0],
        ];
    }

    /**
     * Klasy kart wobec żetonów pliku (products:price-list-file-audit):
     *  - kod w pliku: kod karty dosłownie (bez wielkości liter) w pliku albo ten sam kod innym zapisem, który nie jest
     *    dawnym kodem uciętym;
     *  - podejrzenie ucięcia: kod karty jest dawnym kodem uciętym kodu z pliku (rdzeń albo rdzeń-rozmiar:
     *    ProductSizeVariant::legacyCutCodes — AF0100 ← AF010005, DP0100-11 ← DP010011), także gdy sam rdzeń kodu
     *    „X-N” jest takim kodem;
     *  - brak.
     *
     * @param  list<array{id: int|string, sku: string, name?: string}>  $cards
     * @param  array{codes: array<string, string>, keys: array<string, true>, literals: array<string, true>}  $file  tokensFromCells
     * @return array{
     *     cards: list<array{id: int|string, sku: string, name: string, class: string, evidence: list<string>}>,
     *     counts: array<string, int>,
     *     tokens_sharing_core_without_card: int,
     *     token_examples: list<string>
     * }
     */
    public function auditCards(array $cards, array $file): array
    {
        /** @var array<string, list<string>> $byLegacy dawny kod małymi literami => kody z pliku */
        $byLegacy = [];
        /** @var array<string, list<string>> $byLegacyKey codeKey dawnego kodu => kody z pliku */
        $byLegacyKey = [];
        foreach ($file['codes'] as $token) {
            foreach ($this->sizes->legacyCutCodes($token) as $code) {
                $byLegacy[mb_strtolower($code)][] = $token;
                $byLegacyKey[self::codeKey($code)][] = $token;
            }
        }
        $cardKeys = [];
        foreach ($cards as $card) {
            $cardKeys[self::codeKey((string) $card['sku'])] = true;
        }

        $out = [];
        $counts = [self::CLASS_IN_FILE => 0, self::CLASS_SUSPECTED => 0, self::CLASS_MISSING => 0];
        /** @var array<string, true> $evidenceTokens kody z pliku, które wskazały podejrzaną kartę */
        $evidenceTokens = [];
        foreach ($cards as $card) {
            $sku = trim((string) $card['sku']);
            $lower = mb_strtolower($sku);
            $key = self::codeKey($sku);
            $evidence = [];
            if ($lower !== '' && isset($file['literals'][$lower])) {
                $class = self::CLASS_IN_FILE;
            } elseif (isset($byLegacy[$lower])) {
                $class = self::CLASS_SUSPECTED;
                $evidence = $byLegacy[$lower];
            } elseif ($key !== '' && isset($file['keys'][$key])) {
                $class = self::CLASS_IN_FILE;
            } else {
                $lookups = [$key];
                // „DP0100-4” — kod modelu z doklejonym rozmiarem po myślniku
                if (preg_match('/^(.+?)-([A-Z0-9]{1,4})$/iu', $sku, $m) === 1) {
                    $lookups[] = self::codeKey($m[1]);
                }
                foreach (array_unique($lookups) as $lookup) {
                    if ($lookup !== '' && isset($byLegacyKey[$lookup])) {
                        $evidence = [...$evidence, ...$byLegacyKey[$lookup]];
                    }
                }
                $class = $evidence !== [] ? self::CLASS_SUSPECTED : self::CLASS_MISSING;
            }
            $evidence = array_values(array_unique($evidence));
            foreach ($evidence as $token) {
                $evidenceTokens[$token] = true;
            }
            $counts[$class]++;
            $out[] = [
                'id' => $card['id'],
                'sku' => $sku,
                'name' => (string) ($card['name'] ?? ''),
                'class' => $class,
                'evidence' => array_slice($evidence, 0, 12),
            ];
        }

        // kody z pliku, które wskazały podejrzaną kartę, a własnej karty nie mają — pozycje zlane albo ucięte
        $withoutCard = array_values(array_filter(
            array_map('strval', array_keys($evidenceTokens)),
            static fn (string $token): bool => ! isset($cardKeys[self::codeKey($token)]),
        ));

        return [
            'cards' => $out,
            'counts' => $counts,
            'tokens_sharing_core_without_card' => count($withoutCard),
            'token_examples' => array_slice($withoutCard, 0, 20),
        ];
    }

    /**
     * Żetony kodów z tekstu komórek: cała komórka i słowa (3–40 znaków). codes — kandydaci na kod (z cyfrą),
     * keys — codeKey komórek i kodów, literals — komórki i słowa małymi literami (kody literowe: „LAN”, „YO YO METAL”).
     *
     * @param  iterable<string>  $cells
     * @return array{codes: array<string, string>, keys: array<string, true>, literals: array<string, true>}
     */
    public function tokensFromCells(iterable $cells): array
    {
        $codes = [];
        $keys = [];
        $literals = [];
        foreach ($cells as $cell) {
            $cell = trim(preg_replace('/\s+/u', ' ', (string) $cell) ?? '');
            if ($cell === '') {
                continue;
            }
            $length = mb_strlen($cell);
            if ($length >= 3 && $length <= 40) {
                $literals[mb_strtolower($cell)] = true;
                $key = self::codeKey($cell);
                if ($key !== '') {
                    $keys[$key] = true;
                }
                if (preg_match('/\d/u', $cell) === 1 && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}.\/\-_ ]*$/u', $cell) === 1 && substr_count($cell, ' ') <= 2) {
                    $codes[mb_strtolower($cell)] ??= $cell;
                }
            }
            foreach (preg_split('/[\s,;:()\[\]{}"\'„”|]+/u', $cell) ?: [] as $word) {
                $word = trim($word, ".-/_\t ");
                $length = mb_strlen($word);
                if ($length < 3 || $length > 40) {
                    continue;
                }
                $literals[mb_strtolower($word)] = true;
                if (preg_match('/\d/u', $word) !== 1) {
                    continue;
                }
                $codes[mb_strtolower($word)] ??= $word;
                $key = self::codeKey($word);
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return ['codes' => $codes, 'keys' => $keys, 'literals' => $literals];
    }

    /**
     * Dawne kody wiersza (kod wiersza i kody jego pozycji) — tak, jak zapisałaby je reguła sprzed 10.10.2026.
     *
     * @param  array{sku: string, name: string, payload: array<string, mixed>, identifiers: list<array<string, mixed>>}  $row
     * @return list<string>
     */
    public function legacyCodesOfRow(array $row): array
    {
        $packaging = isset($row['payload']['packaging']) ? (string) $row['payload']['packaging'] : null;
        $codes = [];
        foreach (array_unique([$row['sku'], ...$this->positions($row)]) as $code) {
            foreach ($this->sizes->legacyCutCodes($code, $row['name'], $packaging) as $legacy) {
                $codes[mb_strtolower($legacy)] = $legacy;
            }
        }

        return array_values($codes);
    }

    /**
     * @param  array{sku: string, identifiers: list<array<string, mixed>>}  $row
     * @return list<string>
     */
    private function positions(array $row): array
    {
        $out = [];
        foreach ($row['identifiers'] as $identifier) {
            $position = mb_substr(trim((string) ($identifier['position'] ?? '')), 0, 64);
            if ($position !== '' && ! in_array($position, $out, true)) {
                $out[] = $position;
            }
        }

        return $out !== [] ? $out : [mb_substr(trim($row['sku']), 0, 64)];
    }

    /**
     * Wiersz zablokowany „Usuń i pomijaj przy imporcie”: pozycja, kod wiersza albo dawny (ucięty) kod wiersza —
     * karta o takim kodzie została usunięta z pominięciem. Częściowa blokada pozycji też wstrzymuje wiersz.
     *
     * @param  array{sku: string, name: string, payload: array<string, mixed>, identifiers: list<array<string, mixed>>}  $row
     */
    private function blockReason(array $row, ImportExclusionSet $exclusions): ?string
    {
        if ($exclusions->isEmpty()) {
            return null;
        }
        foreach ($this->positions($row) as $position) {
            if ($exclusions->position($position) !== null) {
                return 'pozycja '.$position.' zablokowana („Usuń i pomijaj przy imporcie”)';
            }
        }
        foreach (array_unique([$row['sku'], ...$this->positions($row), ...$this->legacyCodesOfRow($row)]) as $code) {
            if ($exclusions->sku($code) !== null) {
                return 'kod '.$code.' usuniętej karty zablokowany („Usuń i pomijaj przy imporcie”)';
            }
        }

        return null;
    }

    /**
     * Wpis karty dopasowanej do wiersza: nowy kod (tylko kod ucięty) i nowa nazwa (tylko nazwa z pliku).
     *
     * @param  array{id: int, sku: string, name: string, price: string, list_card: bool}  $card
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $fileNames  znormalizowane nazwy z pliku
     * @param  list<string>  $notes
     * @param  array<string, int>  $takenSkus
     * @return array<string, mixed>
     */
    private function matched(string $match, array $card, array $row, ?string $newSku, array $fileNames, array $notes, array $takenSkus = []): array
    {
        if ($newSku !== null && trim($newSku) === trim($card['sku'])) {
            $newSku = null;
        }
        if ($newSku !== null) {
            $owner = $takenSkus[mb_strtolower(trim($newSku))] ?? null;
            if ($owner !== null && $owner !== $card['id']) {
                $notes[] = 'kod '.$newSku.' ma już karta #'.$owner.' — kod zostaje';
                $newSku = null;
            }
        }
        $newName = null;
        $rowName = trim((string) $row['name']);
        if ($rowName !== '' && $this->norm($rowName) !== $this->norm($card['name'])) {
            if ($this->nameFromFile($card['name'], $fileNames)) {
                $newName = $rowName;
            } else {
                $notes[] = 'nazwa nie z pliku — zostaje';
            }
        }
        $action = $newSku !== null || $newName !== null ? self::ACTION_UPDATE : self::ACTION_KEEP;

        return $this->entry($action, $match, $card['id'], $row, $newSku, $newName, $notes);
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @param  list<string>  $notes
     * @param  list<int>  $candidates
     * @return array{action: string, match: ?string, card_id: ?int, row: ?array<string, mixed>, new_sku: ?string, new_name: ?string, notes: list<string>, candidates: list<int>}
     */
    private function entry(
        string $action,
        ?string $match,
        ?int $cardId,
        ?array $row,
        ?string $newSku = null,
        ?string $newName = null,
        array $notes = [],
        array $candidates = [],
    ): array {
        return [
            'action' => $action,
            'match' => $match,
            'card_id' => $cardId,
            'row' => $row,
            'new_sku' => $newSku,
            'new_name' => $newName,
            'notes' => $notes,
            'candidates' => $candidates,
        ];
    }

    /** @param  array{payload: array<string, mixed>}  $row */
    public function rowPrice(array $row): string
    {
        return $this->sizes->priceBucket(
            $row['payload']['catalog_price_net'] ?? null,
            $row['payload']['purchase_price'] ?? null,
        );
    }

    private function sameName(string $rowName, string $cardName): bool
    {
        $card = $this->norm($cardName);
        $cardStripped = $this->norm($this->sizes->stripSizeFromName($cardName));
        foreach ([$rowName, $this->sizes->stripSizeFromName($rowName)] as $variant) {
            $variant = $this->norm($variant);
            if ($variant !== '' && ($variant === $card || $variant === $cardStripped)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, true>  $fileNames */
    private function nameFromFile(string $name, array $fileNames): bool
    {
        foreach ([$name, $this->sizes->stripSizeFromName($name), $this->sizes->stripSizeLabelFromName($name)] as $variant) {
            $variant = $this->norm($variant);
            if ($variant !== '' && isset($fileNames[$variant])) {
                return true;
            }
        }

        return false;
    }

    private function norm(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
