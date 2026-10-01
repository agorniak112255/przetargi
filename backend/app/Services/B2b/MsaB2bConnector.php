<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Throwable;

/**
 * pl.msasafety.com — sklep producenta MSA Safety (hełmy V-Gard, ochrona dróg oddechowych, detektory ALTAIR, szelki,
 * ochronniki słuchu). Logowanie kodem z e-maila i tempo zapytań opisuje MsaB2bClient.
 *
 * Lista: mapa strony „Product-pl” (481 stron wyrobów 01.10.2026). Strona wyrobu (produkt bazowy, np. „V-Gard® 500
 * z wentylacją”) zawiera listę numerów części — każdy numer to osobny wyrób do zamówienia (inny kolor, więźba,
 * wyposażenie, zakres czujnika, rodzaj pochłaniacza), dlatego karta = numer części. Wyjątek (decyzja właściciela
 * 28.09.2026 o wariantach kolorystycznych): numery części jednej strony, których opisy różnią się wyłącznie jednym
 * członem koloru („V-Gard 500, Helmet, vented, green, Fas-Trac III Foam” / „…, yellow, …”), to jedna karta
 * z kolorami jako pozycjami (members z ceną każdej — jak MmmB2bConnector). Numery „Wstrzymane” (discontinued-product)
 * pomijamy. Strona bez numerów części (np. analizatory Bacharach) nie daje kart.
 *
 * Cena: odpowiedź /sap/retrieveAllProductPricing.json dla numerów części strony (porcje jak data-batchsize strony):
 * value = cena konta netto; formattedListValue = cena katalogowa (gdy sklep ją podaje); waluta z currencyIso.
 * Warunek zamawiania dosłownie z wiersza numeru („ILOŚĆ min: 20”, „Przyrosty ILOŚCI: 20”) — skrypt strony wymusza
 * ilość ≥ min i wielokrotność przyrostu; jednostka z „/szt”.
 *
 * Opis = tekst wstępu i lista „Cechy” strony wyrobu, dosłownie (bez bloków marketingowych z filmem); „Specyfikacje” —
 * do tabelki sklepu razem z numerem i opisem części (opisy części MSA podaje po angielsku). Pliki z Bynder: aprobaty
 * europejskie (zakres „EU …”, region Europa — deklaracje zgodności, certyfikaty UE) oraz karty katalogowe z wersją
 * polską; instrukcje, ulotki, white papers i aprobaty spoza Europy pomijamy (dziesiątki plików na rodzinę, a każdy
 * zapisywany przy każdej karcie — z instrukcjami próbka 150 stron dawała ok. 5 GB). Zdjęcia: numeru części i rodziny
 * w pełnym rozmiarze z assetlibrary.
 *
 * Producent = „MSA” (sklep producenta; marek innych firm nie sprzedaje — strony Bacharach to marka grupy MSA i nie
 * mają numerów części do zamówienia).
 */
final class MsaB2bConnector implements B2bCodeLoginSite, B2bConnector, B2bDocumentSource, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'MSA';

    private const SECTION = 'Informacje ze sklepu MSA';

    /**
     * Tyle numerów części z odczytaną stroną, a żaden z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen
     * (albo zmiana sklepu) — przebieg przerwany.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 40;

    /** Największa porcja numerów części w jednym zapytaniu o ceny (strona podaje własną w data-batchsize). */
    private const MAX_PRICE_BATCH = 50;

    /** Plik większy niż to (KB wg Bynder) — pomijany (synchronizacja przyjmuje dokumenty do 12 MB). */
    private const MAX_DOCUMENT_KB = 12000;

    private const PROGRESS_EVERY = 25;

    private const EPSILON = 0.005;

    /** Jeden człon opisu numeru części, który jest wyłącznie kolorem („green”, „yellow hi-viz”, „hi-viz orange”). */
    private const COLOUR_SEGMENT = '/^(?:(?:hi-?viz|high-?viz|fluo(?:rescent)?)\s+)?(?:white|yellow|red|blue|green|orange|black|grey|gray|brown|pink|purple|silver|navy|beige|lime)(?:\s+(?:hi-?viz|high-?viz|fluo(?:rescent)?))?$/i';

    private int $pagesTotal = 0;

    private int $pagesDone = 0;

    private int $yielded = 0;

    private int $cards = 0;

    private int $colourCards = 0;

    private int $colourMembers = 0;

    private int $withPrice = 0;

    private int $documentsListed = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var array<string, list<string>> powód → numery części */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var list<string> */
    private array $discontinued = [];

    /** @var list<string> */
    private array $withoutParts = [];

    /** @var list<string> */
    private array $failedPages = [];

    /** @var list<string> */
    private array $duplicateParts = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $documentErrors = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly MsaB2bClient $client,
        private readonly ?B2bAccount $account = null,
    ) {}

    public static function key(): string
    {
        return 'msa';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return MsaB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        try {
            $session = $account->connector_session;
        } catch (DecryptException) {
            // sesja zaszyfrowana innym kluczem — jak brak sesji (przebieg poprosi o logowanie kodem)
            $session = null;
        }

        return new self(new MsaB2bClient(
            (string) $account->username,
            (string) $account->password,
            is_array($session) ? $session : [],
            max($delayMs, MsaB2bClient::MIN_DELAY_MS),
        ), $account);
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
        $error = $this->saveSession();
        if ($error !== null) {
            $this->summary[] = $error;
        }
    }

    public function startCodeLogin(): array
    {
        return $this->client->startCodeLogin();
    }

    public function finishCodeLogin(array $state, string $code): array
    {
        return $this->client->finishCodeLogin($state, $code);
    }

    public function products(): iterable
    {
        $this->summary = [];
        $this->withoutPrice = [];
        $this->withoutBase = [];
        $this->discontinued = [];
        $this->withoutParts = [];
        $this->failedPages = [];
        $this->duplicateParts = [];
        $this->withoutDescription = [];
        $this->documentErrors = [];
        $this->pagesDone = 0;
        $this->yielded = 0;
        $this->cards = 0;
        $this->colourCards = 0;
        $this->colourMembers = 0;
        $this->withPrice = 0;
        $this->documentsListed = 0;

        if (! $this->client->isLoggedIn()) {
            $this->login();
        }

        $list = $this->client->sitemapProducts();
        if ($list === []) {
            throw new RuntimeException('Mapa strony '.MsaB2bClient::HOST.' bez wyrobów — zmiana sklepu?');
        }
        $this->pagesTotal = count($list);
        $this->summary[] = 'Lista MSA (mapa strony): '.count($list).' stron wyrobów';
        $this->progress('Lista MSA (mapa strony): '.count($list).' stron wyrobów');

        $seenParts = [];
        foreach ($list as $code => $url) {
            foreach ($this->productsOf((string) $code, $url, $seenParts) as $product) {
                if ($this->withPrice === 0 && self::countAll($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                    throw new B2bFatalException(self::countAll($this->withoutPrice).' pierwszych numerów części '.MsaB2bClient::HOST.' bez żadnej ceny konta — sesja konta nie pokazuje cen albo sklep zmienił odpowiedź cen; przebieg przerwany');
                }
                $this->yielded++;
                yield $product;
            }
            $this->pagesDone++;
            if ($this->pagesDone % self::PROGRESS_EVERY === 0) {
                $this->progress('Strony MSA: '.$this->pagesDone.'/'.$this->pagesTotal.', kart: '.$this->cards);
            }
        }
    }

    /**
     * Liczba pozycji przebiegu: do końca listy szacowana z kart na odczytanych stronach (strona wyrobu daje od zera do
     * kilkudziesięciu kart), po ostatniej stronie dokładna.
     */
    public function totalProducts(): int
    {
        if ($this->pagesDone >= $this->pagesTotal || $this->pagesDone === 0) {
            return max($this->yielded, $this->pagesTotal);
        }

        return max($this->yielded, (int) ceil($this->yielded / $this->pagesDone * $this->pagesTotal));
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = 'Karty: '.$this->cards.($this->colourCards > 0 ? ' (w tym '.$this->colourCards.' z kolorami z '.$this->colourMembers.' numerów części)' : '');
        foreach ($this->withoutPrice as $reason => $parts) {
            $lines[] = 'Bez ceny konta — '.$reason.': '.self::listing($parts).' (pominięte)';
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej: '.self::listing($this->withoutBase);
        }
        if ($this->discontinued !== []) {
            $lines[] = 'Numery „Wstrzymane” (pominięte): '.self::listing($this->discontinued);
        }
        if ($this->withoutParts !== []) {
            $lines[] = 'Strony bez numerów części (bez kart): '.self::listing($this->withoutParts);
        }
        if ($this->duplicateParts !== []) {
            $lines[] = 'Numer części na kilku stronach (kolejne pominięte): '.self::listing($this->duplicateParts);
        }
        if ($this->failedPages !== []) {
            $lines[] = 'Strony nieodczytane: '.self::listing($this->failedPages);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Strony bez opisu: '.self::listing($this->withoutDescription);
        }
        $lines[] = 'Pliki z Bynder (aprobaty UE i karty katalogowe z wersją PL): '.$this->documentsListed;
        if ($this->documentErrors !== []) {
            $lines[] = 'Lista plików nieodczytana: '.self::listing($this->documentErrors);
        }
        if ($this->account !== null && $this->client->isLoggedIn()) {
            $lines[] = $this->saveSession() ?? 'Sesja MSA zapisana na koncie';
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
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'numer części nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('numer części bez ceny konta');
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
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $add = static function (string $name, string $value) use (&$fields): void {
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField(self::SECTION, $name, $value);
            }
        };
        $add('Wyrób', (string) $raw['family']);
        foreach ($raw['parts'] as $part) {
            $add('Numer części', $part['part'].': '.$part['description']);
        }
        foreach ($raw['specs'] as $spec) {
            $add($spec['name'], implode('; ', $spec['values']));
        }
        $lead = $raw['parts'][0];
        $add('Jednostka', (string) $lead['unit']);
        if ($lead['min'] !== null) {
            $add('ILOŚĆ min', B2bOrderQuantity::format($lead['min']));
        }
        if ($lead['step'] !== null) {
            $add('Przyrosty ILOŚCI', B2bOrderQuantity::format($lead['step']));
        }

        return $fields;
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
     * Strona wyrobu: nazwa, okruszki, opis (wstęp i „Cechy”), „Specyfikacje”, zdjęcie rodziny, ustawienia cen
     * (#pricing-config-variables) i numery części z listy „Part Number(s)”.
     *
     * @return array{name: string, categories: list<string>, description: string, specs: list<array{name: string, values: list<string>}>, image: string|null, config: array{csrf: string, permission: int|null, batch: int, product_code: string}, parts: list<array<string, mixed>>}
     */
    public static function parsePage(string $html): array
    {
        $xpath = self::xpath($html);

        $name = self::clean((string) $xpath->query('//h1['.self::cls('product-name').']')?->item(0)?->textContent);

        $categories = [];
        $crumbs = $xpath->query('//section[@id="breadcrumb"]/a') ?: [];
        foreach ($crumbs as $index => $a) {
            $text = self::clean($a->textContent);
            // pierwszy = „Strona główna”, ostatni = sam wyrób
            if ($index === 0 || $index === $crumbs->length - 1 || $text === '') {
                continue;
            }
            $categories[] = $text;
        }

        $intro = '';
        $body = $xpath->query('//div['.self::cls('pdp-redesign-description--body').']')?->item(0);
        if ($body !== null) {
            $intro = self::descriptionText(self::innerHtml($body));
        }
        $features = [];
        foreach ($xpath->query('//div['.self::cls('pdp-redesign--section--highlights').']/div['.self::cls('pdp-redesign--section-content').']') ?: [] as $content) {
            $inner = self::innerHtml($content);
            // blok marketingowy z filmem (np. „#MadeHereForYou” na stronach hełmów) — nie opis wyrobu
            if (preg_match('#<(?:section|video|iframe)\b|bvd-manifest-url#i', $inner) === 1) {
                continue;
            }
            $text = self::descriptionText($inner);
            if ($text !== '') {
                $features[] = $text;
            }
        }
        $description = implode("\n", array_filter([$intro, $features !== [] ? "Cechy:\n".implode("\n", $features) : ''], static fn (string $s): bool => $s !== ''));

        $specs = [];
        foreach ($xpath->query('//div['.self::cls('pdp-redesign--section--specifications').']//div['.self::cls('pdp-redesign-table-row').']') ?: [] as $row) {
            $label = self::clean((string) $xpath->query('.//strong', $row)?->item(0)?->textContent);
            $values = [];
            foreach ($xpath->query('./div[2]/div', $row) ?: [] as $value) {
                $text = self::clean($value->textContent);
                if ($text !== '' && ! in_array($text, $values, true)) {
                    $values[] = $text;
                }
            }
            if ($label !== '' && $values !== []) {
                $specs[] = ['name' => $label, 'values' => $values];
            }
        }

        $image = null;
        $thumb = $xpath->query('//div['.self::cls('pdp-redesign-info-left').']//img')?->item(0);
        if ($thumb instanceof DOMElement) {
            $image = self::fullImageUrl($thumb->getAttribute('src'));
        }

        $config = ['csrf' => '', 'permission' => null, 'batch' => 1, 'product_code' => ''];
        $cv = $xpath->query('//div[@id="pricing-config-variables"]')?->item(0);
        if ($cv instanceof DOMElement) {
            $permission = trim($cv->getAttribute('data-permission'));
            $batch = trim($cv->getAttribute('data-batchsize'));
            $config = [
                'csrf' => trim($cv->getAttribute('data-csrftoken')),
                'permission' => preg_match('/^-?\d+$/', $permission) === 1 ? (int) $permission : null,
                'batch' => ctype_digit($batch) && (int) $batch > 0 ? (int) $batch : 1,
                'product_code' => trim($cv->getAttribute('data-productcode')),
            ];
        }

        return [
            'name' => $name,
            'categories' => $categories,
            'description' => mb_substr($description, 0, 10000),
            'specs' => $specs,
            'image' => $image,
            'config' => $config,
            'parts' => self::parts($xpath),
        ];
    }

    /**
     * Numery części z listy „Part Number(s)” (#pdp-redesign-flyout-partnums), w kolejności strony.
     *
     * @return list<array{part: string, description: string, group: string, url: string|null, image: string|null, discontinued: bool, min: float|null, step: float|null, unit: string, product_code: string, uom: string, qty: float|null}>
     */
    private static function parts(DOMXPath $xpath): array
    {
        $out = [];
        $group = '';
        $nodes = $xpath->query('//div[@id="pdp-redesign-flyout-partnums"]//div['.self::cls('pdp-redesign-partnums--group-header').' or '.self::cls('pdp-redesign-partnums--partnum').']') ?: [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (preg_match('/\bpdp-redesign-partnums--group-header\b/', $node->getAttribute('class')) === 1) {
                $group = self::clean($node->textContent);

                continue;
            }
            $link = $xpath->query('.//a['.self::cls('pdp-redesign-partnum--partnum').']', $node)?->item(0);
            if (! $link instanceof DOMElement) {
                continue;
            }
            $part = self::clean($link->textContent);
            if ($part === '') {
                continue;
            }
            $description = '';
            for ($sibling = $link->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                if ($sibling instanceof DOMElement && $sibling->tagName === 'div') {
                    $description = self::clean($sibling->textContent);
                    break;
                }
            }
            $img = $xpath->query('.//img['.self::cls('variantImage').']', $node)?->item(0);
            $min = null;
            $step = null;
            foreach ($xpath->query('.//div['.self::cls('pdp-redesign-partnum--qty--minimum').']/div', $node) ?: [] as $line) {
                $text = self::clean($line->textContent);
                if (preg_match('/^(.+?):\s*(\d+(?:[.,]\d+)?)$/u', $text, $m) !== 1) {
                    continue;
                }
                $number = B2bOrderQuantity::attribute($m[2]);
                if (mb_stripos($m[1], 'min') !== false) {
                    $min ??= $number;
                } elseif (mb_stripos($m[1], 'przyrost') !== false || mb_stripos($m[1], 'increment') !== false) {
                    $step ??= $number;
                }
            }
            $unit = self::clean((string) $xpath->query('.//span['.self::cls('pdp-redesign-partnum--unit').']', $node)?->item(0)?->textContent);
            $unit = trim(ltrim($unit, '/'));
            // „/code.uom.” — nieprzetłumaczony klucz sklepu zamiast jednostki (np. pochłaniacze Advantage): jednostka nieznana
            if (str_starts_with($unit, 'code.')) {
                $unit = '';
            }
            $qty = self::inputValue($xpath, $node, 'qty');

            $out[] = [
                'part' => $part,
                'description' => $description,
                'group' => $group,
                'url' => self::absoluteShopUrl($link->getAttribute('href')),
                'image' => $img instanceof DOMElement ? self::fullImageUrl($img->getAttribute('src')) : null,
                'discontinued' => $xpath->query('.//div['.self::cls('discontinued-product').']', $node)?->length > 0,
                'min' => $min,
                'step' => $step,
                'unit' => $unit,
                'product_code' => self::inputValue($xpath, $node, 'productCode') ?? '',
                'uom' => self::inputValue($xpath, $node, 'productUnitOfMeasure') ?? '',
                'qty' => $qty !== null ? B2bOrderQuantity::attribute($qty) : null,
            ];
        }

        return $out;
    }

    /**
     * Cena numeru części z odpowiedzi /sap/retrieveAllProductPricing.json (wpis pod numerem części albo — jak
     * w skrypcie strony — cała odpowiedź, gdy zapytanie miało jeden numer). value = cena konta netto; cena katalogowa
     * z listValue albo z tekstu formattedListValue; waluta z currencyIso (albo „zł”/„€” w tekście ceny). Brak waluty
     * = brak ceny (bez zgadywania PLN).
     *
     * @param  array<string, mixed>  $response
     * @return array{net: float, base: float|null, currency: string}|string cena albo powód jej braku
     */
    public static function priceOf(array $response, string $part, bool $single): array|string
    {
        $entry = $response[$part] ?? ($single ? $response : null);
        if (! is_array($entry)) {
            return 'sklep nie zwrócił ceny';
        }
        $net = self::amount($entry['value'] ?? null) ?? self::amountFromText($entry['formattedValue'] ?? null);
        if ($net === null || $net <= 0) {
            return 'sklep nie podaje ceny konta';
        }
        $currency = self::currency($entry);
        if ($currency === null) {
            return 'cena bez waluty';
        }
        $base = self::amount($entry['listValue'] ?? null) ?? self::amountFromText($entry['formattedListValue'] ?? null);

        return ['net' => round($net, 2), 'base' => $base !== null && $base > 0 ? round($base, 2) : null, 'currency' => $currency];
    }

    /** Kwota z tekstu ceny („1 234,56 zł”, „PLN 1,234.56”, „12,50”); null = brak liczby. */
    public static function amountFromText(mixed $text): ?float
    {
        if (! is_string($text)) {
            return null;
        }
        $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/\d[\d.,]*/', $text, $m) !== 1) {
            return null;
        }
        $number = rtrim($m[0], '.,');
        $comma = strrpos($number, ',');
        $dot = strrpos($number, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $number = str_replace($decimal === ',' ? '.' : ',', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif ($comma !== false || $dot !== false) {
            $sep = $comma !== false ? ',' : '.';
            $last = (int) ($comma !== false ? $comma : $dot);
            $decimals = strlen($number) - $last - 1;
            if (substr_count($number, $sep) === 1 && $decimals !== 3) {
                $number = str_replace($sep, '.', $number);
            } else {
                $number = str_replace($sep, '', $number);
            }
        }

        return is_numeric($number) ? (float) $number : null;
    }

    /**
     * Klucz wariantu kolorystycznego z opisu numeru części: człony po przecinkach (puste pominięte), dokładnie jeden
     * z nich to sam kolor. null = opis bez członu koloru albo z kilkoma (nie łączymy).
     *
     * @return array{key: string, colour: string, stem: string}|null
     */
    public static function colourVariantKey(string $description): ?array
    {
        $segments = array_values(array_filter(
            array_map(static fn (string $s): string => self::clean($s), explode(',', $description)),
            static fn (string $s): bool => $s !== '',
        ));
        $colourAt = null;
        foreach ($segments as $index => $segment) {
            if (preg_match(self::COLOUR_SEGMENT, $segment) === 1) {
                if ($colourAt !== null) {
                    return null;
                }
                $colourAt = $index;
            }
        }
        if ($colourAt === null || count($segments) < 2) {
            return null;
        }
        $colour = $segments[$colourAt];
        $rest = $segments;
        unset($rest[$colourAt]);
        $keyed = $segments;
        $keyed[$colourAt] = '*';

        return [
            'key' => mb_strtolower(implode(', ', $keyed)),
            'colour' => $colour,
            'stem' => implode(', ', $rest),
        ];
    }

    /**
     * Pliki z listy Bynder: aprobaty europejskie (zakres „EU …”, region z „Europe”) jako certyfikaty i karty
     * katalogowe („Datasheet…”) z polską wersją. Tylko aktywne, do MAX_DOCUMENT_KB.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return list<array{title: string, url: string, kind: string}>
     */
    public static function documentsOf(array $assets): array
    {
        $out = [];
        foreach ($assets as $asset) {
            $id = (string) ($asset['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) !== 1 || ($asset['status'] ?? 'active') !== 'active') {
                continue;
            }
            $size = $asset['filesize'] ?? null;
            if (is_numeric($size) && (float) $size > self::MAX_DOCUMENT_KB) {
                continue;
            }
            $meta = is_array($asset['documentMetadataObject'] ?? null) ? $asset['documentMetadataObject'] : [];
            $filename = self::clean((string) ($asset['filename'] ?? ''));
            $languages = array_map('trim', explode(',', strtoupper((string) ($meta['language'] ?? ''))));
            if (($asset['approvalDocument'] ?? false) === true) {
                $scope = self::clean((string) ($meta['scope'] ?? ''));
                if (! str_starts_with($scope, 'EU') || stripos((string) ($meta['region'] ?? ''), 'Europe') === false) {
                    continue;
                }
                $title = $scope.($filename !== '' ? ' — '.$filename : '');
                $kind = ProductDocument::KIND_CERTIFICATE;
            } else {
                if (! in_array('PL', $languages, true)) {
                    continue;
                }
                $title = self::clean((string) ($meta['objectname'] ?? '')) ?: self::clean((string) ($asset['name'] ?? '')) ?: $filename;
                // instrukcje („Manuals”) to wielojęzyczne książki po kilka MB — przy każdej karcie rodziny (40 kart
                // V-Gard 500) dawały gigabajty; opis jest na stronie wyrobu
                if (strtolower((string) ($meta['document_type'] ?? '')) === 'manuals'
                    || preg_match('/\b(datasheet|karta katalogowa|data sheet)\b/i', $title.' '.$filename) !== 1) {
                    continue;
                }
                $kind = ProductDocument::KIND_DATASHEET;
            }
            $url = MsaB2bClient::ASSET_DOWNLOAD.$id;
            if ($title !== '' && ! in_array($url, array_column($out, 'url'), true)) {
                $out[] = ['title' => mb_substr($title, 0, 255), 'url' => $url, 'kind' => $kind];
            }
        }

        return $out;
    }

    /** Opis HTML jako tekst: podziały z tagów blokowych, punkty listy „- ”, indeksy (H<sub>2</sub>S) sklejone. */
    public static function descriptionText(string $html): string
    {
        $text = preg_replace('/\s+/u', ' ', $html) ?? $html;
        $text = preg_replace('#<\s*/?\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<\s*li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</?\s*(p|div|h[1-6]|ul|ol|tr|table)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Wszystkie karty jednej strony wyrobu (także pozycje bez ceny — jako pominięte z powodem).
     *
     * @param  array<string, true>  $seenParts
     * @return list<B2bRemoteProduct>
     */
    private function productsOf(string $code, string $url, array &$seenParts): array
    {
        try {
            $html = $this->client->productPage($url);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->failedPages[] = $code.' ('.$e->getMessage().')';

            return [];
        }
        $page = self::parsePage($html);
        $productCode = $page['config']['product_code'] !== '' ? $page['config']['product_code'] : $code;

        $active = [];
        foreach ($page['parts'] as $part) {
            if ($part['discontinued']) {
                $this->discontinued[] = $part['part'];

                continue;
            }
            if (isset($seenParts[$part['part']])) {
                $this->duplicateParts[] = $part['part'].' ('.$code.')';

                continue;
            }
            $seenParts[$part['part']] = true;
            $active[] = $part;
        }
        if ($active === []) {
            if ($page['parts'] === []) {
                $this->withoutParts[] = $code.($page['name'] !== '' ? ' '.$page['name'] : '');
            }

            return [];
        }

        $prices = $this->pricesOf($active, $productCode, $page['config'], $url);
        $products = [];
        $priced = [];
        foreach ($active as $part) {
            $price = $prices[$part['part']];
            if (is_string($price)) {
                $this->withoutPrice[$price][] = $part['part'];
                $products[] = $this->skipped($part, $page, $url, $price);

                continue;
            }
            $this->withPrice++;
            if ($price['base'] === null) {
                $this->withoutBase[] = $part['part'];
            }
            $priced[] = ['part' => $part, 'price' => $price];
        }
        if ($priced === []) {
            return $products;
        }

        if ($page['description'] === '') {
            $this->withoutDescription[] = $code;
        }
        $documents = [];
        try {
            $documents = self::documentsOf($this->client->documents($productCode));
            $this->documentsListed += count($documents);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->documentErrors[] = $code.' ('.$e->getMessage().')';
        }

        foreach (self::colourGroups($priced) as $group) {
            $products[] = $this->card($group, $page, $url, $documents);
        }

        return $products;
    }

    /**
     * Ceny konta numerów części jednej strony: porcje po data-batchsize strony (do MAX_PRICE_BATCH), w obrębie jednej
     * jednostki miary — jak skrypt strony. Błąd porcji = jej numery bez ceny z powodem.
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  array{csrf: string, permission: int|null, batch: int, product_code: string}  $config
     * @return array<string, array{net: float, base: float|null, currency: string}|string>
     */
    private function pricesOf(array $parts, string $productCode, array $config, string $referer): array
    {
        $out = [];
        $byUnit = [];
        foreach ($parts as $part) {
            $byUnit[$part['uom']][] = $part;
        }
        $batch = max(1, min($config['batch'], self::MAX_PRICE_BATCH));
        foreach ($byUnit as $uom => $unitParts) {
            foreach (array_chunk($unitParts, $batch) as $chunk) {
                $forms = array_map(static fn (array $part): array => [
                    'productCode' => $part['product_code'] !== '' ? $part['product_code'] : $productCode,
                    'partNumber' => $part['part'],
                    'quantityRequested' => $part['qty'] ?? $part['min'] ?? 1,
                ], $chunk);
                try {
                    $response = $this->client->prices($forms, (string) $uom, $config['csrf'], $referer);
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    foreach ($chunk as $part) {
                        $out[$part['part']] = 'błąd zapytania o cenę ('.$e->getMessage().')';
                    }

                    continue;
                }
                foreach ($chunk as $part) {
                    $out[$part['part']] = self::priceOf($response, $part['part'], count($chunk) === 1);
                }
            }
        }

        return $out;
    }

    /**
     * Numery części z ceną podzielone na karty: opisy różniące się tylko członem koloru (ta sama grupa strony,
     * jednostka, waluta, ≥ 2 różne kolory) — jedna karta; reszta — po jednym numerze. Kolejność kart jak na stronie.
     *
     * @param  list<array{part: array<string, mixed>, price: array{net: float, base: float|null, currency: string}}>  $priced
     * @return list<list<array{part: array<string, mixed>, price: array{net: float, base: float|null, currency: string}, colour: string|null, stem: string|null}>>
     */
    private static function colourGroups(array $priced): array
    {
        $buckets = [];
        $order = [];
        foreach ($priced as $index => $entry) {
            $variant = self::colourVariantKey($entry['part']['description']);
            $key = $variant !== null
                ? implode('|', [$entry['part']['group'], $entry['part']['unit'], $entry['price']['currency'], $variant['key']])
                : 'single|'.$index;
            if (! isset($buckets[$key])) {
                $order[] = $key;
            }
            $buckets[$key][] = $entry + ['colour' => $variant['colour'] ?? null, 'stem' => $variant['stem'] ?? null];
        }

        $out = [];
        foreach ($order as $key) {
            $group = $buckets[$key];
            $colours = array_map(static fn (array $e): string => mb_strtolower((string) $e['colour']), $group);
            if (count($group) >= 2 && count(array_unique($colours)) === count($colours)) {
                $out[] = $group;

                continue;
            }
            foreach ($group as $entry) {
                $out[] = [['colour' => null, 'stem' => null] + $entry];
            }
        }

        return $out;
    }

    /**
     * Karta jednego numeru części albo kilku kolorów jednego wyrobu (najtańszy kolor prowadzi).
     *
     * @param  list<array{part: array<string, mixed>, price: array{net: float, base: float|null, currency: string}, colour: string|null, stem: string|null}>  $group
     * @param  array<string, mixed>  $page
     * @param  list<array{title: string, url: string, kind: string}>  $documents
     */
    private function card(array $group, array $page, string $url, array $documents): B2bRemoteProduct
    {
        $lead = $group[0];
        foreach ($group as $entry) {
            $cheaper = round($entry['price']['net'], 2) <=> round($lead['price']['net'], 2);
            if ($cheaper < 0 || ($cheaper === 0 && strcmp($entry['part']['part'], $lead['part']['part']) < 0)) {
                $lead = $entry;
            }
        }
        $family = self::familyOf($lead['part'], $page);
        $prices = [];
        foreach ($group as $entry) {
            $prices[$entry['part']['part']] = self::remotePrice($entry['price']);
        }
        $orders = array_unique(array_map(static fn (array $e): string => json_encode([$e['part']['min'], $e['part']['step'], $e['part']['unit']]), $group));
        $leadPart = $lead['part'];
        $order = count($orders) > 1
            ? new B2bOrderQuantity(null, null, $leadPart['unit'] !== '' ? $leadPart['unit'] : null, varies: true)
            : ($leadPart['min'] !== null || $leadPart['step'] !== null
                ? new B2bOrderQuantity($leadPart['min'], $leadPart['step'], $leadPart['unit'] !== '' ? $leadPart['unit'] : null)
                : null);
        $cardPrice = $prices[$leadPart['part']];
        $cardPrice = new B2bRemotePrice(
            net: $cardPrice->net,
            base: $cardPrice->base,
            discountPercent: $cardPrice->discountPercent,
            currency: $cardPrice->currency,
            order: $order,
        );

        $images = [];
        foreach ($group as $entry) {
            $image = $entry['part']['image'];
            if ($image !== null && ! in_array($image, $images, true)) {
                $images[] = $image;
            }
        }
        $leadImage = $leadPart['image'];
        if ($leadImage !== null) {
            $images = [$leadImage, ...array_values(array_filter($images, static fn (string $u): bool => $u !== $leadImage))];
        }
        if ($page['image'] !== null && ! in_array($page['image'], $images, true)) {
            $images[] = $page['image'];
        }

        $coloured = count($group) > 1;
        $this->cards++;
        if ($coloured) {
            $this->colourCards++;
            $this->colourMembers += count($group);
        }
        $parts = array_map(static fn (array $e): array => $e['part'], $group);

        return new B2bRemoteProduct(
            remoteId: $leadPart['part'],
            sku: $leadPart['part'],
            name: self::cardName($family, $leadPart['description']),
            category: $page['categories'] !== [] ? implode(' > ', $page['categories']) : null,
            sourceUrl: $url,
            raw: [
                'status' => 'ok',
                'price' => $cardPrice,
                'family' => $family,
                'parts' => $coloured ? [$leadPart, ...array_values(array_filter($parts, static fn (array $p): bool => $p['part'] !== $leadPart['part']))] : $parts,
                'description' => $page['description'],
                'specs' => $page['specs'],
                'images' => $images,
                'documents' => $documents,
            ],
            variantSummary: $coloured
                ? 'Kolory: '.implode('; ', array_map(static fn (array $e): string => $e['colour'].' ('.$e['part']['part'].')', $group))
                : null,
            members: $coloured
                ? array_map(static fn (array $e): array => [
                    'remote_id' => $e['part']['part'],
                    'sku' => $e['part']['part'],
                    'name' => self::cardName($family, $e['part']['description']),
                    'size' => (string) $e['colour'],
                    'price' => $prices[$e['part']['part']],
                ], $group)
                : [],
            identifiers: array_map(static fn (array $e): B2bRemoteIdentifier => new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $e['part']['part'],
                remoteId: $coloured ? $e['part']['part'] : null,
                label: $coloured ? $e['colour'] : null,
                field: 'Part Number',
            ), $group),
            cardName: $coloured ? self::cardName($family, (string) $lead['stem']) : null,
        );
    }

    /**
     * Numer części, którego nie da się zapisać w tym przebiegu (bez ceny) — przebieg widzi go jako pominięty z powodem.
     *
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $page
     */
    private function skipped(array $part, array $page, string $url, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $part['part'],
            sku: $part['part'],
            name: self::cardName(self::familyOf($part, $page), $part['description']),
            sourceUrl: $url,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * @param  array{net: float, base: float|null, currency: string}  $price
     */
    private static function remotePrice(array $price): B2bRemotePrice
    {
        $base = $price['base'];

        return new B2bRemotePrice(
            net: $price['net'],
            base: $base,
            discountPercent: $base !== null && $base > 0 && $base + self::EPSILON >= $price['net'] ? round((1 - $price['net'] / $base) * 100, 2) : 0.0,
            currency: $price['currency'],
        );
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $page
     */
    private static function familyOf(array $part, array $page): string
    {
        return $part['group'] !== '' ? $part['group'] : (string) $page['name'];
    }

    /** Nazwa karty: wyrób ze strony (po polsku) i opis numeru części (MSA podaje go po angielsku), dosłownie. */
    private static function cardName(string $family, string $description): string
    {
        $name = $family !== '' && $description !== '' ? $family.' – '.$description : ($family !== '' ? $family : $description);

        return mb_substr($name, 0, 255);
    }

    /** Liczba z pola odpowiedzi cen (liczba albo tekst liczbowy); inaczej null. */
    private static function amount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function currency(array $entry): ?string
    {
        $iso = strtoupper(trim((string) ($entry['currencyIso'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $iso) === 1) {
            return $iso;
        }
        foreach (['formattedValue', 'formattedListValue'] as $field) {
            $text = $entry[$field] ?? null;
            if (! is_string($text)) {
                continue;
            }
            if (mb_stripos($text, 'zł') !== false) {
                return 'PLN';
            }
            if (str_contains($text, '€')) {
                return 'EUR';
            }
            if (preg_match('/(?<![A-Za-z])([A-Z]{3})(?![A-Za-z])/', $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Pełny rozmiar zdjęcia z assetlibrary (miniatura „…/transform/catalogthumb/{id}/{nazwa}” → „…/transform/{id}/{nazwa}”).
     * Zaślepka, scene7 (403 dla miniatur numerów części) i inne hosty = null.
     */
    private static function fullImageUrl(string $src): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! MsaB2bClient::isFileUrl($src)) {
            return null;
        }
        $url = preg_replace('#^(https://assetlibrary\.msasafety\.com/transform/)(?:catalogthumb|partnumthumb)/#i', '$1', $src) ?? $src;

        return str_contains((string) parse_url($url, PHP_URL_PATH), '/transform/') ? $url : null;
    }

    private static function absoluteShopUrl(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_contains($href, '..')) {
            return null;
        }
        $url = str_starts_with($href, '/') && ! str_starts_with($href, '//') ? MsaB2bClient::BASE.$href : $href;

        return MsaB2bClient::isShopUrl($url) ? $url : null;
    }

    private static function inputValue(DOMXPath $xpath, DOMNode $context, string $class): ?string
    {
        $input = $xpath->query('.//input['.self::cls($class).']', $context)?->item(0);

        return $input instanceof DOMElement ? trim($input->getAttribute('value')) : null;
    }

    private static function cls(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }

    private static function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= (string) $node->ownerDocument?->saveHTML($child);
        }

        return $html;
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /** Zapis sesji na koncie (tylko dwie kolumny); null = zapisano albo nie ma konta, inaczej komunikat. */
    private function saveSession(): ?string
    {
        if ($this->account === null || ! $this->account->exists) {
            return null;
        }
        try {
            $this->account->forceFill([
                'connector_session' => $this->client->session(),
                'connector_session_saved_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            return 'Sesja MSA nie została zapisana na koncie ('.$e->getMessage().')';
        }

        return null;
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    private static function countAll(array $groups): int
    {
        return array_sum(array_map('count', $groups));
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
