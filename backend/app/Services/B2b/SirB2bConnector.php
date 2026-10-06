<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * b2b.sirsafety.com — sklep B2B producenta SIR Safety System (Włochy): odzież robocza, obuwie, rękawice, ochrona dróg
 * oddechowych, oczu, słuchu, upadku. Sprawdzone na zalogowanym koncie 01.10.2026 (SirB2bClient opisuje logowanie
 * i nagłówki): 1168 wyrobów, ceny w EUR (waluta wpisana na stałe w sklepie), język angielski — polskiego sklep nie ma.
 *
 * Lista = wyszukiwanie zaawansowane bez filtrów (tylko wyroby w sprzedaży, jak w sklepie). Karta = wyrób (SATNR, np.
 * „MA1113”) ze wszystkimi kolorami i rozmiarami (decyzja użytkownika 28.09.2026: rozmiary i kolory jednego wyrobu to
 * jedna karta). Pozycja = wariant SAP kolor × rozmiar („MA1113B010”) z product/product: EAN, rozmiar, minimum
 * zamówienia (AUMNG) i opakowanie (PACK_QTY). Cena jak w koszyku sklepu (prepareOrderRow): pozycja „nierabatowalna”
 * (PRAT6 „X”) — cena cennikowa KBETR, pozostałe — cena konta B2B_KBETR, a bez niej KBETR; cena promocyjna
 * (B2B_PM_KBETR) tylko w podsumowaniu, na karcie cena konta bez promocji (jak Safety Jogger). Cena cennikowa = KBETR.
 * Koszyk B2B nie przyjmuje wariantów z czasem dostawy (PLIFZ > 0), nieprowadzonych (FSH_MG_AT1/AT2 „N”), wycofanych
 * (MSTAE „Z2”) i bez ceny — takie pozycje są poza kartą (lista w podsumowaniu). Pozycje „do wyczerpania zapasu” (MSTAE
 * „Z1”, w sklepie „in esaurimento”: kolor wycofywany z zamiennikiem, często wyprzedaż po cenie cennikowej bez rabatu —
 * spodnie SYMBOL MC1111 w kolorze B4 po 5 EUR przy 10–11,20 EUR pozostałych) są na karcie tylko wtedy, gdy wyrób nie ma
 * innych — jak kolory Outlet Safety Jogger; inaczej najniższa cena karty byłaby ceną resztek. Ilość w koszyku: co
 * najmniej AUMNG i wielokrotność PACK_QTY = warunek zamawiania.
 *
 * Dostępność pozycji: product/availability koloru — ilość na dziś („Na stanie: N”), inaczej pierwszy tydzień z ilością
 * („Dostępne od dd.mm.rrrr”), inaczej „Brak na stanie”.
 *
 * Teksty po angielsku (B2bForeignLanguageSource): opis = długi opis, krótki opis (lista parametrów) i uwagi sklepu
 * (zastosowanie, przechowywanie) dosłownie; nazwa i opis tłumaczone po zapisie. Nazwa także karty już w katalogu,
 * dopóki jest nazwą ze źródła (B2bKeepsExistingNames, 05.10.2026) — tłumaczenie nazwy odrzucone przy pierwszym
 * przebiegu (839 z 1079 kart 01.10.2026) inaczej zostawało po angielsku na zawsze. Normy z poziomami
 * z product/productNorms jako wiersze „Norma” tabelki (B2bShopFieldNormSource). Pliki: angielska karta techniczna
 * PDF (polska istnieje, ale część kart ma w niej treść po włosku) i polska deklaracja zgodności — oba generowane przez
 * sklep. Zdjęcie koloru (albo wyrobu bez kolorów) z GetImage.ashx; obrazek zastępczy sklepu pomijany.
 *
 * remote_id pozycji = kod wariantu SAP (MATNR), SKU karty = kod wyrobu (SATNR), producent = SIR Safety System
 * (deklaracje zgodności wystawia SIR Safety System S.p.A.; sklep nie podaje innej marki). Dawne kody SIR (BISMT,
 * „30844,30844A”) — identyfikatory „poprzedni numer”.
 */
final class SirB2bConnector implements B2bConnector, B2bDocumentSource, B2bForeignLanguageSource, B2bGroupsSizes, B2bImageGallery, B2bKeepsExistingNames, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'SIR Safety System';

    public const CURRENCY = 'EUR';

    private const PAGE_SIZE = 500;

    private const LIST_BUDGET_SECONDS = 10 * 60;

    private const MAX_LIST_PAGES = 50;

    private const PROGRESS_EVERY = 50;

    private const INCONSISTENT = 7302;

    /** Tyle wyrobów bez ceny konta, zanim pojawi się pierwsza cena = sesja bez cen (albo zmiana sklepu). */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    /** Wiersz normy w tabelce (normShopFieldNames). */
    private const NORM_ROW = 'Norma';

    /** Normy, których poziomy zapisuje się jednym ciągłym kodem w kolejności normy (normRow). */
    private const CODE_NORMS = ['EN 388', 'EN 407', 'EN 511'];

    private const SECTION_MAIN = 'Informacje ze sklepu SIR';

    private const SECTION_NORMS = 'Normy';

    /** Karta techniczna po angielsku, deklaracja zgodności po polsku (IDLingua z common/languages sklepu). */
    private const DATASHEET_LANGUAGE = ['id' => 2, 'code' => 'EN'];

    private const DECLARATION_LANGUAGE = ['id' => 60, 'code' => 'PL'];

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    private int $multiPrice = 0;

    /** @var array{classes: array<string, string>, groups: array<string, string>} */
    private array $dictionary = ['classes' => [], 'groups' => []];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> pozycje, których koszyk nie przyjmuje („MC1111B438 (czas dostawy 30 dni)”) */
    private array $unorderable = [];

    /** @var list<string> */
    private array $promotions = [];

    /** @var list<string> */
    private array $notDiscountable = [];

    /** @var list<string> pozycje „do wyczerpania zapasu” poza kartą, bo wyrób ma inne */
    private array $phasedOut = [];

    /** @var list<string> kolory bez odczytanej dostępności */
    private array $availabilityErrors = [];

    /** @var array{url: string, file: array{bytes: string, mime: string}}|null ostatni pobrany plik (synchronizacja pyta o nowy plik dwa razy) */
    private ?array $lastDocument = null;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  wyrobów na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly SirB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'sir';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return SirB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_ROW];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new SirB2bClient((string) $account->username, (string) $account->password, $delayMs));
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $this->summary = [];
        $this->withoutPrice = [];
        $this->withoutDescription = [];
        $this->unorderable = [];
        $this->promotions = [];
        $this->notDiscountable = [];
        $this->phasedOut = [];
        $this->availabilityErrors = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        try {
            $this->dictionary = $this->client->merchandiseGroups();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // bez słownika kategoria to nazwa działu z wyrobu (CLASS_NAME), bez grupy towarowej
            $this->dictionary = ['classes' => [], 'groups' => []];
            $this->summary[] = 'Słownik grup towarowych nieodczytany ('.$e->getMessage().') — kategorie bez grupy towarowej';
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista SIR: '.count($rows).' wyrobów';

        $done = 0;
        foreach ($rows as $row) {
            $product = $this->productFor($row);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych wyrobów '.SirB2bClient::HOST.' bez ceny konta — sesja konta nie pokazuje cen albo sklep zmienił dane wyrobu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Wyroby SIR: '.$done.'/'.count($rows));
            }
            yield $product;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = sprintf(
            'Karty: %d (%d z rozmiarami albo kolorami w różnych cenach — cena karty = najniższa, ceny pozycji w tabeli karty), ceny w EUR',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta albo bez pozycji do zamówienia (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->unorderable !== []) {
            $lines[] = 'Pozycje, których koszyk sklepu nie przyjmuje (poza kartą): '.self::listing($this->unorderable);
        }
        if ($this->phasedOut !== []) {
            $lines[] = 'Pozycje do wyczerpania zapasu (Z1) poza kartą, bo wyrób ma inne: '.self::listing($this->phasedOut);
        }
        if ($this->notDiscountable !== []) {
            $lines[] = 'Pozycje bez rabatu konta (cena cennikowa, jak w koszyku): '.self::listing($this->notDiscountable);
        }
        if ($this->promotions !== []) {
            $lines[] = 'Pozycje z ceną promocyjną (na karcie cena konta bez promocji): '.self::listing($this->promotions);
        }
        if ($this->availabilityErrors !== []) {
            $lines[] = 'Dostępność nieodczytana (pozycje bez stanu w tym przebiegu): '.self::listing($this->availabilityErrors);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'wyrób nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('wyrób bez ceny konta');
        }

        return $price;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        return array_map(
            static fn (array $row): B2bRemoteShopField => new B2bRemoteShopField($row['section'], $row['name'], $row['value']),
            $product->raw['fields'] ?? [],
        );
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (array $file): B2bRemoteDocument => new B2bRemoteDocument($file['title'], $file['url'], $file['kind']),
            $product->raw['documents'] ?? [],
        );
    }

    /**
     * Plik generowany przez sklep (5–10 s na kartę techniczną). Synchronizacja pyta o nowy plik dwa razy (tekst do opisu,
     * potem zapis) — drugi raz z pamięci.
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        if ($this->lastDocument !== null && $this->lastDocument['url'] === $document->sourceUrl) {
            return $this->lastDocument['file'];
        }
        $file = $this->client->documentBytes($document->sourceUrl);
        $this->lastDocument = ['url' => $document->sourceUrl, 'file' => $file];

        return $file;
    }

    /**
     * Zdjęcie każdego koloru karty (kolor wiodący pierwszy); wyrób bez kolorów — zdjęcie wyrobu.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['images'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->imageBytes($url);

        return $file === null ? null : new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        foreach ($this->imageUrls($product) as $url) {
            $image = $this->imageAt($url);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /**
     * Wiersz „Norma” i poziomy normy ze sklepu (product/productNorms, PerformanceList: nazwa poziomu po włosku → wartość):
     * - jedna wartość (także powtórzona) — za oznaczeniem normy dosłownie: „EN ISO 20345 S2 FO SR”, „EN 149 FFP2 NR D”;
     * - EN 388, EN 407, EN 511 z poziomami jednoznakowymi — zwarty kod w kolejności sklepu, która jest kolejnością normy
     *   (sprawdzone na całym katalogu 01.10.2026): „EN 388 3143X”, „EN 407 X2XXXX”, „EN 511 X1X”; tak zapisuje je też
     *   krótki opis wyrobu;
     * - pozostałe (EN 166 „F” i „1”, EN 343 „3” i „1”, EN ISO 11612, EN ISO 374-1…) — samo oznaczenie, a poziomy osobno
     *   z nazwami ze sklepu: sklejone („EN 166 F1”, „EN 343 31”) zmieniłyby znaczenie.
     *
     * @param  list<array{name: string, value: string}>  $levels
     * @return array{norm: string, levels: string} levels '' = poziomy w wierszu normy albo brak poziomów
     */
    public static function normRow(string $norm, array $levels): array
    {
        $norm = self::clean($norm);
        $levels = array_values(array_filter(
            array_map(static fn (array $l): array => ['name' => self::clean($l['name']), 'value' => self::clean($l['value'])], $levels),
            static fn (array $l): bool => $l['value'] !== '',
        ));
        $values = array_values(array_unique(array_column($levels, 'value')));
        if ($norm === '' || $values === []) {
            return ['norm' => $norm, 'levels' => ''];
        }
        if (count($values) === 1) {
            return ['norm' => $norm.' '.$values[0], 'levels' => ''];
        }
        $code = in_array(strtoupper($norm), self::CODE_NORMS, true);
        foreach ($levels as $level) {
            $code = $code && mb_strlen($level['value']) === 1;
        }
        if ($code) {
            return ['norm' => $norm.' '.implode('', array_column($levels, 'value')), 'levels' => ''];
        }

        return [
            'norm' => $norm,
            // nazwy poziomów sklep podaje po włosku — po polsku ze słownika (SirShopTranslations), wartości dosłownie
            'levels' => implode('; ', array_map(
                static fn (array $l): string => $l['name'] !== '' ? SirShopTranslations::levelLabel($l['name']).': '.$l['value'] : $l['value'],
                $levels,
            )),
        ];
    }

    /**
     * Tekst sklepu (zwykły tekst z podziałami wierszy): odstępy w wierszach zwinięte, najwyżej jedna pusta linia pod rząd.
     */
    public static function plainText(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }
            $lines[] = $line;
        }
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Cała lista wyrobów; niespójna (liczba wyrobów zmieniła się w trakcie, wyrób na dwóch stronach) — jedno ponowne
     * pobranie od początku.
     *
     * @return list<array{id: string, name: string}>
     */
    private function listRows(): array
    {
        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista wyrobów '.SirB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $total = 0;
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.SirB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage(($page - 1) * $this->pageSize, $this->pageSize);
            $pageTotal = is_int($json['TOTAL'] ?? null) ? $json['TOTAL'] : -1;
            $items = is_array($json['OUT_DATA'] ?? null) ? $json['OUT_DATA'] : null;
            if ($items === null) {
                throw new RuntimeException('Strona '.$page.' listy '.SirB2bClient::HOST.' bez wyrobów — zmiana sklepu?');
            }
            if ($page === 1) {
                $total = $pageTotal;
                if ($total <= 0) {
                    throw new RuntimeException('Lista wyrobów '.SirB2bClient::HOST.' pusta albo bez licznika (TOTAL '.$pageTotal.') — pusta lista konta albo zmiana sklepu');
                }
                $pages = min((int) ceil($total / $this->pageSize), self::MAX_LIST_PAGES);
                $this->progress('Lista wyrobów SIR: '.$total.' na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba wyrobów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }
            foreach ($items as $item) {
                $id = is_array($item) && is_scalar($item['MATNR'] ?? null) ? self::clean((string) $item['MATNR']) : '';
                if ($id === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.SirB2bClient::HOST.': wyrób bez kodu — zmiana sklepu?');
                }
                if (isset($rows[$id])) {
                    throw new RuntimeException('wyrób '.$id.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$id] = ['id' => $id, 'name' => self::clean((string) ($item['MAKTX'] ?? ''))];
            }
        }
        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' wyrobów', self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Karta wyrobu ze wszystkimi kolorami i rozmiarami do zamówienia; błąd odczytu albo brak ceny konta — wyrób pominięty
     * z powodem (synchronizacja nie uzna go za wycofany).
     *
     * @param  array{id: string, name: string}  $row
     */
    private function productFor(array $row): B2bRemoteProduct
    {
        $id = $row['id'];
        try {
            $rows = $this->client->product($id);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($id, $row['name'], 'wyrób nieodczytany: '.$e->getMessage());
        }
        $father = $rows[0] ?? null;
        if ($father === null || self::clean((string) ($father['SATNR'] ?? '')) !== $id) {
            return $this->skipped($id, $row['name'], 'sklep nie oddał danych wyrobu');
        }
        $baseName = $this->cardName($father, $row['name'], $id);

        // wyrób bez wariantów (ATTYP „00”) sam jest pozycją; sklep bierze wtedy tylko pierwszy wiersz (getProduct)
        $variants = (string) ($father['ATTYP'] ?? '') === '00'
            ? [$father]
            : array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['ATTYP'] ?? '') === '02' && self::clean((string) ($r['SATNR'] ?? '')) === $id));
        $prices = [];
        foreach (is_array($father['PriceList'] ?? null) ? $father['PriceList'] : [] as $entry) {
            if (is_array($entry) && is_scalar($entry['MATNR'] ?? null)) {
                $prices[self::clean((string) $entry['MATNR'])] ??= $entry;
            }
        }
        $colourNames = [];
        foreach (is_array($father['ColorList'] ?? null) ? $father['ColorList'] : [] as $colour) {
            $colourId = is_array($colour) ? self::clean((string) ($colour['ColorID'] ?? '')) : '';
            if ($colourId !== '') {
                $colourNames[$colourId] = self::clean((string) ($colour['Name'] ?? ''));
            }
        }

        // pozycje do zamówienia — reguły koszyka sklepu (prepareOrderRow, checkRowValidity)
        $positions = [];
        foreach ($variants as $variant) {
            $position = $this->position($variant, $prices);
            if (isset($position['reason'])) {
                $this->unorderable[] = $position['label'].' ('.$position['reason'].')';

                continue;
            }
            $positions[] = $position;
        }
        if ($positions === []) {
            $this->withoutPrice[] = $id;

            return $this->skipped($id, $baseName, 'żadna pozycja wyrobu nie ma ceny konta do zamówienia');
        }
        $regular = array_values(array_filter($positions, static fn (array $p): bool => ! $p['phased_out']));
        if ($regular !== [] && count($regular) < count($positions)) {
            foreach ($positions as $position) {
                if ($position['phased_out']) {
                    $this->phasedOut[] = $position['id'];
                }
            }
            $positions = $regular;
        }

        try {
            $texts = $this->client->texts($id);
            $norms = $this->client->norms($id);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // pusty opis albo tabelka bez norm wyglądałyby jak zmiana w sklepie — wyrób pominięty w tym przebiegu
            return $this->skipped($id, $baseName, 'opis albo normy nieodczytane: '.$e->getMessage());
        }

        $colours = [];
        foreach ($positions as $position) {
            $colours[$position['colour']] = true;
        }
        $colours = array_keys($colours);
        $manyColours = count($colours) > 1;
        $availability = $this->availabilityFor($id, $colours);

        $members = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $id, field: 'SATNR')];
        $statuses = [];
        $orders = [];
        $cheapest = null;
        $sizeNames = [];
        $colourLabels = [];
        foreach ($positions as $position) {
            $colourLabel = $this->colourLabel($position['colour'], $colourNames);
            if ($colourLabel !== '') {
                $colourLabels[$colourLabel] = true;
            }
            $sizeLabel = self::positionLabel($manyColours ? $colourLabel : '', $position['size'], $position['id']);
            $stock = $availability[$position['id']] ?? null;
            $price = new B2bRemotePrice(net: $position['net'], base: $position['base'], currency: self::CURRENCY);
            $member = [
                'remote_id' => $position['id'],
                'sku' => $position['id'],
                'name' => self::memberName($baseName, $colourLabel, $position['size']),
                'size' => $sizeLabel,
                'price' => $price,
            ];
            if ($stock !== null) {
                $member['availability'] = $stock['text'];
                $statuses[$stock['status']][] = $sizeLabel;
            }
            $members[] = $member;
            if ($position['ean'] !== '') {
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $position['ean'], remoteId: $position['id'], label: $sizeLabel, field: 'EAN11');
            }
            if ($position['size'] !== '') {
                $sizeNames[$position['size']] = true;
            }
            $orders[$position['order']->min.'/'.$position['order']->step] = $position['order'];
            if ($position['promo'] !== null) {
                $this->promotions[] = $position['id'].' ('.$position['promo'].')';
            }
            if ($position['not_discountable']) {
                $this->notDiscountable[] = $position['id'];
            }
            if ($cheapest === null || $price->net < $cheapest->net) {
                $cheapest = $price;
            }
        }
        foreach (self::legacyCodes((string) ($father['BISMT'] ?? '')) as $code) {
            $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_LEGACY_CODE, value: $code, field: 'BISMT');
        }

        $this->withPrice++;
        $this->cards++;
        $nets = array_map(static fn (array $m): string => (string) $m['price']->net, $members);
        $this->multiPrice += count(array_unique($nets)) > 1 ? 1 : 0;
        $order = count($orders) === 1 ? array_values($orders)[0] : new B2bOrderQuantity(min: null, step: null, varies: true);

        $description = self::descriptionText($texts);
        if ($description === '') {
            $this->withoutDescription[] = $id;
        }
        $leadColour = (string) $colours[0];
        $images = [];
        foreach ($colours as $colour) {
            $images[] = SirB2bClient::imageUrl($id.$colour);
        }

        $summaryParts = [];
        if ($colourLabels !== []) {
            $summaryParts[] = (count($colourLabels) > 1 ? 'Kolory: ' : 'Kolor: ').implode(', ', array_keys($colourLabels));
        }
        if ($sizeNames !== []) {
            $summaryParts[] = 'rozmiary: '.implode(', ', array_map('strval', array_keys($sizeNames)));
        }

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $id,
            name: $baseName,
            category: $this->category($father),
            sourceUrl: SirB2bClient::productUrl($id),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(net: $cheapest->net, base: $cheapest->base, currency: self::CURRENCY, order: $order),
                'description' => $description,
                'fields' => $this->fields($father, $id, array_keys($colourLabels), array_keys($sizeNames), $norms),
                'documents' => self::documentList($id, $leadColour),
                'images' => $images,
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: $summaryParts !== [] ? ucfirst(implode('; ', $summaryParts)) : '',
            members: $members,
            identifiers: $identifiers,
        );
    }

    /**
     * Pozycja (wariant) do zamówienia albo powód, dla którego koszyk sklepu jej nie przyjmie.
     *
     * @param  array<string, mixed>  $variant
     * @param  array<string, array<string, mixed>>  $prices
     * @return array{id: string, colour: string, size: string, ean: string, net: float, base: float|null, promo: string|null, not_discountable: bool, phased_out: bool, order: B2bOrderQuantity}|array{label: string, reason: string}
     */
    private function position(array $variant, array $prices): array
    {
        $id = self::clean((string) ($variant['MATNR'] ?? ''));
        $label = $id !== '' ? $id : '?';
        $reasons = [];
        if (strtoupper((string) ($variant['FSH_MG_AT1'] ?? '')) === 'N' || strtoupper((string) ($variant['FSH_MG_AT2'] ?? '')) === 'N') {
            $reasons[] = 'nieprowadzona w sklepie';
        }
        $leadDays = (int) ($variant['PLIFZ'] ?? 0);
        if ($leadDays > 0) {
            $reasons[] = 'czas dostawy '.$leadDays.' dni';
        }
        if (strtoupper((string) ($variant['MSTAE'] ?? '')) === 'Z2') {
            $reasons[] = 'niedostępna';
        }
        $entry = $prices[$id] ?? null;
        $notDiscountable = strtoupper((string) ($variant['PRAT6'] ?? '')) === 'X';
        $list = self::amount($entry['KBETR'] ?? null);
        $account = self::amount($entry['B2B_KBETR'] ?? null);
        $net = $notDiscountable ? $list : ($account ?? $list);
        if ($entry === null || $net === null) {
            $reasons[] = 'bez ceny';
        } elseif (($entry['B2B_AV_FOR_SALE'] ?? null) === false) {
            $reasons[] = 'nie na sprzedaż';
        }
        if ($id === '' || $reasons !== []) {
            return ['label' => $label, 'reason' => $reasons !== [] ? implode(', ', $reasons) : 'bez kodu'];
        }

        $promo = self::amount($entry['B2B_PM_KBETR'] ?? null);
        $promoText = null;
        if ($promo !== null && ! $notDiscountable) {
            $code = self::clean((string) ($entry['B2B_PM_CODE'] ?? ''));
            $minimum = (int) ($entry['B2B_PM_MIN_ORD_QTY'] ?? 0);
            $promoText = number_format($promo, 2, ',', '').' '.self::CURRENCY.($code !== '' ? ', '.$code : '').($minimum > 0 ? ', od '.$minimum : '');
        }
        $minimum = max(0, (int) round((float) ($variant['AUMNG'] ?? 0)));
        $pack = max(0, (int) ($variant['PACK_QTY'] ?? 0));

        return [
            'id' => $id,
            'colour' => self::clean((string) ($variant['COLOR'] ?? '')),
            'size' => self::clean((string) ($variant['SIZE_DESC'] ?? '')),
            'ean' => preg_match('/^\d{8,14}$/', self::clean((string) ($variant['EAN11'] ?? ''))) === 1 ? self::clean((string) $variant['EAN11']) : '',
            'net' => (float) $net,
            'base' => $list,
            'promo' => $promoText,
            'not_discountable' => $notDiscountable,
            'phased_out' => strtoupper((string) ($variant['MSTAE'] ?? '')) === 'Z1',
            'order' => self::orderQuantity($minimum, $pack),
        ];
    }

    /**
     * Warunek zamawiania jak w koszyku sklepu: ilość co najmniej AUMNG i wielokrotność PACK_QTY (checkRowValidity) —
     * minimum = najmniejsza wielokrotność opakowania nie mniejsza niż AUMNG.
     */
    private static function orderQuantity(int $minimum, int $pack): B2bOrderQuantity
    {
        if ($pack > 1) {
            return new B2bOrderQuantity(min: (float) (max(1, (int) ceil(max($minimum, 1) / $pack)) * $pack), step: (float) $pack);
        }

        return new B2bOrderQuantity(min: (float) max($minimum, 1), step: null);
    }

    /**
     * Dostępność pozycji kolorów karty (pozycja → stan i tekst z ilością); błąd odczytu koloru — jego pozycje bez stanu
     * w tym przebiegu.
     *
     * @param  list<string>  $colours
     * @return array<string, array{status: string, text: string}>
     */
    private function availabilityFor(string $id, array $colours): array
    {
        $today = Carbon::now('Europe/Warsaw')->format('Ymd');
        $out = [];
        foreach ($colours as $colour) {
            try {
                $rows = $this->client->availability($id.$colour);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                $this->availabilityErrors[] = $id.$colour;

                continue;
            }
            $byPosition = [];
            foreach ($rows as $row) {
                $position = self::clean((string) ($row['MATNR'] ?? ''));
                $date = self::clean((string) ($row['DAT00'] ?? ''));
                if ($position === '' || preg_match('/^\d{8}$/', $date) !== 1 || $date === '99991231' || $date < $today) {
                    continue;
                }
                $byPosition[$position][$date] = (int) ($row['MNG02'] ?? 0);
            }
            foreach ($byPosition as $position => $dates) {
                ksort($dates);
                $out[$position] = self::availabilityText($dates, $today);
            }
        }

        return $out;
    }

    /**
     * Stan pozycji jak w koszyku sklepu (getDateAvailable): ilość na dziś, inaczej pierwszy tydzień z ilością.
     *
     * @param  array<int|string, int>  $dates  „RRRRMMDD” → ilość, rosnąco (PHP trzyma takie klucze jako liczby)
     * @return array{status: string, text: string}
     */
    private static function availabilityText(array $dates, string $today): array
    {
        if (($dates[$today] ?? 0) > 0) {
            return ['status' => 'Na stanie', 'text' => 'Na stanie: '.$dates[$today]];
        }
        foreach ($dates as $date => $quantity) {
            $date = (string) $date;
            if ($quantity > 0) {
                $status = 'Dostępne od '.substr($date, 6, 2).'.'.substr($date, 4, 2).'.'.substr($date, 0, 4);

                return ['status' => $status, 'text' => $status];
            }
        }

        return ['status' => 'Brak na stanie', 'text' => 'Brak na stanie'];
    }

    /**
     * Nazwa karty (dosłowna, tłumaczona po zapisie): nazwa wyrobu ze sklepu i kod wyrobu — „VICTORIA glove MA1113”;
     * wyrób bez angielskiej nazwy — nazwa z listy, a bez niej włoska (MAKTX_ITA).
     *
     * @param  array<string, mixed>  $father
     */
    private function cardName(array $father, string $listName, string $id): string
    {
        $name = self::clean((string) ($father['MAKTX'] ?? ''));
        if ($name === '') {
            $name = $listName !== '' ? $listName : self::clean((string) ($father['MAKTX_ITA'] ?? ''));
        }
        if ($name === '') {
            return $id;
        }

        return str_contains(self::squashed($name), self::squashed($id)) ? $name : $name.' '.$id;
    }

    /**
     * Kategoria: dział > grupa towarowa ze słownika SAP, po polsku z SirShopTranslations (06.10.2026: „Rękawice >
     * Rękawice chroniące przed przecięciem”; nazwa spoza słownika zostaje po angielsku); bez słownika SAP — nazwa
     * działu z wyrobu.
     *
     * @param  array<string, mixed>  $father
     */
    private function category(array $father): ?string
    {
        $class = self::clean((string) ($father['CLASS'] ?? ''));
        $group = self::clean((string) ($father['MATKL'] ?? ''));

        return SirShopTranslations::categoryPath(
            self::clean($this->dictionary['classes'][$class] ?? (string) ($father['CLASS_NAME'] ?? '')),
            self::clean($this->dictionary['groups'][$group] ?? ''),
        );
    }

    /**
     * Opis: długi opis, krótki opis (parametry: norma, materiały, zręczność) i uwagi sklepu (zastosowanie, przechowywanie)
     * dosłownie; powtórzone teksty raz.
     *
     * @param  array<string, mixed>  $texts
     */
    private static function descriptionText(array $texts): string
    {
        $parts = [];
        foreach (['longDescription', 'shortDescription', 'warningText'] as $key) {
            $text = self::plainText(is_string($texts[$key] ?? null) ? $texts[$key] : '');
            if ($text !== '' && ! in_array($text, $parts, true)) {
                $parts[] = $text;
            }
        }

        return mb_substr(implode("\n\n", $parts), 0, 10000);
    }

    /**
     * Wiersze tabelki: dane wyrobu ze sklepu i normy z poziomami.
     *
     * @param  array<string, mixed>  $father
     * @param  list<string>  $colours
     * @param  list<string|int>  $sizes
     * @param  list<array<string, mixed>>  $norms
     * @return list<array{section: string, name: string, value: string}>
     */
    private function fields(array $father, string $id, array $colours, array $sizes, array $norms): array
    {
        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            $value = self::clean($value);
            if ($value !== '') {
                $fields[] = ['section' => $section, 'name' => $name, 'value' => $value];
            }
        };
        $class = self::clean((string) ($father['CLASS'] ?? ''));
        $group = self::clean((string) ($father['MATKL'] ?? ''));

        $add(self::SECTION_MAIN, 'Kod wyrobu', $id);
        $add(self::SECTION_MAIN, 'Dawne kody SIR', implode(', ', self::legacyCodes((string) ($father['BISMT'] ?? ''))));
        // wartości ze słowników sklepu po polsku (SirShopTranslations); kody (wyrobu, koloru w nawiasie) dosłownie
        $add(self::SECTION_MAIN, 'Dział', SirShopTranslations::department(self::clean($this->dictionary['classes'][$class] ?? (string) ($father['CLASS_NAME'] ?? ''))));
        $add(self::SECTION_MAIN, 'Grupa towarowa', SirShopTranslations::group(self::clean($this->dictionary['groups'][$group] ?? '')));
        $add(self::SECTION_MAIN, 'Kategoria ŚOI', SirShopTranslations::ppeCategory(self::clean((string) ($father['DPI_CATEG'] ?? ''))));
        $add(self::SECTION_MAIN, 'Kolory', implode(', ', array_map(SirShopTranslations::colour(...), $colours)));
        $add(self::SECTION_MAIN, 'Rozmiary', implode(', ', array_map('strval', $sizes)));
        $add(self::SECTION_MAIN, 'Jednostka', SirShopTranslations::unit(self::clean((string) ($father['MSEHT'] ?? ''))));
        $add(self::SECTION_MAIN, 'Ilość w opakowaniu', self::quantity($father['PACK_QTY'] ?? null));
        $add(self::SECTION_MAIN, 'Ilość w kartonie', self::quantity($father['CART_QTY'] ?? null));
        $add(self::SECTION_MAIN, 'Minimalne zamówienie', self::quantity($father['AUMNG'] ?? null));
        $add(self::SECTION_MAIN, 'Kraj pochodzenia', SirShopTranslations::country(self::clean((string) ($father['WHERL'] ?? ''))));

        $seen = [];
        $levelRows = [];
        foreach ($norms as $norm) {
            $levels = [];
            foreach (is_array($norm['PerformanceList'] ?? null) ? $norm['PerformanceList'] : [] as $performance) {
                if (is_array($performance) && is_scalar($performance['PerformanceValue'] ?? null)) {
                    $levels[] = ['name' => is_scalar($performance['Name'] ?? null) ? (string) $performance['Name'] : '', 'value' => (string) $performance['PerformanceValue']];
                }
            }
            $row = self::normRow((string) ($norm['TestoNorma'] ?? ''), $levels);
            $key = $row['norm'].'|'.$row['levels'];
            if ($row['norm'] === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $add(self::SECTION_NORMS, self::NORM_ROW, $row['norm']);
            if ($row['levels'] !== '') {
                $levelRows[] = ['Poziomy '.$row['norm'], $row['levels']];
            }
        }
        // poziomy pod wierszami norm — wiersz „Norma” zostaje samym oznaczeniem (ShopCardNormFacts czyta resztę jako poziom)
        foreach ($levelRows as [$name, $value]) {
            $add(self::SECTION_NORMS, $name, $value);
        }

        return $fields;
    }

    /**
     * Pliki generowane przez sklep dla koloru wiodącego: karta techniczna (angielska) i deklaracja zgodności (polska).
     * Nazwy jak przy pobraniu ze sklepu („stpMA1113_EN.pdf”, „MA1113B0_DoC_PL.pdf”).
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentList(string $id, string $leadColour): array
    {
        $sheet = self::DATASHEET_LANGUAGE;
        $declaration = self::DECLARATION_LANGUAGE;

        return [
            [
                'title' => 'stp'.$id.'_'.$sheet['code'].'.pdf',
                'url' => SirB2bClient::documentUrl('getDS', [
                    'productID' => $id,
                    'colorID' => $leadColour !== '' ? $leadColour : 'null',
                    'langID' => $sheet['id'],
                    'norm_manager' => 'false',
                ]),
                'kind' => ProductDocument::KIND_DATASHEET,
            ],
            [
                'title' => $id.$leadColour.'_DoC_'.$declaration['code'].'.pdf',
                'url' => SirB2bClient::documentUrl('getCD', ['prodID' => $id.$leadColour, 'langID' => $declaration['id']]),
                'kind' => ProductDocument::KIND_CERTIFICATE,
            ],
        ];
    }

    /**
     * „GREY (B0)”; kolor bez nazwy — sam kod; bez koloru — ''.
     *
     * @param  array<string, string>  $names
     */
    private function colourLabel(string $colour, array $names): string
    {
        if ($colour === '') {
            return '';
        }
        $name = $names[$colour] ?? '';

        return $name !== '' ? $name.' ('.$colour.')' : $colour;
    }

    /** Nazwa pozycji: „VICTORIA glove MA1113, GREY (B0) 10”; pozycja bez koloru i rozmiaru — sama nazwa karty. */
    private static function memberName(string $baseName, string $colour, string $size): string
    {
        $suffix = self::clean($colour.' '.$size);

        return $suffix !== '' ? $baseName.', '.$suffix : $baseName;
    }

    /** Etykieta pozycji w tabeli rozmiarów: „GREY (B0) / 10”, „10”; bez rozmiaru — kolor, a bez obu — kod wariantu. */
    private static function positionLabel(string $colour, string $size, string $id): string
    {
        if ($colour !== '' && $size !== '') {
            return $colour.' / '.$size;
        }
        if ($size !== '' || $colour !== '') {
            return $size !== '' ? $size : $colour;
        }

        return $id;
    }

    /**
     * Dawne kody SIR z BISMT („26057A,26057AD”) — dosłownie, bez powtórzeń.
     *
     * @return list<string>
     */
    private static function legacyCodes(string $value): array
    {
        $codes = [];
        foreach (explode(',', $value) as $code) {
            $code = self::clean($code);
            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Ilość z SAP („12.000”, „60”) bez zer po przecinku; 0 i puste — ''. */
    private static function quantity(mixed $value): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return $number > 0 ? B2bOrderQuantity::format($number) : '';
    }

    /**
     * Jeden stan dla wszystkich pozycji — sam stan; różne — „Na stanie: 38, 40; Dostępne od 08.10.2026: 42”.
     *
     * @param  array<string, list<string>>  $statuses
     */
    private static function groupAvailability(array $statuses): ?string
    {
        if ($statuses === []) {
            return null;
        }
        if (count($statuses) === 1) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $labels) {
            $parts[] = $status.': '.implode(', ', $labels);
        }

        return mb_substr(implode('; ', $parts), 0, 1000);
    }

    private static function amount(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    /**
     * Wyrób, którego nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta.
     */
    private function skipped(string $id, string $name, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $id,
            sku: $id,
            name: $name !== '' ? $name : $id,
            sourceUrl: SirB2bClient::productUrl($id),
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /** Kod bez odstępów i znaków innych niż litery i cyfry, wielkimi literami. */
    private static function squashed(string $text): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    /** Tekst ze sklepu: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
