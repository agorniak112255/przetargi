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
final class JspB2bConnector implements B2bConnector
{
    private const REASON_UNAVAILABLE = 'niedostępny w katalogu konta (sklep przekierował na stronę główną)';

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

    public function description(B2bRemoteProduct $product): string
    {
        $lines = array_map(static fn (string $feature): string => '- '.$feature, $product->raw['features'] ?? []);
        $unit = (string) ($product->raw['unit'] ?? '');
        if ($unit !== '') {
            $lines[] = ($lines !== [] ? "\n" : '').'Jednostka: '.$unit;
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
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
        $items = $xpath->query('//ul['.JspB2bClient::classPredicate('description-overview').']/li');
        if ($items === false || $items->length === 0) {
            $items = $xpath->query('//*['.JspB2bClient::classPredicate('ProductShortDescription').']//li');
        }
        $out = [];
        foreach ($items ?: [] as $li) {
            $text = self::text($li);
            if ($text !== '' && ! isset($out[$text])) {
                $out[$text] = true;
            }
        }

        return array_map('strval', array_keys($out));
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
