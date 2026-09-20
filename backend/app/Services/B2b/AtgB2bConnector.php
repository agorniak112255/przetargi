<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * atg-glovesolutions.com/pl — publiczna witryna producenta rękawic ATG. Łącznik jest źródłem treści, nie cen
 * (B2bContentOnlySite): witryna cen nie podaje wcale, a karty katalogu powstają z cennika dostawcy.
 *
 * Kartę wiąże z katalogiem numer artykułu ze danych strukturalnych (schema.org `sku`, np. „34-8753”) — ten sam
 * numer jest kodem pozycji w cennikach ATG i u dystrybutorów.
 *
 * PO CO ta witryna, skoro karty mają już opisy: poziomy EN 388 brały się z opisów wzbogacanych ze sklepów, bo
 * w hierarchii źródeł opisu sklep stoi nad witryną producenta. Sprawdzenie 54 kart ATG (20.09.2026) wobec kart
 * producenta: 25 kart miało kod EN 388 inny niż producent — przekręcony, nieczytelny albo żaden. Stąd
 * B2bNormFactSource: pary „norma → poziom” idą do products.manufacturer_norms i to one wygrywają przy
 * dopasowaniu do wymagania przetargu (App\Support\ManufacturerNormFacts).
 *
 * Co bierzemy skąd na karcie:
 * - dane strukturalne (schema.org Product): numer artykułu, nazwa, zdjęcie wyrobu, normy z poziomami. To umowa
 *   maszynowa, więc przetrwa przebudowę szablonu — a normy i tożsamość wyrobu muszą być pewne;
 * - znaczniki strony: proza opisowa (akapit wstępu i bloki cech) oraz tabelka „Informacje o produkcie”
 *   i lista „Funkcje” — dane tabelaryczne idą do product_shop_cards, nie do opisu.
 *
 * Tabelki nie wklejamy do opisu i rozmiarów nie przepisujemy na listę wersji: witryna podaje je jako zakres
 * („6 (XS) - 12 (3XL)”), a rozpisanie zakresu na pojedyncze rozmiary dopisywałoby wersje, których dostawca
 * może nie mieć. Zakres zostaje wierszem tabelki, dosłownie.
 */
final class AtgB2bConnector implements B2bConnector, B2bContentOnlySite, B2bDocumentSource, B2bImageGallery, B2bManufacturerSite, B2bNormFactSource, B2bPublicSite, B2bRunSummaryAware, B2bShopFieldSource
{
    /** Karta artykułu: /pl/products/{rodzina}/{model}/{numer}. Dwa segmenty to strona rodziny, nie artykuł. */
    private const PRODUCT_PATH = '#^/'.AtgB2bClient::LANGUAGE.'/products/[^/]+/[^/]+/[^/]+$#';

    /** Nagłówki bloków na karcie (wersja polska witryny) — po nich poznajemy tabelkę i listę cech. */
    private const HEADING_SPECS = 'Informacje o produkcie';

    private const HEADING_FEATURES = 'Funkcje';

    private const SECTION_SPECS = 'Informacje o produkcie';

    private const SECTION_FEATURES = 'Funkcje';

    private const SECTION_NORMS = 'Normy';

    /** Etykieta wiersza dla normy podanej bez poziomu — sama zgodność z normą. */
    private const NORM_ROW_LABEL = 'Norma';

    /**
     * Rodzaje plików karty. Adres pliku to sam identyfikator (/download/{uuid}), więc nie ma w nim nazwy —
     * rozpoznajemy dwoma drogami, w tej kolejności:
     * 1. podpis odnośnika na stronie (zawsze pod ręką, zero dodatkowych zapytań),
     * 2. nazwa pliku z nagłówka pobrania, gdy podpisu nie znamy — jest po angielsku i taka sama we wszystkich
     *    23 wersjach witryny („product_data_sheet_34-8753_pl.pdf”), więc przetrwa zmianę tłumaczenia.
     *
     * `label` dopasowujemy fragmentami po zdjęciu ogonków i wielkich liter, bo witryna ma na karcie literówkę
     * („Karta charakterystki produktu”) i trzyma dwie bardzo podobne nazwy: karta charakterystyki (MSDS)
     * i karta charakterystyki PRODUKTU (parametry techniczne). Wzorzec z „produktu” musi stać wyżej.
     *
     * Kolejność listy jest zarazem kolejnością załączników przy karcie i decyduje, z którego pliku bierzemy tekst
     * do indeksu wyszukiwania (B2bCatalogSync::DESCRIPTION_SOURCE_KINDS bierze pierwszy) — dlatego karta
     * techniczna z parametrami stoi przed katalogową.
     *
     * @var list<array{labels: list<string>, files: list<string>, title: string, kind: string}>
     */
    private const DOCUMENT_KINDS = [
        [
            'labels' => ['charakterystki produktu', 'charakterystyki produktu', 'karta produktu techniczna'],
            'files' => ['product_data_sheet'],
            'title' => 'Karta techniczna produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
        ],
        [
            'labels' => ['karte produktu', 'karta produktu', 'karty produktu'],
            'files' => ['catalog'],
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
        ],
        [
            'labels' => ['deklaracja zgodnosci ue', 'deklaracji zgodnosci ue'],
            'files' => ['eu_declaration'],
            'title' => 'Deklaracja zgodności UE',
            'kind' => ProductDocument::KIND_CERTIFICATE,
        ],
        [
            'labels' => ['ukca'],
            'files' => ['ukca_declaration'],
            'title' => 'Deklaracja zgodności UKCA',
            'kind' => ProductDocument::KIND_CERTIFICATE,
        ],
        // Karty charakterystyki (MSDS) projekt nie ma jako osobnego rodzaju — zostaje własna nazwa i „inne”,
        // żeby nie udawała karty technicznej, z której bierzemy tekst do indeksu.
        [
            'labels' => ['karta charakterystyki', 'karta bezpieczenstwa'],
            'files' => ['material_safety_data_sheet', 'safety_data_sheet'],
            'title' => 'Karta charakterystyki',
            'kind' => ProductDocument::KIND_OTHER,
        ],
        [
            'labels' => ['instrukcje dotyczace prania', 'instrukcja prania'],
            'files' => ['laundry_instructions'],
            'title' => 'Instrukcja prania',
            'kind' => ProductDocument::KIND_MANUAL,
        ],
    ];

    private int $total = 0;

    /** @var list<string> numery artykułów, przy których karta nie podała norm */
    private array $withoutNorms = [];

    /** @var list<string> numery artykułów, przy których karta nie podała tabelki parametrów */
    private array $withoutSpecs = [];

    public function __construct(
        private readonly AtgB2bClient $client,
    ) {}

    public static function key(): string
    {
        return 'atg';
    }

    public static function label(): string
    {
        return 'ATG';
    }

    public static function host(): string
    {
        return AtgB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new AtgB2bClient($delayMs));
    }

    /** Witryna jest publiczna — nie ma się gdzie logować. */
    public function login(): void {}

    public function products(): iterable
    {
        $urls = $this->productUrls();
        $this->total = count($urls);

        foreach ($urls as $url) {
            yield $this->productFor($url);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    /** Witryna należy do tej marki — tylko jej karty wolno nadpisać opisem i normami stąd. */
    public static function ownBrand(): string
    {
        return 'ATG';
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'ATG';
    }

    /**
     * Witryna producenta nie podaje żadnych cen — to znaczenie B2bContentOnlySite i normalny stan, nie powód
     * pominięcia pozycji. Kartę, której nie udało się odczytać, pomija dopiero ten wyjątek: synchronizacja pyta
     * o cenę jako pierwsza, więc to pierwsze miejsce, w którym można podać powód.
     */
    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'strona produktu nieodczytana'));
        }

        return null;
    }

    /**
     * Proza karty: akapit wstępu i bloki cech (nagłówek bloku razem z jego akapitem), w kolejności ze strony.
     * Parametrów z tabelki nie dopisujemy — idą do product_shop_cards. Karta bez prozy daje pusty opis,
     * który niczego nie nadpisuje.
     */
    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Wiersze karty u producenta: tabelka „Informacje o produkcie”, lista „Funkcje” i normy z poziomami.
     * Wartości dosłownie ze strony — jednostek, zakresów rozmiarów ani oznaczeń norm nie normalizujemy.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        foreach ($raw['specs'] ?? [] as $row) {
            $fields[] = new B2bRemoteShopField(self::SECTION_SPECS, (string) $row['name'], (string) $row['value']);
        }
        // Cechy są na karcie listą bez etykiet (ikona i podpis) — powtórzona etykieta jest w karcie dozwolona.
        foreach ($raw['features'] ?? [] as $feature) {
            $fields[] = new B2bRemoteShopField(self::SECTION_FEATURES, self::SECTION_FEATURES, (string) $feature);
        }
        // Normy z poziomem: oznaczenie jest etykietą, poziom wartością. Norma bez poziomu (EN ISO 21420 to sama
        // zgodność) trafia jako wartość pod wspólną etykietę — wiersz bez wartości karta i tak by pominęła.
        foreach ($raw['norms'] ?? [] as $norm) {
            $value = trim((string) ($norm['value'] ?? ''));
            $fields[] = $value === ''
                ? new B2bRemoteShopField(self::SECTION_NORMS, self::NORM_ROW_LABEL, (string) $norm['label'])
                : new B2bRemoteShopField(self::SECTION_NORMS, (string) $norm['label'], $value);
        }

        return $fields;
    }

    /**
     * Pary „norma → poziom” z danych strukturalnych karty, dosłownie. Co z tego jest poziomem EN 388,
     * a co tylko cytatem (ANSI/ISEA, klasy odporności chemicznej), rozstrzyga ManufacturerNormFacts.
     *
     * @return list<B2bRemoteNormFact>
     */
    public function normFacts(B2bRemoteProduct $product): array
    {
        $facts = [];
        foreach ($product->raw['norms'] ?? [] as $norm) {
            $label = trim((string) ($norm['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $value = trim((string) ($norm['value'] ?? ''));
            $facts[] = new B2bRemoteNormFact($label, $value === '' ? null : $value);
        }

        return $facts;
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $documents = [];
        foreach ($product->raw['documents'] ?? [] as $file) {
            $documents[] = new B2bRemoteDocument(
                title: (string) $file['title'],
                sourceUrl: (string) $file['url'],
                kind: (string) $file['kind'],
            );
        }

        return $documents;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
    }

    /**
     * Zdjęcia wyrobu z danych strukturalnych karty — witryna wskazuje w nich plik oryginalny, więc nie trzeba
     * odsiewać grafik technologii i miniatur szablonu z galerii.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['image_urls'] ?? [];
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
     * Karty bez norm albo bez tabelki zgłaszamy w podsumowaniu przebiegu: witryna jest w znacznikach
     * Tailwinda, więc przebudowa szablonu odbiera nam tabelkę po cichu — w dzienniku ma być widać, ile
     * kart wróciło bez niej.
     *
     * @return list<string>
     */
    public function runSummary(): array
    {
        $out = [];
        if ($this->withoutNorms !== []) {
            $out[] = 'Kart bez listy norm w danych strukturalnych: '.self::listing($this->withoutNorms);
        }
        if ($this->withoutSpecs !== []) {
            $out[] = 'Kart bez tabelki „'.self::HEADING_SPECS.'”: '.self::listing($this->withoutSpecs);
        }

        return $out;
    }

    /**
     * @param  list<string>  $skus
     */
    private static function listing(array $skus): string
    {
        return count($skus).' — '.implode('; ', array_slice($skus, 0, 20))
            .(count($skus) > 20 ? ' i '.(count($skus) - 20).' więcej' : '');
    }

    /**
     * Adresy kart artykułów z mapy witryny, bez powtórzeń i w kolejności z mapy.
     *
     * @return list<string>
     */
    private function productUrls(): array
    {
        $maps = self::locations($this->client->body(AtgB2bClient::baseUrl().'/sitemap.xml'));
        if ($maps === []) {
            throw new RuntimeException('Mapa strony '.AtgB2bClient::HOST.' nie wskazuje żadnej mapy adresów');
        }

        $urls = [];
        foreach ($maps as $map) {
            foreach (self::locations($this->client->body($map)) as $url) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if (preg_match(self::PRODUCT_PATH, $path) === 1) {
                    $urls[$url] = true;
                }
            }
        }

        if ($urls === []) {
            throw new RuntimeException('Mapa strony '.AtgB2bClient::HOST.' nie zawiera żadnej karty artykułu');
        }

        return array_keys($urls);
    }

    /**
     * Adresy z mapy witryny — zarówno z mapy nadrzędnej, jak i ze zwykłej listy adresów. Bierzemy tylko <loc>:
     * odsyłacze do wersji językowych (xhtml:link) stoją przy każdym adresie i nie są osobnymi stronami.
     *
     * @return list<string>
     */
    private static function locations(string $xml): array
    {
        if (preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $xml, $m) < 1) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $url) {
            $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== '') {
                $out[] = $url;
            }
        }

        return $out;
    }

    private function productFor(string $url): B2bRemoteProduct
    {
        try {
            $html = $this->client->bodyOrNull($url);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return self::skipped($url, 'nie udało się pobrać karty: '.$e->getMessage());
        }

        if ($html === null) {
            return self::skipped($url, 'karta nie istnieje (martwy wpis w mapie strony)');
        }

        $data = self::structuredProduct($html);
        if ($data === null) {
            return self::skipped($url, 'strona nie zawiera danych produktu (schema.org Product)');
        }

        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            return self::skipped($url, 'karta bez numeru artykułu');
        }
        $name = trim((string) ($data['name'] ?? '')) ?: $sku;

        $dom = self::dom($html);
        $norms = self::norms($data);
        $specs = self::specRows($dom);
        if ($norms === []) {
            $this->withoutNorms[] = $sku;
        }
        if ($specs === []) {
            $this->withoutSpecs[] = $sku;
        }

        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: $name,
            sourceUrl: $url,
            raw: [
                'status' => 'ok',
                'description' => self::prose($dom),
                'specs' => $specs,
                'features' => self::featureList($dom),
                'norms' => $norms,
                'documents' => $this->documentLinks($dom),
                'image_urls' => self::imagesFrom($data),
            ],
        );
    }

    /**
     * Dane strukturalne produktu ze strony (schema.org). Strona ma ich kilka (ścieżka nawigacyjna, firma) —
     * bierzemy pierwszy blok typu Product.
     *
     * @return array<string, mixed>|null
     */
    private static function structuredProduct(string $html): ?array
    {
        if (preg_match_all('#<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>#is', $html, $m) < 1) {
            return null;
        }

        foreach ($m[1] as $json) {
            $data = json_decode(trim($json), true);
            if (is_array($data) && ($data['@type'] ?? null) === 'Product') {
                return $data;
            }
        }

        return null;
    }

    /**
     * Pary „norma → poziom” z danych strukturalnych, dosłownie i w kolejności ze strony.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: string}>
     */
    private static function norms(array $data): array
    {
        $out = [];
        foreach (is_array($data['additionalProperty'] ?? null) ? $data['additionalProperty'] : [] as $property) {
            if (! is_array($property)) {
                continue;
            }
            $label = trim((string) ($property['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $value = $property['value'] ?? null;
            $out[] = [
                'label' => $label,
                'value' => is_scalar($value) ? trim((string) $value) : '',
            ];
        }

        return $out;
    }

    /**
     * Zdjęcia wyrobu z danych strukturalnych — pojedynczy adres albo lista.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function imagesFrom(array $data): array
    {
        $image = $data['image'] ?? null;
        $candidates = is_array($image) ? $image : [$image];

        $out = [];
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $url = self::absolute(trim($candidate));
            if ($url !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Proza karty: akapit wstępu pod numerem artykułu i bloki cech (nagłówek razem z akapitem).
     * Akapity rozdzielone pustą linią — tak samo jak w opisach z pozostałych źródeł.
     */
    private static function prose(DOMXPath $dom): string
    {
        $parts = [];
        $intro = $dom->query('(//h1)[1]/following::div['.self::classPredicate('prose').'][1]');
        foreach ($intro !== false && $intro->item(0) !== null ? self::paragraphs($dom, $intro->item(0)) : [] as $text) {
            $parts[] = $text;
        }

        $features = $dom->query('(//h1)[1]/following::dl[1]/descendant::dt');
        foreach ($features !== false ? $features : [] as $term) {
            $heading = self::text($term);
            $body = $dom->query('following-sibling::dd[1]', $term);
            $text = $body !== false && $body->item(0) !== null ? self::text($body->item(0)) : '';
            if ($heading === '' && $text === '') {
                continue;
            }
            // Nagłówek bloku jest zdaniem („Zwiększona ochrona przed przecięciem z zachowaniem komfortu”),
            // więc zostaje pierwszym zdaniem akapitu — bez dopisywania interpunkcji.
            $parts[] = trim($heading."\n".$text);
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    /**
     * Akapity bloku w kolejności ze strony; blok bez akapitów daje swój własny tekst.
     *
     * @return list<string>
     */
    private static function paragraphs(DOMXPath $dom, DOMNode $block): array
    {
        $out = [];
        $nodes = $dom->query('.//p', $block);
        foreach ($nodes !== false ? $nodes : [] as $node) {
            $text = self::text($node);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        if ($out === []) {
            $text = self::text($block);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Tabelka „Informacje o produkcie”: etykieta wiersza i wartość dosłownie ze strony.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function specRows(DOMXPath $dom): array
    {
        $block = $dom->query('//h4[normalize-space(.)="'.self::HEADING_SPECS.'"]/following-sibling::div[1]');
        if ($block === false || $block->item(0) === null) {
            return [];
        }

        $out = [];
        $labels = $dom->query('.//span['.self::classPredicate('font-semibold').']', $block->item(0));
        foreach ($labels !== false ? $labels : [] as $label) {
            $name = self::text($label);
            $value = $dom->query('following-sibling::p[1]', $label);
            $text = $value !== false && $value->item(0) !== null ? self::text($value->item(0)) : '';
            if ($name === '' || $text === '') {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $text];
        }

        return $out;
    }

    /**
     * Lista „Funkcje” — cechy wyrobu podane jako ikona z podpisem.
     *
     * @return list<string>
     */
    private static function featureList(DOMXPath $dom): array
    {
        $items = $dom->query('//h4[normalize-space(.)="'.self::HEADING_FEATURES.'"]/following-sibling::ul[1]/li');
        if ($items === false) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $text = self::text($item);
            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Pliki karty: adres w polskiej wersji (sam /download/… przekierowuje na wersję angielską), nazwa i rodzaj
     * po wzorcu nazwy pliku. Plik spoza wzorca zostaje z nazwą z odnośnika i rodzajem „inne”.
     *
     * Kolejność: najpierw pliki rozpoznane, w kolejności DOCUMENT_KINDS, potem nierozpoznane — z karty
     * technicznej bierzemy tekst do indeksu wyszukiwania, więc musi stać przed katalogową.
     *
     * @return list<array{url: string, title: string, kind: string}>
     */
    private function documentLinks(DOMXPath $dom): array
    {
        $links = $dom->query('//a[starts-with(@href, "/download/")]');
        if ($links === false) {
            return [];
        }

        $known = [];
        $rest = [];
        $seen = [];
        foreach ($links as $link) {
            $href = $link instanceof \DOMElement ? trim($link->getAttribute('href')) : '';
            if ($href === '' || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            // Wersja polska pliku: sam /download/{uuid} przekierowuje na wersję angielską witryny.
            $url = AtgB2bClient::baseUrl().'/'.AtgB2bClient::LANGUAGE.$href;
            $label = self::text($link);
            $match = self::kindFromLabel($label);
            if ($match === null) {
                // Podpisu nie znamy (inne tłumaczenie, nowy rodzaj pliku) — pytamy witrynę o nazwę pliku.
                $file = null;
                try {
                    $file = $this->client->fileName($url);
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException) {
                    $file = null;
                }
                $match = $file !== null ? self::kindFromFile($file) : null;
            }
            $title = $match['title'] ?? $label;
            if (trim($title) === '') {
                continue;
            }
            $entry = ['url' => $url, 'title' => $title, 'kind' => $match['kind'] ?? ProductDocument::KIND_OTHER];
            if (isset($match['position'])) {
                $known[$match['position']][] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        ksort($known);
        $out = [];
        foreach ($known as $entries) {
            foreach ($entries as $entry) {
                $out[] = $entry;
            }
        }

        return array_merge($out, $rest);
    }

    /**
     * Rodzaj pliku po podpisie odnośnika — bez ogonków i wielkich liter, bo podpis bywa poprawiany
     * („charakterystki” → „charakterystyki”).
     *
     * @return array{position: int, title: string, kind: string}|null
     */
    private static function kindFromLabel(string $label): ?array
    {
        $needle = self::foldPolish($label);
        if ($needle === '') {
            return null;
        }
        foreach (self::DOCUMENT_KINDS as $position => $pattern) {
            foreach ($pattern['labels'] as $candidate) {
                if (str_contains($needle, $candidate)) {
                    return ['position' => $position, 'title' => $pattern['title'], 'kind' => $pattern['kind']];
                }
            }
        }

        return null;
    }

    /**
     * Rodzaj pliku po nazwie z nagłówka pobrania — nazwa jest po angielsku i wspólna dla wszystkich
     * wersji językowych witryny.
     *
     * @return array{position: int, title: string, kind: string}|null
     */
    private static function kindFromFile(string $file): ?array
    {
        $needle = mb_strtolower($file);
        foreach (self::DOCUMENT_KINDS as $position => $pattern) {
            foreach ($pattern['files'] as $candidate) {
                if (str_contains($needle, $candidate)) {
                    return ['position' => $position, 'title' => $pattern['title'], 'kind' => $pattern['kind']];
                }
            }
        }

        return null;
    }

    /** Tekst do porównania podpisów: małe litery bez polskich ogonków i bez podwójnych odstępów. */
    private static function foldPolish(string $text): string
    {
        $folded = strtr(mb_strtolower(trim($text)), [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    /** Adres bezwzględny w obrębie witryny; obcy host i puste odesłanie odpadają. */
    private static function absolute(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (str_starts_with($url, '/')) {
            return AtgB2bClient::baseUrl().$url;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_ends_with($host, AtgB2bClient::HOST) ? $url : null;
    }

    private static function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
    }

    /** Dopasowanie po jednej klasie CSS, niezależnie od pozostałych klas elementu. */
    private static function classPredicate(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * Pozycja, której nie da się zapisać. Kod i nazwa muszą być niepuste (B2bCatalogSync sprawdza je przed
     * price(), a dopiero price() zna prawdziwy powód) — zastępczo idzie ostatni segment adresu karty.
     */
    private static function skipped(string $url, string $reason): B2bRemoteProduct
    {
        $fallback = trim(basename((string) parse_url($url, PHP_URL_PATH))) ?: $url;

        return new B2bRemoteProduct(
            remoteId: $fallback,
            sku: $fallback,
            name: $fallback,
            sourceUrl: $url,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }
}
