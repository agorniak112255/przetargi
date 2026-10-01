<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductIdentifier;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * hurt.demar24.pl — hurtownia producenta obuwia Demar (IdoSell). Sprawdzone na zalogowanym koncie 01.10.2026
 * (DemarB2bClient opisuje logowanie i sesję).
 *
 * Lista: mapa strony (872 produkty 01.10.2026). Karta = produkt sklepu (Symbol, np. „7261A”) ze wszystkimi
 * rozmiarami; dwie skale rozmiarów jednego wzoru (kalosze dziecięce 20–27 i 28–35) to w sklepie osobne produkty
 * z osobnymi Symbolami — i osobne karty. Dane rozmiarów z ajax/projector.php, reszta ze strony produktu (dwa
 * zapytania na produkt).
 *
 * Cena: price_net rozmiaru = cena konta netto po rabacie konta (01.10.2026 „Na cały asortyment 17 %”). Cena przed
 * rabatem (beforerebate_net) jest podana raz na produkt — dla rozmiarów w cenie produktu (price_net); rozmiar
 * w innej cenie (półbuty 9-080: 53,12 i 55,61 zł) zostaje bez ceny bazowej, bez przeliczania rabatem. Rozmiary
 * w różnych cenach = jedna karta z ceną każdego rozmiaru (B2bSizePriceSource). Ceny detalicznej nie zapisujemy.
 *
 * Producent = marka produktu ze sklepu („Demar”, a także „Playshoes”, „Coccine-Dakoma”); produkt bez marki zostaje
 * bez producenta (np. skarpety HUNTER), bez zgadywania. Opis = „Opis produktu” dosłownie (bez odnośników
 * „Więcej informacji”); parametry ze strony („Marka”, „Kolor”, „Podnosek”, „Normy bezpieczeństwa: S3”…) — do tabelki
 * sklepu. „Kod producenta” to EAN każdego rozmiaru — do identyfikatorów. Plików do pobrania karty nie mają.
 */
final class DemarB2bConnector implements B2bConnector, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Demar';

    private const SECTION = 'Informacje ze sklepu Demar';

    /** Parametr ze stroną EAN-ów rozmiarów — do identyfikatorów, nie do tabelki. */
    private const PRODUCER_CODE = 'Kod producenta';

    /** Odnośnik do artykułu na blogu sklepu w środku opisu — napis przycisku, nie treść o wyrobie. */
    private const MORE_LINK = 'Więcej informacji';

    /**
     * Tyle produktów z odczytaną stroną, a żaden z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen (albo
     * zmiana sklepu) — przebieg przerwany.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const PROGRESS_EVERY = 50;

    private const EPSILON = 0.005;

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    private int $multiPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $sizesWithoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var list<string> */
    private array $withoutBrand = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $duplicateSymbols = [];

    /** @var list<string> */
    private array $unmatchedCodes = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly DemarB2bClient $client) {}

    public static function key(): string
    {
        return 'demar';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return DemarB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new DemarB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->sizesWithoutPrice = [];
        $this->withoutBase = [];
        $this->withoutBrand = [];
        $this->withoutDescription = [];
        $this->duplicateSymbols = [];
        $this->unmatchedCodes = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $list = $this->client->sitemapProducts();
        if ($list === []) {
            throw new RuntimeException('Mapa strony '.DemarB2bClient::HOST.' bez produktów — zmiana sklepu?');
        }
        $this->total = count($list);
        $this->summary[] = 'Lista Demar: '.count($list).' produktów';
        $this->progress('Lista Demar (mapa strony): '.count($list).' produktów');

        $done = 0;
        $seenSymbols = [];
        foreach ($list as $id => $url) {
            $product = $this->productFor($id, $url, $seenSymbols);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych produktów '.DemarB2bClient::HOST.' bez żadnej ceny konta — sesja konta nie pokazuje cen albo sklep zmienił dane produktu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Produkty Demar: '.$done.'/'.count($list));
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
        $lines[] = 'Karty: '.$this->cards.($this->multiPrice > 0 ? ' ('.$this->multiPrice.' z rozmiarami w różnych cenach)' : '');
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Rozmiary bez ceny konta (te rozmiary poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Rozmiary w innej cenie niż cena produktu — bez ceny przed rabatem: '.self::listing($this->withoutBase);
        }
        if ($this->duplicateSymbols !== []) {
            $lines[] = 'Ten sam Symbol na kilku produktach (kolejne pominięte): '.self::listing($this->duplicateSymbols);
        }
        if ($this->withoutBrand !== []) {
            $lines[] = 'Bez marki w sklepie (karty bez producenta): '.self::listing($this->withoutBrand);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }
        if ($this->unmatchedCodes !== []) {
            $lines[] = 'Kod producenta bez pasującego rozmiaru (pominięty): '.self::listing($this->unmatchedCodes);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['manufacturer'] ?? '');
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'produkt nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('produkt bez ceny konta');
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
        $names = array_column($raw['parameters'], 'name');
        if (! in_array('Symbol', $names, true)) {
            $add('Symbol', (string) $raw['symbol']);
        }
        foreach ($raw['parameters'] as $parameter) {
            $add($parameter['name'], implode('; ', $parameter['values']));
        }
        $add('Jednostka sprzedaży', (string) $raw['unit']);
        $add('Rozmiary', implode(', ', $raw['sizes']));

        return $fields;
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
     * Strona produktu: kolejność i etykiety rozmiarów (window.product_data — etykiety jak na przyciskach, „S” zamiast
     * „s” z projector.php), parametry, opis, ścieżka kategorii i zdjęcia w pełnym rozmiarze.
     *
     * @return array{sizes: list<array{id: string, label: string}>, parameters: list<array{name: string, values: list<string>}>, producer_codes: list<array{label: string, code: string}>, description: string, categories: list<string>, images: list<string>}
     */
    public static function parseProductPage(string $html, int $productId): array
    {
        $xpath = self::xpath($html);

        $parameters = [];
        $codes = [];
        foreach ($xpath->query('//section[@id="projector_dictionary"]//div[contains(concat(" ", normalize-space(@class), " "), " dictionary__param ")]') ?: [] as $param) {
            $name = self::ownText($xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " dictionary__name_txt ")]', $param)?->item(0));
            if ($name === '') {
                continue;
            }
            $values = [];
            foreach ($xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " dictionary__value ")]', $param) ?: [] as $value) {
                $label = $xpath->query('.//span[contains(@class, "dictionary__producer_code") and contains(@class, "--name")]', $value)?->item(0);
                $code = $xpath->query('.//span[contains(@class, "dictionary__producer_code") and contains(@class, "--value")]', $value)?->item(0);
                if ($code !== null) {
                    $pair = ['label' => self::clean((string) $label?->textContent), 'code' => self::clean($code->textContent)];
                    if ($pair['code'] !== '') {
                        $codes[] = $pair;
                    }

                    continue;
                }
                $text = self::ownText($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dictionary__value_txt ")]', $value)?->item(0));
                if ($text !== '' && ! in_array($text, $values, true)) {
                    $values[] = $text;
                }
            }
            if ($name !== self::PRODUCER_CODE && $values !== []) {
                $parameters[] = ['name' => $name, 'values' => $values];
            }
        }

        $categories = [];
        foreach ($xpath->query('//div[@id="breadcrumbs"]//ol/li[contains(concat(" ", normalize-space(@class), " "), " category ")]/a') ?: [] as $a) {
            $text = self::clean($a->textContent);
            if ($text !== '') {
                $categories[] = $text;
            }
        }

        $images = [];
        foreach ($xpath->query('//div[@id="photos_slider"]//figure//img') ?: [] as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }
            $src = trim($img->getAttribute('data-img_high_res'));
            if ($src === '') {
                $src = trim($img->getAttribute('src'));
            }
            $url = self::absoluteUrl($src);
            if ($url !== null && ! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        $description = '';
        if (preg_match('#<section\b[^>]*\bid="projector_longdescription"[^>]*>(.*?)</section>#is', $html, $m) === 1) {
            $description = self::descriptionText($m[1]);
        }

        return [
            'sizes' => self::pageSizes($html, $productId),
            'parameters' => $parameters,
            'producer_codes' => $codes,
            'description' => $description,
            'categories' => $categories,
            'images' => $images,
        ];
    }

    /**
     * Opis HTML jako tekst: białe znaki jak w przeglądarce, podziały z tagów blokowych, punkty listy „- ”, odnośniki
     * „Więcej informacji” pominięte. Treść dosłownie.
     */
    public static function descriptionText(string $html): string
    {
        $html = preg_replace('#<a\b[^>]*>\s*'.preg_quote(self::MORE_LINK, '#').'\s*</a>#iu', '', $html) ?? $html;
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

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * @param  array<int, true>  $seenSymbols
     */
    private function productFor(int $id, string $url, array &$seenSymbols): B2bRemoteProduct
    {
        try {
            $logins = $this->client->logins();
            $json = $this->client->projector($id);
            $html = $this->client->productPage($url);
            if ($this->client->logins() !== $logins) {
                // strona wymagała ponownego logowania — dane rozmiarów mogły przyjść bez sesji (ceny detaliczne)
                $json = $this->client->projector($id);
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped((string) $id, '', 'produkt '.$id.': '.$e->getMessage(), []);
        }

        $node = is_array($json['sizes'] ?? null) ? $json['sizes'] : [];
        $symbol = self::clean((string) ($node['code'] ?? ''));
        $name = self::clean((string) ($node['name'] ?? ''));
        $items = is_array($node['items'] ?? null) ? $node['items'] : [];
        $positions = array_map(static fn (string $key): string => $id.'-'.self::sizeId($key), array_keys($items));
        if ($symbol === '') {
            return $this->skipped((string) $id, $name, 'produkt '.$id.' bez Symbolu w sklepie', $positions);
        }
        if (isset($seenSymbols[$symbol])) {
            $this->duplicateSymbols[] = $symbol.' (produkt '.$id.')';

            return $this->skipped($symbol, $name, 'ten sam Symbol ma już inny produkt sklepu', $positions);
        }
        if ($items === []) {
            $this->withoutPrice[] = $symbol;

            return $this->skipped($symbol, $name, 'sklep nie podał rozmiarów z ceną', []);
        }

        $page = self::parseProductPage($html, $id);
        $sizeprices = is_array($json['sizeprices'] ?? null) ? $json['sizeprices'] : [];
        $currency = self::currency($sizeprices);
        $sizes = self::orderedSizes($id, $items, $page['sizes']);

        $priced = [];
        foreach ($sizes as $size) {
            if ($size['net'] === null) {
                $this->sizesWithoutPrice[] = $symbol.' '.$size['label'];

                continue;
            }
            $priced[] = $size;
        }
        if ($priced === [] || $currency === null) {
            $this->withoutPrice[] = $symbol;

            return $this->skipped($symbol, $name, $currency === null ? 'sklep nie podał waluty ceny' : 'produkt bez ceny konta', $positions);
        }
        // Symbol zajmuje dopiero produkt z ceną — kolejny z tym samym Symbolem nie trafi na tę samą kartę
        $seenSymbols[$symbol] = true;
        $this->withPrice++;

        $headlineNet = is_numeric($sizeprices['price_net'] ?? null) ? (float) $sizeprices['price_net'] : null;
        $beforeRebate = is_numeric($sizeprices['beforerebate_net'] ?? null) ? (float) $sizeprices['beforerebate_net'] : null;
        $prices = [];
        foreach ($priced as $size) {
            $base = $beforeRebate !== null && $beforeRebate > 0 && $headlineNet !== null
                && abs($size['net'] - $headlineNet) < self::EPSILON && $beforeRebate >= $size['net'] - self::EPSILON
                ? $beforeRebate : null;
            if ($base === null && $beforeRebate !== null) {
                $this->withoutBase[] = $symbol.' '.$size['label'];
            }
            $prices[$size['remote_id']] = new B2bRemotePrice(
                net: $size['net'],
                base: $base,
                discountPercent: $base !== null && $base > 0 ? round((1 - $size['net'] / $base) * 100, 2) : 0.0,
                currency: $currency,
            );
        }
        $nets = array_unique(array_map(static fn (B2bRemotePrice $p): string => sprintf('%.2F', $p->net), $prices));
        $this->multiPrice += count($nets) > 1 ? 1 : 0;

        $unit = self::clean((string) ($node['unit'] ?? ''));
        $sellBy = is_numeric($node['unit_sellby'] ?? null) ? (float) $node['unit_sellby'] : null;
        $step = $sellBy !== null && $sellBy > 1 ? $sellBy : null;
        $cheapest = self::cheapest(array_values($prices));
        $cheapest = new B2bRemotePrice(
            net: $cheapest->net,
            base: $cheapest->base,
            discountPercent: $cheapest->discountPercent,
            currency: $cheapest->currency,
            order: new B2bOrderQuantity($step, $step, $unit !== '' ? $unit : null),
        );

        $manufacturer = is_array($node['firm'] ?? null) ? self::clean((string) ($node['firm']['name'] ?? '')) : '';
        if ($manufacturer === '') {
            $this->withoutBrand[] = $symbol;
        }
        if ($page['description'] === '') {
            $this->withoutDescription[] = $symbol;
        }
        $this->cards++;

        $codesByLabel = [];
        foreach ($page['producer_codes'] as $pair) {
            $codesByLabel[mb_strtolower($pair['label'])][] = $pair['code'];
        }
        $labels = [];
        foreach ($sizes as $size) {
            $labels[mb_strtolower($size['label'])] = true;
        }
        foreach ($page['producer_codes'] as $pair) {
            if (! isset($labels[mb_strtolower($pair['label'])])) {
                $this->unmatchedCodes[] = $symbol.' '.$pair['label'];
            }
        }

        $sizeLabels = array_column($priced, 'label');
        $link = (string) ($json['sizes']['link'] ?? '');

        return new B2bRemoteProduct(
            remoteId: $priced[0]['remote_id'],
            sku: $symbol,
            name: $name,
            category: $page['categories'] !== [] ? implode(' > ', $page['categories']) : null,
            sourceUrl: $url !== '' ? $url : ($link !== '' ? self::absoluteUrl($link) : null),
            raw: [
                'status' => 'ok',
                'price' => $cheapest,
                'manufacturer' => $manufacturer,
                'symbol' => $symbol,
                'unit' => $unit,
                'sizes' => $sizeLabels,
                'description' => $page['description'],
                'parameters' => $page['parameters'],
                'images' => $page['images'],
            ],
            availability: self::availabilityText($priced),
            variantSummary: 'Rozmiary: '.implode(', ', $sizeLabels),
            // jeden rozmiar — pozycja pojedyncza (cena z price()); kilka — każdy z własną ceną
            members: count($priced) > 1
                ? array_map(static fn (array $size): array => array_filter([
                    'remote_id' => $size['remote_id'],
                    'sku' => self::eanOf($codesByLabel[mb_strtolower($size['label'])] ?? []) ?? $size['remote_id'],
                    'name' => trim($name.' '.$size['label']),
                    'availability' => $size['amount'] !== null ? 'Stan: '.$size['amount'] : '',
                    'size' => $size['label'],
                    'price' => $prices[$size['remote_id']],
                ], static fn (mixed $value): bool => $value !== ''), $priced)
                : [],
            identifiers: self::identifiers($symbol, $priced, $codesByLabel),
        );
    }

    /**
     * Rozmiary z projector.php w kolejności przycisków strony (etykieta ze strony); rozmiar spoza strony — na końcu,
     * w kolejności „priority” sklepu, z etykietą z projector.php. Cena netto konta > 0 albo null; stan — liczba
     * sztuk ze sklepu albo null.
     *
     * @param  array<string, mixed>  $items  klucz „00036-18” = priorytet-id rozmiaru
     * @param  list<array{id: string, label: string}>  $pageSizes
     * @return list<array{remote_id: string, label: string, net: float|null, amount: int|null}>
     */
    private static function orderedSizes(int $productId, array $items, array $pageSizes): array
    {
        $byId = [];
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            $sizeId = self::sizeId((string) $key);
            $net = $item['prices']['price_net'] ?? null;
            $amount = $item['amount'] ?? null;
            $byId[$sizeId] = [
                'remote_id' => $productId.'-'.$sizeId,
                'label' => self::clean((string) ($item['name'] ?? '')),
                'net' => is_numeric($net) && (float) $net > 0 ? round((float) $net, 2) : null,
                // ujemny stan (IdoSell: -1) to nie liczba sztuk — bez stanu, bez zgadywania „bez limitu”
                'amount' => (is_int($amount) && $amount >= 0) || (is_string($amount) && ctype_digit($amount)) ? (int) $amount : null,
                'priority' => is_numeric($item['priority'] ?? null) ? (int) $item['priority'] : PHP_INT_MAX,
            ];
        }

        $out = [];
        foreach ($pageSizes as $pageSize) {
            if (isset($byId[$pageSize['id']])) {
                $size = $byId[$pageSize['id']];
                if ($pageSize['label'] !== '') {
                    $size['label'] = $pageSize['label'];
                }
                $out[] = $size;
                unset($byId[$pageSize['id']]);
            }
        }
        uasort($byId, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        array_push($out, ...array_values($byId));

        return array_map(static function (array $size): array {
            unset($size['priority']);
            if ($size['label'] === '') {
                $size['label'] = $size['remote_id'];
            }

            return $size;
        }, $out);
    }

    /**
     * Rozmiary z window.product_data strony: pary name/id rozmiarów tego produktu, w kolejności przycisków.
     *
     * @return list<array{id: string, label: string}>
     */
    private static function pageSizes(string $html, int $productId): array
    {
        $start = strpos($html, 'window.product_data');
        if ($start === false) {
            return [];
        }
        $block = substr($html, $start, 200_000);
        preg_match_all('/\bname:\s*"((?:[^"\\\\]|\\\\.)*)",\s*id:\s*"((?:[^"\\\\]|\\\\.)*)",\s*product_id:\s*(\d+)/u', $block, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $label, $id, $owner]) {
            if ((int) $owner !== $productId) {
                continue;
            }
            $label = json_decode('"'.$label.'"');
            $id = json_decode('"'.$id.'"');
            if (is_string($id) && $id !== '' && ! in_array($id, array_column($out, 'id'), true)) {
                $out[] = ['id' => $id, 'label' => self::clean(is_string($label) ? $label : '')];
            }
        }

        return $out;
    }

    /** Id rozmiaru z klucza pozycji projector.php („00036-18” → „18”, „00000-uniw” → „uniw”). */
    private static function sizeId(string $key): string
    {
        $parts = explode('-', $key, 2);

        return count($parts) === 2 && $parts[1] !== '' ? $parts[1] : $key;
    }

    /**
     * Symbol produktu i dla każdego rozmiaru „Kod producenta” ze strony (EAN — 8/12/13/14 cyfr; inny zapis jako kod
     * producenta), dosłownie.
     *
     * @param  list<array{remote_id: string, label: string}>  $sizes
     * @param  array<string, list<string>>  $codesByLabel
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(string $symbol, array $sizes, array $codesByLabel): array
    {
        $out = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $symbol, field: 'Symbol')];
        foreach ($sizes as $size) {
            foreach ($codesByLabel[mb_strtolower($size['label'])] ?? [] as $code) {
                $out[] = new B2bRemoteIdentifier(
                    type: self::isEan($code) ? ProductIdentifier::TYPE_EAN : ProductIdentifier::TYPE_MANUFACTURER_CODE,
                    value: $code,
                    remoteId: $size['remote_id'],
                    label: $size['label'],
                    field: self::PRODUCER_CODE,
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $codes
     */
    private static function eanOf(array $codes): ?string
    {
        foreach ($codes as $code) {
            if (self::isEan($code)) {
                return $code;
            }
        }

        return null;
    }

    private static function isEan(string $code): bool
    {
        return preg_match('/^(?:\d{8}|\d{12,14})$/', $code) === 1;
    }

    /**
     * Stan rozmiarów dosłownie: „Stan: 40: 6; 41: 0; 48.: 1”; bez stanu — null.
     *
     * @param  list<array{label: string, amount: int|null}>  $sizes
     */
    private static function availabilityText(array $sizes): ?string
    {
        $parts = [];
        foreach ($sizes as $size) {
            if ($size['amount'] !== null) {
                $parts[] = $size['label'].': '.$size['amount'];
            }
        }

        return $parts !== [] ? mb_substr('Stan: '.implode('; ', $parts), 0, 1000) : null;
    }

    /**
     * Waluta tylko z tekstu ceny podanego przez sklep („166,00 zł” → PLN); brak = null, bez domyślnej.
     *
     * @param  array<string, mixed>  $sizeprices
     */
    private static function currency(array $sizeprices): ?string
    {
        foreach (['price_net_formatted', 'price_formatted'] as $field) {
            $text = $sizeprices[$field] ?? null;
            if (! is_string($text)) {
                continue;
            }
            if (mb_stripos($text, 'zł') !== false) {
                return 'PLN';
            }
            if (preg_match('/(?<![A-Za-z])([A-Z]{3})(?![A-Za-z])/', $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Najtańszy rozmiar karty, ta sama reguła co w B2bCatalogSync::sizePricing (remis — B2bCatalogSync::winsSizePriceTie).
     *
     * @param  non-empty-list<B2bRemotePrice>  $prices
     */
    private static function cheapest(array $prices): B2bRemotePrice
    {
        $cheapest = $prices[0];
        foreach ($prices as $price) {
            if ($price->net < $cheapest->net - 0.0049
                || (abs($price->net - $cheapest->net) < self::EPSILON && B2bCatalogSync::winsSizePriceTie($price->base, $cheapest->base))) {
                $cheapest = $price;
            }
        }

        return $cheapest;
    }

    /**
     * Produkt, którego nie da się zapisać w tym przebiegu. Znane pozycje (rozmiary) idą jako pozycje bez cen —
     * przebieg widzi je jako pominięte (poza sprzątaniem).
     *
     * @param  list<string>  $positions
     */
    private function skipped(string $sku, string $name, string $reason, array $positions): B2bRemoteProduct
    {
        $name = $name !== '' ? $name : $sku;

        return new B2bRemoteProduct(
            remoteId: $positions[0] ?? $sku,
            sku: $sku,
            name: $name,
            raw: ['status' => 'skipped', 'reason' => $reason],
            members: count($positions) > 1
                ? array_map(static fn (string $position): array => ['remote_id' => $position, 'sku' => $position, 'name' => $name.' '.$position], $positions)
                : [],
        );
    }

    /** Pełny adres w sklepie ze ścieżki („/hpeciai/…jpg”); adres spoza sklepu albo pusty = null. */
    private static function absoluteUrl(string $src): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || str_contains($src, '..')) {
            return null;
        }
        $url = str_starts_with($src, '/') && ! str_starts_with($src, '//') ? DemarB2bClient::BASE.$src : $src;

        return DemarB2bClient::isShopUrl($url) ? $url : null;
    }

    /**
     * Tekst elementu bez odnośnika „Więcej” (a.dictionary__more) i bez dymka z opisem (.dictionary__description),
     * które sklep wstawia w nazwę parametru; brak elementu = ''.
     */
    private static function ownText(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && preg_match('/\bdictionary__(?:more|description)\b/', $child->getAttribute('class')) === 1) {
                continue;
            }
            $text .= $child instanceof DOMElement ? self::ownText($child) : $child->textContent;
        }

        return self::clean($text);
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
