<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * www.jspsafety.com — jeden kod produktu = jedna karta (bez wersji). Lista kodów z mapy strony, cena konta,
 * nazwa, cechy i zdjęcie ze strony produktu /products/kw/a/{KOD} (jedno zapytanie na produkt, w products()).
 *
 * Produkt, którego nie da się zapisać (kod poza katalogiem konta, strona bez kodu, inny kod na stronie, błąd
 * pobrania), jest i tak podawany do synchronizacji z raw['status'] ≠ 'ok' — price() rzuca wtedy wyjątek z
 * powodem, więc przebieg liczy go jako pominięty z prawdziwym powodem w dzienniku i nic nie zapisuje.
 * Nazwa takiego produktu to sam kod: B2bCatalogSync wymaga niepustej nazwy przed wywołaniem price()
 * (inaczej powód brzmiałby „brak kodu lub nazwy”), a do zapisu i tak nie dochodzi.
 *
 * Ceny: sklep pokazuje kontu ceny handlowe bez oznaczenia VAT — przyjmujemy je jako ceny netto zakupu
 * (założenie, strona tego nie mówi wprost). Waluta tylko z symbolu przy cenie („€” → EUR). Przy „From:” cena
 * zakupu = kwota „From” (najniższy próg ilościowy, jak ją pokazuje sklep); progów nie uśredniamy.
 * MRRP, gdy niepuste, to cena katalogowa; inaczej katalogowa = zakupu (jak w Anro).
 */
final class JspB2bConnector implements B2bConnector, B2bShopFieldSource
{
    private const REASON_UNAVAILABLE = 'niedostępny w katalogu konta (sklep przekierował na stronę główną)';

    /** Sekcje karty wyrobu u dostawcy (B2bShopFieldSource). */
    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_WEIGHTS = 'Wagi i wymiary';

    /** Elementy bez tekstu opisu (pola formularza, media, ramki). */
    private const SKIPPED_TAGS = ['input', 'button', 'select', 'option', 'textarea', 'img', 'iframe', 'video', 'audio', 'object', 'embed', 'svg', 'link', 'meta', 'script', 'style', 'noscript'];

    /** Elementy, które zaczynają i kończą linię tekstu (li i tr mają osobną obsługę). */
    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'header', 'footer', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'hr', 'pre'];

    private int $total = 0;

    public function __construct(private readonly JspB2bClient $client) {}

    public static function key(): string
    {
        return 'jsp';
    }

    public static function label(): string
    {
        return 'JSP';
    }

    public static function host(): string
    {
        return JspB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new JspB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $codes = $this->client->sitemapProductCodes();
        $this->total = count($codes);

        foreach ($codes as $code) {
            yield $this->productFor($code);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'JSP';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'strona produktu nieodczytana'));
        }

        $priceText = (string) ($product->raw['price_text'] ?? '');
        if ($priceText === '') {
            return null;
        }
        $price = self::money($priceText);
        if ($price === null) {
            throw new RuntimeException('nie udało się odczytać ceny „'.$priceText.'”');
        }
        if ($price['currency'] === null) {
            throw new RuntimeException('sklep nie podał waluty ceny („'.$priceText.'”)');
        }
        if ($price['amount'] <= 0) {
            return null;
        }

        $base = null;
        $mrrpText = (string) ($product->raw['mrrp_text'] ?? '');
        if ($mrrpText !== '') {
            $mrrp = self::money($mrrpText);
            // MRRP w innej walucie albo nieczytelne — nie mieszamy, katalogowa = zakupu
            if ($mrrp !== null && $mrrp['currency'] === $price['currency'] && $mrrp['amount'] > 0) {
                $base = $mrrp['amount'];
            }
        }

        return new B2bRemotePrice(
            net: $price['amount'],
            base: $base,
            discountPercent: 0.0,
            currency: $price['currency'],
        );
    }

    /**
     * Opis z tekstów strony, dosłownie (język strony konta, bez tłumaczenia), sekcje oddzielone pustą linią:
     * pełny opis z zakładki Overview; krótkie cechy spod tytułu tylko wtedy, gdy nie wszystkie są już liniami
     * opisu (bez Overview — same cechy, jak dotąd); zakładki Features & Benefits, Delivered With (bez linii już
     * obecnych w opisie) i Weights & Dimensions (tylko pary „nazwa: wartość”); na końcu jednostka sprzedaży.
     * Zakładki Documents, Other Colours, Product Reviews i Video nie trafiają do opisu.
     */
    public function description(B2bRemoteProduct $product): string
    {
        $raw = $product->raw;
        $sections = [];
        $known = [];

        $overview = $raw['overview'] ?? [];
        if ($overview !== []) {
            $sections[] = implode("\n", $overview);
            foreach ($overview as $line) {
                $known[self::lineKey($line)] = true;
            }
            $short = $raw['short_features'] ?? [];
            $uncovered = array_filter($short, static fn (string $item): bool => ! isset($known[self::lineKey($item)]));
            if ($uncovered !== []) {
                $sections[] = "Cechy w skrócie:\n".implode("\n", array_map(static fn (string $item): string => '- '.$item, $short));
                foreach ($short as $item) {
                    $known[self::lineKey($item)] = true;
                }
            }
        } elseif (($raw['features'] ?? []) !== []) {
            $sections[] = implode("\n", array_map(static fn (string $item): string => '- '.$item, $raw['features']));
            foreach ($raw['features'] as $item) {
                $known[self::lineKey($item)] = true;
            }
        }

        foreach (['tab_features' => 'Cechy i zalety:', 'delivered_with' => 'W zestawie:'] as $key => $heading) {
            $items = [];
            foreach ($raw[$key] ?? [] as $item) {
                if (! isset($known[self::lineKey($item)])) {
                    $known[self::lineKey($item)] = true;
                    $items[] = '- '.$item;
                }
            }
            if ($items !== []) {
                $sections[] = $heading."\n".implode("\n", $items);
            }
        }

        if (($raw['weights'] ?? []) !== []) {
            $sections[] = "Wagi i wymiary:\n".implode("\n", $raw['weights']);
        }

        $unit = (string) ($raw['unit'] ?? '');
        if ($unit !== '') {
            $sections[] = 'Jednostka: '.$unit;
        }

        return mb_substr(implode("\n\n", $sections), 0, 10000);
    }

    /**
     * Karta wyrobu u dostawcy z tego samego HTML-a, który products() już pobrał (żadnego zapytania więcej):
     * kod, jednostka sprzedaży („UOS:”) i kategoria z okruszków, a potem zakładka Weights & Dimensions —
     * wiersz „INNER PACK – Height: 12CM” rozdzielony na nazwę i wartość, dosłownie ze sklepu. Ceny nie
     * dokładamy — karta ma na nie własną sekcję.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $code = trim((string) ($product->raw['code'] ?? $product->sku));
        if ($code !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Kod', $code);
        }
        $unit = trim((string) ($product->raw['unit'] ?? ''));
        if ($unit !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', $unit);
        }
        $category = trim((string) ($product->category ?? ''));
        if ($category !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Kategoria', $category);
        }

        foreach ($product->raw['weights'] ?? [] as $row) {
            // wiersze buduje weightRows(): nazwa nigdy nie ma dwukropka, więc dzieli je pierwszy dwukropek
            [$name, $value] = array_pad(explode(':', (string) $row, 2), 2, '');
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_WEIGHTS, trim($name), trim($value));
        }

        return $fields;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = (string) ($product->raw['image_url'] ?? '');
        if ($url === '') {
            return null;
        }
        $file = $this->client->imageBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    private function productFor(string $code): B2bRemoteProduct
    {
        $url = $this->client->productUrl($code);
        try {
            $page = $this->client->productPage($code);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($code, $url, 'nie udało się pobrać strony produktu: '.$e->getMessage());
        }
        if ($page['status'] !== 'ok' || ! isset($page['html'])) {
            return $this->skipped($code, $url, self::REASON_UNAVAILABLE);
        }

        $xpath = JspB2bClient::dom($page['html']);
        $shownCode = self::text($xpath->query('//*['.JspB2bClient::classPredicate('ProductTitleBar_PartNo').']')->item(0));
        if ($shownCode === '') {
            return $this->skipped($code, $url, 'brak kodu produktu na stronie (np. strona grupy wariantów)');
        }
        // przekierowanie na inny produkt nie może zapisać jego ceny pod tym kodem
        if (JspB2bClient::normalizeCode($shownCode) !== JspB2bClient::normalizeCode($code)) {
            return $this->skipped($code, $url, 'kod na stronie ('.$shownCode.') inny niż kod z mapy strony ('.$code.')');
        }
        $name = self::text($xpath->query('//h1['.JspB2bClient::classPredicate('ProductTitleBar_Title').']')->item(0));

        $bar = $xpath->query('//*['.JspB2bClient::classPredicate('ProductSKU_FloatingBar').']')->item(0);
        $priceBox = $xpath->query('.//*['.JspB2bClient::classPredicate('Prices_ProductPriceContainer').']', $bar ?? null)->item(0);
        $mrrpBox = $xpath->query('.//*['.JspB2bClient::classPredicate('Prices_ProductPriceMRRPContainer').']', $bar ?? null)->item(0);
        $unitText = self::text($xpath->query('.//*['.JspB2bClient::classPredicate('CollapsiblePanelHead_HeaderUOS').']', $bar ?? null)->item(0));
        $unit = preg_match('/^UOS:\s*(.+)$/iu', $unitText, $m) === 1 ? trim($m[1]) : '';

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $shownCode,
            name: $name,
            category: self::category($xpath),
            sourceUrl: $url,
            raw: [
                'status' => 'ok',
                'code' => $code,
                'price_prefix' => self::text(self::first($xpath, 'Prices_PriceTextPrefix', $priceBox)),
                'price_text' => self::text(self::first($xpath, 'Prices_PriceText', $priceBox)),
                'mrrp_text' => self::text(self::first($xpath, 'Prices_PriceMRRPText', $mrrpBox)),
                'unit' => $unit,
                'features' => self::features($xpath),
                'short_features' => self::listItems($xpath, '//*['.JspB2bClient::classPredicate('ProductShortDescription').']//li'),
                'overview' => self::consecutiveUnique(self::tabLines($xpath, 'overview')),
                'tab_features' => self::tabItems($xpath, 'features'),
                'delivered_with' => self::tabItems($xpath, 'delivered'),
                'weights' => self::weightRows(self::tabLines($xpath, 'weights')),
                'image_url' => self::metaProperty($xpath, 'og:image'),
            ],
        );
    }

    private function skipped(string $code, string $url, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: $code,
            sourceUrl: $url,
            raw: ['status' => 'skipped', 'code' => $code, 'reason' => $reason],
        );
    }

    private static function first(DOMXPath $xpath, string $class, ?DOMNode $context): ?DOMNode
    {
        if ($context === null) {
            return null;
        }

        return $xpath->query('.//*['.JspB2bClient::classPredicate($class).']', $context)->item(0);
    }

    /**
     * Cechy z list „description-overview” (przy koncie po polsku), dosłownie, bez powtórzeń; gdy ich brak —
     * krótki opis z paska produktu.
     *
     * @return list<string>
     */
    private static function features(DOMXPath $xpath): array
    {
        $items = self::listItems($xpath, '//ul['.JspB2bClient::classPredicate('description-overview').']/li');

        return $items !== []
            ? $items
            : self::listItems($xpath, '//*['.JspB2bClient::classPredicate('ProductShortDescription').']//li');
    }

    /**
     * Teksty elementów listy, dosłownie, bez pustych i powtórzeń.
     *
     * @return list<string>
     */
    private static function listItems(DOMXPath $xpath, string $query): array
    {
        $out = [];
        foreach ($xpath->query($query) ?: [] as $li) {
            $text = self::text($li);
            if ($text !== '' && ! isset($out[$text])) {
                $out[$text] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * Treść zakładki danego rodzaju jako linie tekstu (pusta lista, gdy zakładki brak). Zakładkę rozpoznajemy po
     * tytule akordeonu (TabbedData_TabBodyAccordionTitle) albo nagłówku zakładki z tym samym data-index — tylko
     * Overview i Documents mają klasę Tab_*, reszta treści zakładek jest bez nazwy. Tytuły są po angielsku także
     * dla konta polskiego. Po słowach z treści (np. „waga”) zakładek nie zgadujemy.
     *
     * @param  'overview'|'features'|'delivered'|'weights'  $kind
     * @return list<string>
     */
    private static function tabLines(DOMXPath $xpath, string $kind): array
    {
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('TabbedData_TabBodyContainer').']') ?: [] as $body) {
            if (! $body instanceof DOMElement) {
                continue;
            }
            if (self::tabKind($xpath, $body) === $kind) {
                return self::blockLines($body);
            }
        }

        return [];
    }

    private static function tabKind(DOMXPath $xpath, DOMElement $body): ?string
    {
        $title = '';
        $index = $body->getAttribute('data-index');
        if ($index !== '' && preg_match('/^\d+$/', $index) === 1) {
            $container = $xpath->query('ancestor::*['.JspB2bClient::classPredicate('TabbedData_Container').'][1]', $body)->item(0);
            foreach (['TabbedData_TabBodyAccordionTitle', 'TabbedData_TabHead'] as $class) {
                $title = self::text($xpath->query(
                    ($container !== null ? './/' : '//').'*['.JspB2bClient::classPredicate($class).'][@data-index="'.$index.'"]',
                    $container,
                )->item(0));
                if ($title !== '') {
                    break;
                }
            }
        }
        if ($title === '') {
            $previous = $body->previousSibling;
            while ($previous !== null && ! $previous instanceof DOMElement) {
                $previous = $previous->previousSibling;
            }
            if ($previous instanceof DOMElement && str_contains(' '.$previous->getAttribute('class').' ', ' TabbedData_TabBodyAccordionTitle ')) {
                $title = self::text($previous);
            }
        }

        return match (true) {
            preg_match('/^overview$/i', $title) === 1,
            $title === '' && str_contains(' '.$body->getAttribute('class').' ', ' Tab_Overview ') => 'overview',
            preg_match('/\bfeatures\b.*\bbenefits\b/i', $title) === 1 => 'features',
            preg_match('/^delivered\s+with$/i', $title) === 1 => 'delivered',
            preg_match('/^weights\b/i', $title) === 1 => 'weights',
            default => null,
        };
    }

    /**
     * Pozycje zakładki listowej (Features & Benefits, Delivered With): bez znacznika „- ” i bez powtórzeń.
     *
     * @param  'features'|'delivered'  $kind
     * @return list<string>
     */
    private static function tabItems(DOMXPath $xpath, string $kind): array
    {
        $out = [];
        foreach (self::tabLines($xpath, $kind) as $line) {
            $item = (string) preg_replace('/^- /u', '', $line);
            if ($item !== '' && ! isset($out[$item])) {
                $out[$item] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * Wiersze Weights & Dimensions: tylko linie „nazwa: wartość” (dosłownie, bez tłumaczenia i przeliczania
     * jednostek), poprzedzone ostatnią nazwą grupy — linią bez dwukropka, np. „INNER PACK” → „INNER PACK – Height: 12CM”.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function weightRows(array $lines): array
    {
        $rows = [];
        $group = '';
        foreach ($lines as $line) {
            if (preg_match('/^([^:]{1,80}?)\s*:\s*(\S.*)$/u', $line, $m) === 1) {
                $rows[] = ($group !== '' ? $group.' – ' : '').$m[1].': '.$m[2];
            } elseif (! str_contains($line, ':') && ! str_contains($line, ' | ')) {
                $group = $line;
            }
        }

        return $rows;
    }

    /**
     * Element HTML → linie tekstu. Nowa linia: <br>, początek i koniec elementu blokowego, pozycja listy („- …”),
     * wiersz tabeli (dwie komórki → „nazwa: wartość”). Pogrubienie (<strong>, <b>) jest osobną linią-nagłówkiem,
     * gdy zaczyna linię poza listą, a tekst po nim nie jest dalszym ciągiem zdania („<strong>CR2</strong> - CR2 to…”,
     * „<strong>Wkładka EPP </strong>- Nasza…” zostają w jednej linii). Bez pól formularza, obrazków, ramek
     * i przełącznika „Show more/less”. Białe znaki w tekście zwinięte do spacji, puste linie pominięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $root): array
    {
        $lines = [];
        $buffer = '';
        $flush = static function () use (&$lines, &$buffer): void {
            $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $buffer));
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
            $buffer = '';
        };

        $walk = static function (DOMNode $node, bool $inList) use (&$walk, &$buffer, $flush): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    $buffer .= $child->nodeValue;

                    continue;
                }
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->nodeName);
                $class = ' '.$child->getAttribute('class').' ';
                if (in_array($tag, self::SKIPPED_TAGS, true)
                    || str_contains($class, ' ProductDescription_ShowHideDescriptionLinkContainer ')
                    || str_contains($class, ' ProductDescription_ShowHideDescriptionLink ')) {
                    continue;
                }

                if ($tag === 'br') {
                    $flush();
                } elseif ($tag === 'li') {
                    $flush();
                    $buffer = '- ';
                    $walk($child, true);
                    $flush();
                } elseif ($tag === 'tr') {
                    $flush();
                    $total = 0;
                    $cells = [];
                    foreach ($child->childNodes as $cell) {
                        if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                            $total++;
                            if (self::text($cell) !== '') {
                                $cells[] = self::text($cell);
                            }
                        }
                    }
                    // nazwa bez wartości (np. „Uwagi” | pusta komórka) to ani para, ani nagłówek grupy — pomijamy
                    if (! ($total >= 2 && count($cells) === 1)) {
                        $buffer = count($cells) === 2 ? $cells[0].': '.$cells[1] : implode(' | ', $cells);
                    }
                    $flush();
                } elseif (in_array($tag, self::BLOCK_TAGS, true)) {
                    $flush();
                    $walk($child, $inList);
                    $flush();
                } elseif (in_array($tag, ['strong', 'b'], true) && ! $inList && self::isHeading($child, $buffer)) {
                    $flush();
                    $walk($child, $inList);
                    $flush();
                } else {
                    $walk($child, $inList);
                }
            }
        };

        $walk($root, false);
        $flush();

        return $lines;
    }

    /** Pogrubienie jako nagłówek: zaczyna linię, nie kończy się łącznikiem, a następny tekst nie ciągnie zdania. */
    private static function isHeading(DOMElement $bold, string $buffer): bool
    {
        if (trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $buffer)) !== '') {
            return false;
        }
        $own = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $bold->textContent));
        if ($own === '' || preg_match('/[-–—:]$/u', $own) === 1) {
            return false;
        }
        $next = $bold->nextSibling;
        while ($next !== null && $next->nodeType === XML_TEXT_NODE && trim((string) preg_replace('/[\s\x{00A0}]+/u', '', (string) $next->nodeValue)) === '') {
            $next = $next->nextSibling;
        }
        if ($next === null || $next->nodeType !== XML_TEXT_NODE) {
            return true;
        }
        $following = ltrim((string) preg_replace('/^[\s\x{00A0}]+/u', '', (string) $next->nodeValue));

        return preg_match('/^[-–—:,.;)\p{Ll}]/u', $following) !== 1;
    }

    /**
     * Linie bez powtórzeń tuż po sobie (ta sama linia w dwóch miejscach opisu może mieć inne znaczenie — zostaje).
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function consecutiveUnique(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($out === [] || end($out) !== $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** Klucz porównania linii (czy cecha jest już w opisie): bez „- ”, końcowej kropki i wielkości liter. */
    private static function lineKey(string $line): string
    {
        return mb_strtolower(trim((string) preg_replace(['/^-\s+/u', '/[\s.;:,]+$/u'], '', $line)));
    }

    /** Ścieżka kategorii z okruszków; „All Products” i „Search : …” (adres kw/a) to nie kategorie. */
    private static function category(DOMXPath $xpath): ?string
    {
        $segments = [];
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('BreadCrumbs_BreadCrumbLinks').']//a['.JspB2bClient::classPredicate('breadcrumb').']') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $path = rtrim((string) parse_url($a->getAttribute('href'), PHP_URL_PATH), '/');
            $text = self::text($a);
            if ($text !== '' && str_starts_with($path, '/products/') && ! str_starts_with($path, '/products/kw/')) {
                $segments[] = $text;
            }
        }

        return $segments !== [] ? implode(' › ', $segments) : null;
    }

    private static function metaProperty(DOMXPath $xpath, string $property): string
    {
        $node = $xpath->query('//meta[@property="'.$property.'"]')->item(0);
        $value = $node instanceof DOMElement ? trim($node->getAttribute('content')) : '';

        return preg_match('#^https?://#i', $value) === 1 ? $value : '';
    }

    private static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));
    }

    /**
     * „€155.83” / „€1,234.56” → kwota i waluta z symbolu (null, gdy symbolu brak). Zapis niejednoznaczny
     * („1,234” bez części dziesiętnej) = null — nie zgadujemy.
     *
     * @return array{amount: float, currency: string|null}|null
     */
    private static function money(string $text): ?array
    {
        $currency = match (true) {
            str_contains($text, '€'), preg_match('/\bEUR\b/', $text) === 1 => 'EUR',
            str_contains($text, '£'), preg_match('/\bGBP\b/', $text) === 1 => 'GBP',
            mb_stripos($text, 'zł') !== false, preg_match('/\bPLN\b/', $text) === 1 => 'PLN',
            default => null,
        };
        if (preg_match('/\d[\d.,\s\x{00A0}]*/u', $text, $m) !== 1) {
            return null;
        }
        $number = rtrim((string) preg_replace('/[\s\x{00A0}]+/u', '', $m[0]), ',.');

        $lastDot = strrpos($number, '.');
        $lastComma = strrpos($number, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $number = str_replace([$thousands, $decimal], ['', '.'], $number);
        } elseif ($lastDot !== false || $lastComma !== false) {
            $sep = $lastDot !== false ? '.' : ',';
            $parts = explode($sep, $number);
            $tail = (string) end($parts);
            if (count($parts) === 2 && strlen($tail) <= 2) {
                $number = str_replace($sep, '.', $number);
            } elseif (strlen($tail) === 3 && count($parts) > 2) {
                $number = str_replace($sep, '', $number);
            } else {
                return null;
            }
        }
        if (! is_numeric($number)) {
            return null;
        }

        return ['amount' => round((float) $number, 2), 'currency' => $currency];
    }
}
