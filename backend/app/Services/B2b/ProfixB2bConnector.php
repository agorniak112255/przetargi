<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Support\BhpAttributeNormalizer;
use RuntimeException;

/**
 * partners.profix.com.pl — platforma B2B Profix sp. z o.o., właściciela marki Lahti Pro. Konto zamawia tu wyłącznie
 * Lahti Pro (decyzja użytkownika 08.10.2026), więc łącznik czyta tylko pozycje z parametrem „Marka” = „LAHTI PRO”
 * (filtr portalu; id parametru i wartości odczytywane z odpowiedzi, nie wpisane na sztywno). Sprawdzone 08.10.2026 na
 * API portalu (ProfixB2bClient): 3903 pozycje Lahti Pro z 18 021 w katalogu, ceny w PLN.
 *
 * Pozycja portalu to jeden rozmiar (albo kolor) wyrobu: symbol „L4055304”, nazwa „SPODNIE STRETCH ZIELONO-CZARNE, "XL",
 * CE, LAHTI”. Kod modelu = litera i 5 cyfr na początku symbolu (L40553 — reguła użytkownika, ten sam kod jest
 * w sklepie lahtipro.pl). Pod jednym kodem modelu portal trzyma czasem inny wyrób (przyłbica L1540400 i szybki do niej
 * L1540401) albo inne opakowanie (zatyczki 5/100/200 par, rękawice „KARTA” i „12 PAR”), dlatego karta = kod modelu
 * i nazwa pozycji bez rozmiaru, koloru i kodów tego modelu — inne opakowanie, inne oznaczenie obuwia („SB” i „SB SRA”)
 * czy inny wyrób zostaje osobną kartą. Kody spoza wzorca („LPPOMA39”, „46017”) — model to kod bez końcówki
 * rozmiaru z nazwy (LPPOMA), inaczej cały kod.
 *
 * Cena pozycji: pricePromo = cena konta (po rabacie konta), price = cena katalogowa. Promocje portalu (czasowe,
 * „warunki u opiekuna”, pakiety) nie są ceną zakupu — ich liczba idzie tylko do dziennika. Opis, parametry, pliki
 * i zdjęcia — ze szczegółów pozycji wiodącej karty (/catalog/product/{symbol}); tam też sprawdzana jest marka.
 */
final class ProfixB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Lahti Pro';

    public const LABEL = 'Profix';

    /** Parametr marki w filtrach portalu i jego wartość dla Lahti Pro — dosłownie z portalu. */
    private const BRAND_PARAM = 'Marka';

    private const BRAND_VALUE = 'LAHTI PRO';

    /**
     * Ostatni człon nazwy z inną marką Profixu: żyłki „…, PROLINE” mają w portalu „Marka: LAHTI PRO”, ale to wyroby
     * Proline — poza kartami (konto zamawia tylko Lahti Pro).
     */
    private const OTHER_BRAND_TAILS = ['PROLINE'];

    private const PAGE_SIZE = 200;

    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const MAX_PAGES = 200;

    private const PROGRESS_EVERY = 50;

    private const INCONSISTENT = 7311;

    /** Tyle pierwszych kart z obcą marką w szczegółach (bez żadnej Lahti Pro) = filtr marki nie działa; przebieg przerwany. */
    private const MAX_FIRST_FOREIGN = 20;

    private const MAX_IMAGES = 6;

    /** Wariant zdjęć portalu: do 1600 px (sprawdzone 08.10.2026: 568×1600, 72 KB przy oryginale 870×2452, 362 KB). */
    private const IMAGE_FILTER = 'full';

    /**
     * Tyle pierwszych kart z ceną, w których żadna pozycja nie ma ceny konta niższej od katalogowej = konto bez rabatu
     * (dziś −35% przy 3883 z 3903 pozycji) — zmiana konta albo portalu; przebieg przerwany, zanim zapisze ceny katalogowe.
     */
    private const MAX_FIRST_WITHOUT_DISCOUNT = 20;

    private const NORM_ROW = 'NORMA';

    /**
     * Wiersz normy obuwia złożony z dwóch wierszy opisu: „NORMA: EN ISO 20345:2022” i „KAT. BEZPIECZEŃSTWA: S1 FO SR” →
     * „EN ISO 20345:2022 S1 FO SR” (kategoria to klasa tej normy). Tylko gdy NORMA to dokładnie jedna norma obuwia,
     * a klasa do niej pasuje (S… — EN ISO 20345, O… — EN ISO 20347); dosłowne wiersze zostają w opisie.
     */
    private const FOOTWEAR_NORM_ROW = 'NORMA (z kategorią bezpieczeństwa)';

    private const FOOTWEAR_CATEGORY_ROW = 'KAT. BEZPIECZEŃSTWA';

    private const SECTION_MAIN = 'Informacje z portalu Profix';

    /** Parametry pozycji, nie karty (rozmiar i waga rozmiaru) albo powielone gdzie indziej (marka = producent karty). */
    private const ITEM_PARAMS = ['rozmiar', 'marka', 'waga [kg]'];

    /** Rodzaje plików z kluczy tłumaczeń portalu. */
    private const DOCUMENTS = [
        'pim.doc.product_card' => ['Karta produktu', ProductDocument::KIND_DATASHEET],
        'pim.doc.declaration_conformity' => ['Deklaracja zgodności', ProductDocument::KIND_CERTIFICATE],
        'pim.doc.instruction' => ['Instrukcja użytkowania', ProductDocument::KIND_MANUAL],
    ];

    /** Jednostki portalu w warunku zamawiania. */
    private const UNITS = ['SZT' => 'szt.', 'PR' => 'para', 'KPL' => 'kpl.'];

    /** Poziom stanu portalu (stockLevel). */
    private const STOCK_LEVELS = ['high' => 'dużo', 'medium' => 'średnio', 'low' => 'mało'];

    /** Rozmiar: litery (XS…6XL), liczba (rękawice, obuwie), para „S/M”, „8 - M”, z dopiskiem w nawiasie „S (48)”, „XL(188/98-102)”. */
    private const SIZE_TOKEN = '(?:[2-6]?X{0,3}[SL]|M|[2-6]?XL|\d{1,2}(?:[.,]5)?)';

    /**
     * Słowo koloru (każda część złożenia „ZIELONO-CZARNE”, „CZAR.-POM.”) — z polskimi skrótami portalu. Krótkie skróty
     * (CZAR., NIEB., POM.) tylko w całości, żeby nie łapać innych słów.
     */
    private const COLOUR_PART = '/^(?:JASNO|CIEMNO)?(?:CZERWON\p{L}*|CZERW\.?|CZARN\p{L}*|CZAR\.?|NIEBIESK\p{L}*|NIEB\.?|ZIELON\p{L}*|ZIEL\.?'
        .'|ŻÓŁT\p{L}*|BIAŁ\p{L}*|POMARAŃCZ\p{L}*\.?|POM\.?|SZAR\p{L}*|GRANATOW\p{L}*|GRANAT\.?|FIOLETOW\p{L}*|FIOL\.?|RÓŻOW\p{L}*'
        .'|SREBRN\p{L}*|BRĄZOW\p{L}*|BEŻOW\p{L}*|LIMONKOW\p{L}*|OLIWKOW\p{L}*|GRAFITOW\p{L}*|KHAKI|MORO|MELANGE|BORDOW\p{L}*|BORDO'
        .'|KREMOW\p{L}*|BEZB\.?|BEZBARWN\p{L}*)$/u';

    private int $total = 0;

    private int $items = 0;

    private int $cardsDone = 0;

    private int $multiPrice = 0;

    private int $lahtiCards = 0;

    private int $discountedCards = 0;

    /** @var array<string, array<string, mixed>> karty z listy (klucz: model + nazwa bez rozmiaru i koloru) */
    private array $cards = [];

    /** @var array<string, int> model → liczba kart z tym modelem */
    private array $modelCards = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $foreign = [];

    /** @var list<string> */
    private array $otherBrandNames = [];

    /** @var list<string> pozycje bez ceny konta w PLN (poza kartą) */
    private array $unpriced = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $unreadable = [];

    /** @var list<string> pozycje z promocją niższą od ceny konta */
    private array $promotions = [];

    /** @var list<string> pozycje z tym samym rozmiarem co inna pozycja karty */
    private array $duplicateSizes = [];

    /** @var list<string> */
    private array $splitModels = [];

    /** @var list<string> karty kilku kolorów, w których opis koloru różni się od opisu pozycji wiodącej */
    private array $colourDetailsDiffer = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  pozycji na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly ProfixB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'profix';
    }

    public static function label(): string
    {
        return self::LABEL;
    }

    public static function host(): string
    {
        return ProfixB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_ROW, self::FOOTWEAR_NORM_ROW];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new ProfixB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->foreign = [];
        $this->otherBrandNames = [];
        $this->unpriced = [];
        $this->withoutDescription = [];
        $this->unreadable = [];
        $this->promotions = [];
        $this->duplicateSizes = [];
        $this->splitModels = [];
        $this->cardsDone = 0;
        $this->multiPrice = 0;
        $this->lahtiCards = 0;
        $this->discountedCards = 0;
        $this->colourDetailsDiffer = [];

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        [$items, $categories, $filter] = $this->listItems();
        $paths = $this->categoryPaths($categories, $filter);
        $this->items = count($items);
        $this->group($items, $paths);
        unset($items, $paths);

        $this->total = count($this->cards);
        $this->summary[] = 'Lista Profix (Marka '.self::BRAND_VALUE.'): '.$this->items.' pozycji, '.count($this->modelCards).' modeli → '.$this->total.' kart';

        $done = 0;
        foreach (array_keys($this->cards) as $key) {
            $card = $this->cards[$key];
            unset($this->cards[$key]);
            $product = $this->productFor($card);
            if ($this->lahtiCards === 0 && count($this->foreign) >= self::MAX_FIRST_FOREIGN) {
                throw new B2bFatalException(count($this->foreign).' pierwszych kart '.ProfixB2bClient::HOST.' ma w szczegółach inną markę niż '.self::BRAND_VALUE.' — filtr marki nie działa albo portal zmienił dane; przebieg przerwany');
            }
            if ($this->discountedCards === 0 && $this->cardsDone >= self::MAX_FIRST_WITHOUT_DISCOUNT) {
                throw new B2bFatalException($this->cardsDone.' pierwszych kart '.ProfixB2bClient::HOST.' bez ceny konta niższej od katalogowej — konto bez rabatu albo zmiana portalu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Karty Profix: '.$done.'/'.$this->total);
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
            'Karty: %d (%d z rozmiarami w różnych cenach — cena karty = najniższa, ceny pozycji w tabeli karty)',
            $this->cardsDone,
            $this->multiPrice,
        );
        if ($this->splitModels !== []) {
            $lines[] = 'Kod modelu w kilku kartach (inny wyrób, opakowanie albo oznaczenie pod tym samym kodem): '.self::listing($this->splitModels);
        }
        if ($this->otherBrandNames !== []) {
            $lines[] = 'Pozycje z inną marką w nazwie (pominięte — konto zamawia tylko '.self::BRAND.'): '.self::listing($this->otherBrandNames);
        }
        if ($this->foreign !== []) {
            $lines[] = 'Karty z inną marką w szczegółach (pominięte): '.self::listing($this->foreign);
        }
        if ($this->unpriced !== []) {
            $lines[] = 'Pozycje bez ceny konta w PLN (poza kartą): '.self::listing($this->unpriced);
        }
        if ($this->promotions !== []) {
            $lines[] = 'Pozycje z promocją niższą od ceny konta (cena zakupu = cena konta, bez promocji): '.self::listing($this->promotions);
        }
        if ($this->duplicateSizes !== []) {
            $lines[] = 'Ten sam rozmiar w dwóch pozycjach karty (rozmiar z kodem): '.self::listing($this->duplicateSizes);
        }
        if ($this->colourDetailsDiffer !== []) {
            $lines[] = 'Karty kilku kolorów z innym opisem koloru niż pozycji wiodącej (na karcie opis pozycji wiodącej): '.self::listing($this->colourDetailsDiffer);
        }
        if ($this->unreadable !== []) {
            $lines[] = 'Szczegóły nieodczytane (karta pominięta): '.self::listing($this->unreadable);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w portalu: '.self::listing($this->withoutDescription);
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
            static fn (array $row): B2bRemoteShopField => new B2bRemoteShopField(self::SECTION_MAIN, $row['name'], $row['value']),
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

    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
    }

    /**
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['images'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    /**
     * Rozbiór nazwy pozycji: model (kod modelu), czy kod jest we wzorcu litera + 5 cyfr, klucz karty (model + nazwa bez
     * rozmiaru, koloru i kodów modelu), rozmiar (dosłownie, części złączone „ / ”), kolor (słowa koloru dosłownie i postać
     * do porównania) oraz nazwa bez rozmiaru (do nazwy karty).
     *
     * @return array{model: string, standard: bool, key: string, size: string|null, colour: string, colour_key: string, name_without_size: string}
     */
    public static function analyse(string $symbol, string $name): array
    {
        $symbol = strtoupper(trim($symbol));
        $text = self::clean($name);
        $sizes = [];

        // rozmiar w cudzysłowie — tylko wartość wyglądająca na rozmiar („BOMBER” w cudzysłowie to nazwa linii)
        $text = (string) preg_replace_callback('/,?\s*"([^"]{1,40})"/u', static function (array $m) use (&$sizes): string {
            $value = self::clean($m[1]);
            if (self::isSize($value)) {
                $sizes[] = $value;

                return '';
            }

            return $m[0];
        }, $text);
        // ucięta nazwa: cudzysłów bez pary przy rozmiarze („"2XL/3XL, CE”, „,XL",CE”)
        $segments = self::segments($text);
        foreach ($segments as $i => $segment) {
            if (substr_count($segment, '"') === 1 && self::isSize(trim(str_replace('"', '', $segment)))) {
                $sizes[] = trim(str_replace('"', '', $segment));
                unset($segments[$i]);
            }
        }
        // „ROZM. XL(188/98-102)”, wiek dziecka „DLA DZIECI 4-6 LAT” (przy rozmiarze „XS”), osobny człon będący rozmiarem („, L,”)
        foreach ($segments as $i => $segment) {
            if (preg_match('/^ROZM\.?\s*(.+)$/u', $segment, $m) === 1) {
                $sizes[] = trim($m[1]);
                unset($segments[$i]);

                continue;
            }
            if (preg_match('/\b(\d{1,2}\s*-\s*\d{1,2}\s+LAT)\b/u', $segment, $m) === 1) {
                $sizes[] = $m[1];
                $segments[$i] = self::clean(str_replace($m[1], '', $segment));
            }
            if (self::isSize($segment)) {
                $sizes[] = $segment;
                unset($segments[$i]);
            }
        }
        $segments = array_values(array_filter($segments, static fn (string $s): bool => $s !== ''));
        $withoutSize = implode(', ', $segments);
        $size = $sizes === [] ? null : implode(' / ', array_unique($sizes));

        [$model, $standard] = self::model($symbol, $sizes);

        // klucz: kropka bez spacji rozdzielona („WSTAWK.STRETCH”), bez kodów tego modelu („L211907P”) i bez słów koloru
        $keyParts = [];
        $colours = [];
        foreach ($segments as $segment) {
            $segment = (string) preg_replace('/\.(?=\p{L})/u', '. ', $segment);
            $segment = (string) preg_replace('/\b'.preg_quote($model, '/').'[0-9A-Z]{0,4}\b/u', '', $segment);
            $words = [];
            foreach (preg_split('/\s+/u', self::clean($segment)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                if (self::isColour($word)) {
                    $colours[] = $word;
                } else {
                    $words[] = $word;
                }
            }
            if ($words !== []) {
                $keyParts[] = implode(' ', $words);
            }
        }

        $colour = implode(' ', $colours);

        return [
            'model' => $model,
            'standard' => $standard,
            'key' => $model."\n".implode(',', $keyParts),
            'size' => $size,
            'colour' => $colour,
            'colour_key' => (string) preg_replace('/[\s.]+/u', '', $colour),
            'name_without_size' => $withoutSize,
        ];
    }

    /** Wartość wyglądająca na rozmiar: „XL”, „S (48)”, „39”, „8 - M”, „S/M”, „2XL/3XL”, „XL(188/98-102)”. */
    public static function isSize(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^'.self::SIZE_TOKEN.'(?:\s*[\/-]\s*'.self::SIZE_TOKEN.')?(?:\s*\([0-9\/\s,.\-]+\))?$/u', $value) === 1;
    }

    /** Słowo koloru (także złożenie „ZIELONO-CZARNE”, „CZAR.-POM.”). */
    public static function isColour(string $word): bool
    {
        $parts = explode('-', $word);
        foreach ($parts as $part) {
            if ($part === '' || preg_match(self::COLOUR_PART, $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adres zdjęcia w wariancie portalu „full” (do 1600 px, ok. 70 KB; oryginał /photo/… ma ok. 2500 px i 360 KB)
     * z adresu miniatury (/media/cache/{filtr}/photo/…) albo oryginału; null = adres spoza portalu albo nie zdjęcie.
     */
    public static function imageUrl(string $url): ?string
    {
        $url = trim($url);
        if (! ProfixB2bClient::isShopUrl($url)) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = (string) preg_replace('#^/media/cache/[^/]+/#', '/', $path);
        if (preg_match('#^/photo/.+\.(?:jpe?g|png|webp|gif)$#i', $path) !== 1) {
            return null;
        }

        return ProfixB2bClient::BASE.'/media/cache/'.self::IMAGE_FILTER.$path;
    }

    /**
     * Opis HTML portalu jako tekst: wiersze listy i akapity w osobnych liniach, encje rozwinięte (także podwójnie
     * zakodowane „&amp;oacute;”), odstępy zwinięte. Piktogramy (obrazki) odpadają — idą do tabelki.
     */
    public static function htmlText(string $html): string
    {
        $html = (string) preg_replace('#<\s*br\s*/?>|</\s*(?:p|div|li|h[1-6]|tr)\s*>#iu', "\n", $html);
        $text = self::decode(strip_tags($html));
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Podpisy piktogramów opisu (alt/title obrazków) dosłownie, bez powtórzeń.
     *
     * @return list<string>
     */
    public static function pictograms(string $html): array
    {
        $out = [];
        if (preg_match_all('/<img\b[^>]*>/iu', $html, $images) === false) {
            return [];
        }
        foreach ($images[0] as $tag) {
            foreach (['title', 'alt'] as $attribute) {
                if (preg_match('/\b'.$attribute.'\s*=\s*"([^"]*)"/iu', $tag, $m) === 1) {
                    $label = self::clean(self::decode($m[1]));
                    if ($label !== '') {
                        if (! in_array($label, $out, true)) {
                            $out[] = $label;
                        }

                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Wiersze opisu w postaci „ETYKIETA: wartość” z etykietą wielkimi literami (MATERIAŁ, NORMA, KAT. BEZPIECZEŃSTWA) —
     * dosłownie, do tabelki karty.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function labelledLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^([\p{Lu}][\p{Lu}\d .\/()-]{1,40}?)\s*:\s*(.+)$/u', trim($line), $m) !== 1) {
                continue;
            }
            $name = self::clean($m[1]);
            $value = self::clean($m[2]);
            if ($name !== '' && $value !== '' && mb_strtoupper($name) === $name) {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Wiersze opisu z normą obuwia połączoną z kategorią bezpieczeństwa (FOOTWEAR_NORM_ROW) w miejscu wiersza NORMA —
     * tylko przy jednej normie obuwia i pasującej klasie; inaczej wiersze bez zmian.
     *
     * @param  list<array{name: string, value: string}>  $rows
     * @return list<array{name: string, value: string}>
     */
    public static function footwearNorm(array $rows): array
    {
        $norms = array_keys(array_filter($rows, static fn (array $r): bool => $r['name'] === self::NORM_ROW));
        $classes = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === self::FOOTWEAR_CATEGORY_ROW));
        if (count($norms) !== 1 || count($classes) !== 1
            || preg_match('/^EN ISO 2034([57])(?::(?:19|20)\d{2})?$/u', $rows[$norms[0]]['value'], $norm) !== 1
            || preg_match('/^(?:'.BhpAttributeNormalizer::FOOTWEAR_CLASS.')(?![\p{L}\d])/u', $classes[0]['value'], $class) !== 1
            || ($norm[1] === '5') !== str_starts_with($class[0], 'S')) {
            return $rows;
        }
        $rows[$norms[0]] = ['name' => self::FOOTWEAR_NORM_ROW, 'value' => $rows[$norms[0]]['value'].' '.$classes[0]['value']];

        return $rows;
    }

    /**
     * Model pozycji: litera i 5 cyfr na początku symbolu (reguła użytkownika); kod spoza wzorca — kod bez końcówki
     * równej rozmiarowi z nazwy (LPPOMA39 przy „39” → LPPOMA; reszta musi mieć literę i co najmniej 4 znaki), inaczej
     * cały kod.
     *
     * @param  list<string>  $sizes
     * @return array{0: string, 1: bool}
     */
    private static function model(string $symbol, array $sizes): array
    {
        if (preg_match('/^([A-Z]\d{5})/', $symbol, $m) === 1) {
            return [$m[1], true];
        }
        foreach ($sizes as $size) {
            // pierwszy człon rozmiaru: „XL(188/98-102)” → XL, „2XL” → 2XL
            if (preg_match('/^([0-9A-Z]{1,4})/u', strtoupper($size), $m) !== 1) {
                continue;
            }
            $stem = substr($symbol, 0, -strlen($m[1]));
            if (str_ends_with($symbol, $m[1]) && strlen($stem) >= 4 && preg_match('/[A-Z]/', $stem) === 1) {
                return [$stem, false];
            }
        }

        return [$symbol, false];
    }

    /**
     * Cała lista Lahti Pro (po symbolu, strony po $pageSize); niespójna (licznik zmienił się w trakcie, pozycja dwa razy,
     * pozycji mniej niż licznik) — jedno ponowne pobranie od początku.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<array{id: int, title: string}>, 2: array<string, string>}
     */
    private function listItems(): array
    {
        $filter = $this->brandFilter();
        try {
            return [...$this->scanList($filter), $filter];
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return [...$this->scanList($filter), $filter];
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista wyrobów '.ProfixB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Filtr marki: parametr „Marka” i wartość „LAHTI PRO” z filtrów portalu, razem z licznikiem całego katalogu
     * (strażnik: lista z filtrem musi być mniejsza).
     *
     * @return array<string, string>
     */
    private function brandFilter(): array
    {
        $json = $this->client->grid(['page' => 1, 'onpage' => 1, 'cats' => 'true'], 'filtry katalogu');
        foreach (is_array($json['params'] ?? null) ? $json['params'] : [] as $param) {
            if (! is_array($param) || mb_strtolower(self::clean((string) ($param['title'] ?? ''))) !== mb_strtolower(self::BRAND_PARAM)) {
                continue;
            }
            foreach (is_array($param['values'] ?? null) ? $param['values'] : [] as $value) {
                if (is_array($value) && mb_strtoupper(self::clean((string) ($value['name'] ?? ''))) === self::BRAND_VALUE
                    && is_int($param['id'] ?? null) && is_int($value['id'] ?? null)) {
                    return [
                        'param_'.$param['id'] => (string) $value['id'],
                        '_all' => (string) (is_int($json['total'] ?? null) ? $json['total'] : 0),
                    ];
                }
            }
        }

        throw new RuntimeException('Portal '.ProfixB2bClient::HOST.' nie ma filtra „'.self::BRAND_PARAM.': '.self::BRAND_VALUE.'” — zmiana portalu albo konta; przebieg przerwany');
    }

    /**
     * @param  array<string, string>  $filter
     * @return array{0: array<string, array<string, mixed>>, 1: list<array{id: int, title: string}>}
     */
    private function scanList(array $filter): array
    {
        $all = (int) $filter['_all'];
        unset($filter['_all']);
        $started = microtime(true);
        $items = [];
        $categories = [];
        $total = null;
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.ProfixB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->grid([
                'page' => $page, 'onpage' => $this->pageSize, 'cats' => 'true', 'sort_field' => 'symbol', 'sort_order' => 'asc', ...$filter,
            ], 'lista Lahti Pro, strona '.$page);
            $pageTotal = is_int($json['total'] ?? null) ? $json['total'] : -1;
            if ($pageTotal < 0 || ! is_array($json['data'] ?? null)) {
                throw new RuntimeException('Lista '.ProfixB2bClient::HOST.' bez licznika albo pozycji (strona '.$page.') — zmiana portalu?');
            }
            if ($total === null) {
                $total = $pageTotal;
                if ($total === 0) {
                    throw new RuntimeException('Lista '.self::BRAND_VALUE.' w '.ProfixB2bClient::HOST.' pusta — filtr marki nic nie zwrócił');
                }
                if ($all > 0 && $total >= $all) {
                    throw new RuntimeException('Filtr marki '.self::BRAND_VALUE.' nie zadziałał ('.$total.' z '.$all.' pozycji katalogu) — przebieg przerwany');
                }
                $pages = min(max(1, (int) ceil($total / $this->pageSize)), self::MAX_PAGES);
                $categories = self::categories($json['categories'] ?? []);
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba pozycji zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }
            foreach ($json['data'] as $item) {
                $row = is_array($item) ? self::item($item) : null;
                if ($row === null) {
                    throw new RuntimeException('Lista '.ProfixB2bClient::HOST.', strona '.$page.': pozycja bez symbolu albo nazwy — zmiana portalu?');
                }
                if (isset($items[$row['symbol']])) {
                    throw new RuntimeException('pozycja '.$row['symbol'].' dwa razy na liście', self::INCONSISTENT);
                }
                $items[$row['symbol']] = $row;
            }
            if ($page % 5 === 0) {
                $this->progress('Lista Profix: strona '.$page.'/'.$pages.' ('.count($items).' z '.$total.' pozycji)');
            }
        }
        if (count($items) < (int) $total) {
            throw new RuntimeException('na liście '.count($items).' z '.$total.' pozycji', self::INCONSISTENT);
        }

        return [$items, $categories];
    }

    /**
     * Ścieżki kategorii pozycji („Artykuły BHP > Rękawice”): podkategorie każdej kategorii marki, a kategoria bez
     * podkategorii — sama. Kategoria jest tylko opisem karty — błąd jej listy nie przerywa przebiegu (karty bez kategorii,
     * w dzienniku).
     *
     * @param  list<array{id: int, title: string}>  $categories
     * @param  array<string, string>  $filter
     * @return array<string, string> symbol → ścieżka
     */
    private function categoryPaths(array $categories, array $filter): array
    {
        unset($filter['_all']);
        $paths = [];
        foreach ($categories as $category) {
            try {
                $json = $this->client->grid(['page' => 1, 'onpage' => 1, 'cats' => 'true', 'category' => $category['id'], ...$filter], 'kategoria '.$category['title']);
                $subs = self::categories($json['sub_categories'] ?? []);
                $leaves = $subs === []
                    ? [['id' => $category['id'], 'path' => $category['title']]]
                    : array_map(static fn (array $s): array => ['id' => $s['id'], 'path' => $category['title'].' > '.$s['title']], $subs);
                foreach ($leaves as $leaf) {
                    $pages = 1;
                    for ($page = 1; $page <= $pages && $page <= self::MAX_PAGES; $page++) {
                        $list = $this->client->grid([
                            'page' => $page, 'onpage' => $this->pageSize, 'cats' => 'true', 'sort_field' => 'symbol', 'sort_order' => 'asc',
                            'category' => $leaf['id'], ...$filter,
                        ], 'kategoria '.$leaf['path'].', strona '.$page);
                        $pages = max(1, (int) ceil((int) ($list['total'] ?? 0) / $this->pageSize));
                        foreach (is_array($list['data'] ?? null) ? $list['data'] : [] as $item) {
                            $symbol = is_array($item) ? strtoupper(self::clean((string) ($item['symbol'] ?? ''))) : '';
                            if ($symbol !== '') {
                                $paths[$symbol] ??= $leaf['path'];
                            }
                        }
                    }
                }
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $this->summary[] = 'Kategoria '.$category['title'].' nieodczytana ('.$e->getMessage().') — jej karty bez kategorii';
            }
        }
        $this->progress('Kategorie Profix: '.count($categories).', pozycji z kategorią: '.count($paths));

        return $paths;
    }

    /**
     * Pozycje w karty (klucz analyse()), w kolejności symboli. Pozycja z inną marką w nazwie — poza kartami.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, string>  $paths
     */
    private function group(array $items, array $paths): void
    {
        $this->cards = [];
        $this->modelCards = [];
        ksort($items, SORT_STRING);
        foreach ($items as $symbol => $item) {
            $tail = mb_strtoupper(trim((string) (array_slice(explode(',', $item['name']), -1)[0] ?? '')));
            if (in_array($tail, self::OTHER_BRAND_TAILS, true)) {
                $this->otherBrandNames[] = $symbol.' ('.$item['name'].')';

                continue;
            }
            $parsed = self::analyse((string) $symbol, $item['name']);
            $key = $parsed['key'];
            if (! isset($this->cards[$key])) {
                $this->cards[$key] = [
                    'model' => $parsed['model'],
                    'standard' => $parsed['standard'],
                    'members' => [],
                    'paths' => [],
                ];
                $this->modelCards[$parsed['model']] = ($this->modelCards[$parsed['model']] ?? 0) + 1;
                if ($this->modelCards[$parsed['model']] === 2) {
                    $this->splitModels[] = $parsed['model'];
                }
            }
            $this->cards[$key]['members'][] = [...$item, 'size' => $parsed['size'], 'colour' => $parsed['colour'], 'colour_key' => $parsed['colour_key'], 'name_without_size' => $parsed['name_without_size']];
            if (isset($paths[$symbol])) {
                $this->cards[$key]['paths'][$paths[$symbol]] = ($this->cards[$key]['paths'][$paths[$symbol]] ?? 0) + 1;
            }
        }
        // Kolejność: model, w modelu najpierw karta z największą liczbą pozycji. Gdy karta modelu rozpadnie się między
        // przebiegami (nowe opakowanie, zmieniona nazwa), dotychczasową kartę zajmie część z większością jej pozycji
        // (B2bCatalogSync::resolveGroupCard: powiązanie pozycji wiodącej, potem większość powiązań), a odłączona mniejsza
        // część dostanie nową kartę — nie odwrotnie.
        uasort($this->cards, static fn (array $a, array $b): int => [$a['model'], -count($a['members']), $a['members'][0]['symbol']]
            <=> [$b['model'], -count($b['members']), $b['members'][0]['symbol']]);
    }

    /**
     * Karta z pozycjami do zamówienia; bez ceny konta, z obcą marką albo nieodczytanymi szczegółami — pominięta z powodem.
     *
     * @param  array<string, mixed>  $card
     */
    private function productFor(array $card): B2bRemoteProduct
    {
        $positions = [];
        foreach ($card['members'] as $member) {
            if ($member['net'] === null) {
                $this->unpriced[] = $member['symbol'];
            } else {
                $positions[] = $member;
            }
        }
        // SKU: kod modelu, gdy model ma jedną kartę; inaczej (i przy kodzie spoza wzorca) symbol pozycji wiodącej
        $ownModel = $card['standard'] && ($this->modelCards[$card['model']] ?? 1) === 1;
        if ($positions === []) {
            $first = $card['members'][0];

            return $this->skipped($card['members'], $ownModel ? $card['model'] : $first['symbol'], 'żadna pozycja wyrobu nie ma ceny konta w PLN');
        }
        $lead = $positions[0];
        $sku = $ownModel ? $card['model'] : $lead['symbol'];

        try {
            $detail = $this->client->product($lead['symbol']);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->unreadable[] = $lead['symbol'].' ('.$e->getMessage().')';

            return $this->skipped($card['members'], $sku, 'szczegóły pozycji nieodczytane: '.$e->getMessage());
        }
        $brands = self::producerValues($detail);
        if ($brands !== [] && ! in_array(self::BRAND_VALUE, array_map('mb_strtoupper', $brands), true)) {
            $this->foreign[] = $lead['symbol'].' ('.implode(', ', $brands).')';

            return $this->skipped($card['members'], $sku, 'marka w portalu: '.implode(', ', $brands).' — konto zamawia tylko '.self::BRAND);
        }
        $this->lahtiCards++;

        $colourKeys = array_values(array_unique(array_filter(array_column($positions, 'colour_key'), static fn (string $c): bool => $c !== '')));
        $manyColours = count($colourKeys) > 1;
        $members = [];
        $identifiers = [];
        if ($card['standard']) {
            $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $card['model'], field: 'Symbol (kod modelu: litera i 5 cyfr)');
        }
        $usedLabels = [];
        $statuses = [];
        $sizeNames = [];
        $colourNames = [];
        $multiples = [];
        $units = [];
        $cheapest = null;
        foreach ($positions as $item) {
            $colourLabel = $item['colour'];
            $label = $item['size'];
            if ($manyColours) {
                $label = ($colourLabel !== '' ? $colourLabel : $item['symbol']).($label !== null ? ' / '.$label : '');
            }
            if ($label !== null && isset($usedLabels[$label])) {
                $this->duplicateSizes[] = $item['symbol'];
                $label .= ' ('.$item['symbol'].')';
            }
            if ($label !== null) {
                $usedLabels[$label] = true;
            }
            $price = new B2bRemotePrice(net: $item['net'], base: $item['base'], currency: 'PLN');
            $availability = self::availability($item);
            $members[] = array_filter([
                'remote_id' => $item['symbol'],
                'sku' => $item['symbol'],
                'name' => $item['name'],
                'availability' => $availability,
                'size' => $label,
                'price' => $price,
            ], static fn (mixed $v): bool => $v !== null);
            $statuses[$availability][] = $label ?? $item['symbol'];
            $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $item['symbol'], remoteId: $item['symbol'], label: $label, field: 'symbol');
            if ($item['ean'] !== '') {
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $item['ean'], remoteId: $item['symbol'], label: $label, field: 'ean');
            }
            if ($item['size'] !== null) {
                $sizeNames[$item['size']] = true;
            }
            if ($colourLabel !== '') {
                $colourNames[$item['colour_key']] ??= $colourLabel;
            }
            $multiples[(string) $item['multiple']] = $item['multiple'];
            $units[$item['unit']] = true;
            if ($item['promotion_below']) {
                $this->promotions[] = $item['symbol'];
            }
            if ($cheapest === null || $price->net < $cheapest->net) {
                $cheapest = $price;
            }
        }
        $this->cardsDone++;
        foreach ($members as $member) {
            if ($member['price']->base !== null && $member['price']->net < $member['price']->base - 0.004) {
                $this->discountedCards++;

                break;
            }
        }
        if (count(array_unique(array_map(static fn (array $m): string => (string) $m['price']->net, $members))) > 1) {
            $this->multiPrice++;
        }

        $html = (string) ($detail['content'] ?? '');
        $description = self::htmlText($html);
        if ($description === '') {
            $this->withoutDescription[] = $sku;
        }
        $images = self::galleryUrls($detail);
        if ($manyColours) {
            $images = $this->colourImages($positions, $lead['symbol'], $images, $description, $sku);
        }

        $unitKeys = array_keys($units);
        $unit = count($unitKeys) === 1 ? (self::UNITS[$unitKeys[0]] ?? $unitKeys[0]) : null;
        $order = count($multiples) > 1
            ? new B2bOrderQuantity(min: null, step: null, unit: $unit, varies: true)
            : self::orderQuantity((float) array_values($multiples)[0], $unit);

        $single = count($members) === 1 && ! isset($members[0]['size']);
        // jedna pozycja — nazwa dosłownie (z rozmiarem: karta to ten jeden rozmiar)
        $cardName = count($positions) > 1 ? self::cardName($lead, $manyColours, $card['model']) : $lead['name'];
        $summary = [];
        if ($colourNames !== []) {
            $summary[] = ($manyColours ? 'Kolory: ' : 'Kolor: ').implode(', ', $colourNames);
        }
        if ($sizeNames !== []) {
            $summary[] = 'rozmiary: '.implode(', ', array_keys($sizeNames));
        }

        return new B2bRemoteProduct(
            remoteId: $lead['symbol'],
            sku: $sku,
            name: $lead['name'],
            category: self::category($card['paths']),
            sourceUrl: ProfixB2bClient::BASE.'/app/catalog/'.rawurlencode($lead['symbol']),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(net: $cheapest->net, base: $cheapest->base, currency: 'PLN', order: $order),
                'description' => mb_substr($description, 0, 10000),
                'fields' => self::fields($card, $detail, $description, $html),
                'documents' => self::documentList($detail),
                'images' => $images,
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: $summary === [] ? '' : ucfirst(implode('; ', $summary)),
            members: $single ? [] : $members,
            identifiers: $identifiers,
            cardName: $cardName !== $lead['name'] ? $cardName : null,
        );
    }

    /**
     * Pierwsze zdjęcie każdego koloru (karta wielokolorowa, jak przy łączeniu kolorów innych łączników): pozycja wiodąca
     * — z jej galerii, pozostałe kolory — ze szczegółów pierwszej pozycji koloru. Błąd szczegółów koloru — kolor bez zdjęcia.
     *
     * Opis koloru inny niż opis pozycji wiodącej (kolor zmienia parametry) — karta w dzienniku.
     *
     * @param  list<array<string, mixed>>  $positions
     * @param  list<string>  $leadImages
     * @return list<string>
     */
    private function colourImages(array $positions, string $leadSymbol, array $leadImages, string $leadDescription, string $sku): array
    {
        $firstOfColour = [];
        foreach ($positions as $item) {
            $firstOfColour[$item['colour_key']] ??= $item['symbol'];
        }
        $images = [];
        foreach ($firstOfColour as $symbol) {
            if ($symbol === $leadSymbol) {
                $url = $leadImages[0] ?? null;
            } else {
                try {
                    $detail = $this->client->product($symbol);
                    $url = self::galleryUrls($detail)[0] ?? null;
                    if (self::htmlText((string) ($detail['content'] ?? '')) !== $leadDescription && ! in_array($sku, $this->colourDetailsDiffer, true)) {
                        $this->colourDetailsDiffer[] = $sku;
                    }
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException) {
                    $url = null;
                }
            }
            if ($url !== null && ! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return array_slice($images, 0, self::MAX_IMAGES);
    }

    /**
     * Nazwa karty kilku pozycji: nazwa pozycji wiodącej bez rozmiaru i bez kodów modelu w nazwie („L211907P” to kod
     * pary jednego rozmiaru), przy kilku kolorach — także bez słów koloru.
     *
     * @param  array<string, mixed>  $lead
     */
    private static function cardName(array $lead, bool $manyColours, string $model): string
    {
        $name = self::clean((string) preg_replace('/\s*\b'.preg_quote($model, '/').'[0-9A-Z]{0,4}\b/u', '', $lead['name_without_size']));
        if ($manyColours) {
            $segments = [];
            foreach (self::segments($name) as $segment) {
                $words = array_filter(preg_split('/\s+/u', $segment) ?: [], static fn (string $w): bool => $w !== '' && ! self::isColour($w));
                if ($words !== []) {
                    $segments[] = implode(' ', $words);
                }
            }
            $name = implode(', ', $segments);
        }
        $name = trim((string) preg_replace('/\s*,(\s*,)+/u', ',', $name), ' ,');

        return $name !== '' ? $name : $lead['name'];
    }

    /**
     * Wiersze tabelki karty: kod modelu, wiersze „ETYKIETA: wartość” z opisu (MATERIAŁ, NORMA…), parametry portalu poza
     * rozmiarem, marką i wagą rozmiaru, kraj pochodzenia i kod CN (gdy wspólne dla pozycji), opakowanie zbiorcze
     * i piktogramy — dosłownie.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $detail
     * @return list<array{name: string, value: string}>
     */
    private static function fields(array $card, array $detail, string $description, string $html): array
    {
        $fields = [];
        if ($card['standard']) {
            $fields[] = ['name' => 'Kod modelu', 'value' => $card['model']];
        }
        foreach (self::footwearNorm(self::labelledLines($description)) as $row) {
            $fields[] = $row;
        }
        foreach (is_array($detail['params'] ?? null) ? $detail['params'] : [] as $param) {
            if (! is_array($param)) {
                continue;
            }
            $name = self::clean((string) ($param['name'] ?? ''));
            $values = array_values(array_filter(array_map(
                static fn (mixed $v): string => is_scalar($v) ? self::clean((string) $v) : '',
                is_array($param['values'] ?? null) ? $param['values'] : [],
            ), static fn (string $v): bool => $v !== '' && $v !== '-'));
            if ($name === '' || $values === [] || in_array(mb_strtolower($name), self::ITEM_PARAMS, true)) {
                continue;
            }
            $fields[] = ['name' => $name, 'value' => implode('; ', $values)];
        }
        foreach (['country' => 'Kraj pochodzenia', 'customs' => 'Kod CN'] as $key => $name) {
            $values = array_values(array_unique(array_filter(array_column($card['members'], $key), static fn (string $v): bool => $v !== '')));
            if (count($values) === 1) {
                $fields[] = ['name' => $name, 'value' => $values[0]];
            }
        }
        $boxes = array_values(array_unique(array_map(static fn (array $m): string => $m['box'] !== null ? $m['box'].' '.$m['unit'] : '', $card['members'])));
        if (count($boxes) === 1 && $boxes[0] !== '') {
            $fields[] = ['name' => 'Opakowanie zbiorcze', 'value' => $boxes[0]];
        }
        $pictograms = self::pictograms($html);
        if ($pictograms !== []) {
            $fields[] = ['name' => 'Piktogramy', 'value' => implode('; ', $pictograms)];
        }

        return $fields;
    }

    /**
     * Pliki pozycji wiodącej: karta produktu, deklaracja zgodności, instrukcja (nieznany rodzaj — „inny”, z kluczem
     * portalu w nazwie); ten sam plik raz.
     *
     * @param  array<string, mixed>  $detail
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentList(array $detail): array
    {
        $out = [];
        foreach (is_array($detail['documents'] ?? null) ? $detail['documents'] : [] as $document) {
            if (! is_array($document)) {
                continue;
            }
            $url = ProfixB2bClient::fileUrl((string) ($document['path'] ?? ''));
            if ($url === null || isset($out[$url])) {
                continue;
            }
            // nazwy plików portalu nic nie mówią („curves.pdf”, „v5.pdf”) — tytuł to etykieta rodzaju, jak w portalu
            $key = self::clean((string) ($document['name'] ?? ''));
            [$title, $kind] = self::DOCUMENTS[$key] ?? [$key !== '' ? $key : basename((string) parse_url($url, PHP_URL_PATH)), ProductDocument::KIND_OTHER];
            $out[$url] = ['title' => $title, 'url' => $url, 'kind' => $kind];
        }

        return array_values($out);
    }

    /**
     * Oryginały zdjęć z galerii szczegółów (w kolejności portalu), najwyżej MAX_IMAGES.
     *
     * @param  array<string, mixed>  $detail
     * @return list<string>
     */
    private static function galleryUrls(array $detail): array
    {
        $urls = [];
        foreach (['gallery', 'thumbnail'] as $key) {
            foreach (is_array($detail[$key] ?? null) ? $detail[$key] : [] as $url) {
                $original = is_string($url) ? self::imageUrl($url) : null;
                if ($original !== null && ! in_array($original, $urls, true)) {
                    $urls[] = $original;
                }
            }
            if ($urls !== []) {
                break;
            }
        }

        return array_slice($urls, 0, self::MAX_IMAGES);
    }

    /**
     * Wartości parametru producenta (isProducer) ze szczegółów; [] = szczegóły go nie podają.
     *
     * @param  array<string, mixed>  $detail
     * @return list<string>
     */
    private static function producerValues(array $detail): array
    {
        foreach (is_array($detail['params'] ?? null) ? $detail['params'] : [] as $param) {
            if (is_array($param) && ($param['isProducer'] ?? false) === true) {
                return array_values(array_filter(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? self::clean((string) $v) : '',
                    is_array($param['values'] ?? null) ? $param['values'] : [],
                ), static fn (string $v): bool => $v !== ''));
            }
        }

        return [];
    }

    /**
     * Pozycja listy — tylko pola, których łącznik używa (lista ma ok. 3,9 tys. pozycji, przebieg na serwerze 128 MB).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function item(array $item): ?array
    {
        $symbol = strtoupper(self::clean((string) ($item['symbol'] ?? '')));
        $name = self::clean((string) ($item['name'] ?? ''));
        if ($symbol === '' || $name === '') {
            return null;
        }
        $net = self::amount($item['pricePromo'] ?? null);
        $base = self::amount($item['price'] ?? null);
        $promotionBelow = false;
        if ($net !== null) {
            foreach (is_array($item['allPromotions'] ?? null) ? $item['allPromotions'] : [] as $promotion) {
                if (is_array($promotion) && ($promotion['set'] ?? false) !== true) {
                    $promo = self::amount($promotion['price'] ?? null);
                    if ($promo !== null && $promo < $net - 0.004) {
                        $promotionBelow = true;

                        break;
                    }
                }
            }
        }
        $multiple = $item['multiple'] ?? 1;
        $box = $item['box'] ?? null;

        return [
            'symbol' => $symbol,
            'name' => $name,
            'ean' => self::clean((string) ($item['ean'] ?? '')),
            'net' => $net,
            'base' => $net !== null ? $base : null,
            'unit' => strtoupper(self::clean((string) ($item['unit'] ?? ''))),
            'multiple' => (is_int($multiple) || is_float($multiple)) && $multiple > 1 ? (float) $multiple : 1.0,
            'box' => is_int($box) || (is_string($box) && ctype_digit($box)) ? (string) $box : null,
            'stock' => ($item['stock'] ?? false) === true,
            'stock_level' => strtolower(self::clean((string) ($item['stockLevel'] ?? ''))),
            'country' => self::clean((string) ($item['countryOrigin'] ?? '')),
            'customs' => self::clean((string) ($item['customsCode'] ?? '')),
            'promotion_below' => $promotionBelow,
        ];
    }

    /**
     * Cena w PLN z pary portalu [kwota, waluta]; inna waluta, zero albo brak — null.
     */
    private static function amount(mixed $pair): ?float
    {
        if (! is_array($pair) || strtoupper(trim((string) ($pair[1] ?? ''))) !== 'PLN') {
            return null;
        }
        $value = $pair[0] ?? null;
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        return (is_int($value) || is_float($value)) && $value > 0 ? round((float) $value, 2) : null;
    }

    /**
     * @param  mixed  $list  categories / sub_categories z odpowiedzi
     * @return list<array{id: int, title: string}>
     */
    private static function categories(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $category) {
            if (is_array($category) && is_int($category['id'] ?? null) && (int) ($category['cnt'] ?? 0) > 0) {
                $title = self::clean((string) ($category['title'] ?? ''));
                if ($title !== '') {
                    $out[] = ['id' => $category['id'], 'title' => $title];
                }
            }
        }

        return $out;
    }

    /**
     * Kategoria karty: najczęstsza ścieżka jej pozycji, przy remisie pierwsza; null = brak.
     *
     * @param  array<string, int>  $paths
     */
    private static function category(array $paths): ?string
    {
        if ($paths === []) {
            return null;
        }
        arsort($paths);

        return (string) array_key_first($paths);
    }

    /** Dostępność pozycji: „Na stanie (dużo)” / „Brak na stanie”. */
    private static function availability(array $item): string
    {
        if (! $item['stock']) {
            return 'Brak na stanie';
        }
        $level = self::STOCK_LEVELS[$item['stock_level']] ?? null;

        return 'Na stanie'.($level !== null ? ' ('.$level.')' : '');
    }

    /**
     * Jeden stan dla wszystkich pozycji — dosłownie; różne — „Na stanie (dużo): S, M; Brak na stanie: XL”.
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

    /** Sprzedaż w wielokrotnościach (multiple portalu): min i krok; 1 = bez ograniczeń. */
    private static function orderQuantity(float $multiple, ?string $unit): B2bOrderQuantity
    {
        return $multiple > 1 ? new B2bOrderQuantity(min: $multiple, step: $multiple, unit: $unit) : new B2bOrderQuantity(min: 1.0, step: null, unit: $unit);
    }

    /**
     * Karta, której nie da się zapisać w tym przebiegu — widoczna jako pominięta z powodem. Niesie wszystkie swoje pozycje:
     * pozycja pominięta nie może wyglądać na zniknięcie rozmiaru (B2bCatalogSync sprząta wiersze rozmiarów tylko kart,
     * których żadna pozycja nie była pominięta).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function skipped(array $items, string $sku, string $reason): B2bRemoteProduct
    {
        $lead = $items[0];

        return new B2bRemoteProduct(
            remoteId: $lead['symbol'],
            sku: $sku,
            name: $lead['name'],
            sourceUrl: ProfixB2bClient::BASE.'/app/catalog/'.rawurlencode($lead['symbol']),
            raw: ['status' => 'skipped', 'reason' => $reason],
            members: count($items) > 1 ? array_map(
                static fn (array $item): array => ['remote_id' => $item['symbol'], 'sku' => $item['symbol'], 'name' => $item['name']],
                $items,
            ) : [],
        );
    }

    /**
     * Człony nazwy rozdzielone przecinkiem, bez pustych.
     *
     * @return list<string>
     */
    private static function segments(string $text): array
    {
        return array_values(array_filter(array_map(static fn (string $s): string => self::clean($s), explode(',', $text)), static fn (string $s): bool => $s !== ''));
    }

    /** Encje HTML rozwinięte, także podwójnie zakodowane („&amp;oacute;” → „ó”). */
    private static function decode(string $text): string
    {
        for ($i = 0; $i < 2; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
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

    /** Tekst z portalu: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
