<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Support\ImageUrlBlocklist;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductImageDownloader
{
    private const MAX_BYTES = 5_000_000;

    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif' => 'gif',
    ];

    /** Najkrótszy bok: pasek 1000x60 to baner, nie zdjęcie. */
    private const MIN_SIDE = 100;

    /**
     * Pole: 150x150 (22 500) to miniatura, 190x417 (79 230) już zdjęcie, a schemat 172x111 (19 092) — miniatura.
     * Bez progu boku 180 px: kadry wąskich wyrobów (taśmy, listwy, odboje 600x160) to pełne zdjęcia.
     */
    private const MIN_AREA = 40_000;

    /**
     * Strona zapory pod nagłówkiem obrazka (Cloudflare, Incapsula) — odmowa chwilowa jak przy text/html, nie trwała.
     * Szukane małymi literami w początku treści.
     */
    private const FIREWALL_MARKERS = ['cf-chl', 'just a moment', 'attention required', '_incapsula_resource', 'challenge-platform'];

    /**
     * Odrzuca URL karty produktu (HTML) — wcześniej SKU w ścieżce dawało fałszywy „hit”.
     */
    public static function looksLikeImageUrl(string $url): bool
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if ($path === '' || str_ends_with($path, '/')) {
            return false;
        }
        // .png.webp / .jpg?itok=...
        if (preg_match('/\.(jpe?g|png|webp|gif|avif|bmp)(\.(webp|avif))?(\?|$)/i', $path) === 1) {
            return true;
        }
        // Ansell Sitecore PIM: …/065g_primary.ashx
        if (str_ends_with($path, '.ashx') && (
            str_contains($path, '/media/')
            || str_contains($path, '/pim/')
            || str_contains($path, 'product-assets')
        )) {
            return true;
        }
        // typowe CDN / media / Drupal / uvex shop-media (często bez rozszerzenia w path)
        if (preg_match('#/(media|shop-media|fileadmin|images?|img|cdn|static|uploads|assets|product[-_]?images?|sites/default/files|pim/products|product-assets)/#i', $path) === 1) {
            return true;
        }
        // CloudFront / imgproxy uvex: /images/{hash}/w:992/h:992/...
        if (str_contains($host, 'cloudfront.net') && preg_match('#^/images/[^/]+/#i', $path) === 1) {
            return true;
        }
        // Cloudflare Images: /{konto}/{id-obrazka}/{wariant} — nigdy bez rozszerzenia
        if ($host === 'imagedelivery.net' && preg_match('#^/[^/]+/[^/]+/[^/]+$#', $path) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     * @return list<ProductImage>
     */
    /**
     * Powody odrzucenia z ostatniego downloadMany — „obrazek za mały (190x417)”,
     * „HTTP 403”. Bez tego produkt dostawał „źródła zwróciły błędne URL”, choć
     * adresy były dobre, a odpadał rozmiar.
     *
     * @var array<string, string>
     */
    private array $failures = [];

    /**
     * Adresy z ostatniego downloadMany, których źródło chwilowo odmówiło: strona zapory zamiast pliku
     * (Incapsula na ansell.com), 403, 429, 5xx. Ta sama karta raz przechodzi, raz nie — warto ponowić
     * później (products:retry-images). 404, za mały obrazek czy zły typ pliku nie wracają.
     *
     * @var list<string>
     */
    private array $retryLater = [];

    /** Kod wyjątku downloadOne: odmowa chwilowa, adres trafia do $retryLater. */
    private const RETRY_LATER = 4290;

    public function __construct(private readonly PublicUrlFetcher $urls = new PublicUrlFetcher) {}

    /** @return array<string, string> url => powód */
    public function lastFailures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function lastRetryLaterUrls(): array
    {
        return $this->retryLater;
    }

    /**
     * Przekroczony czas, zerwane połączenie, a także pętla 302 Incapsuli (302 na ten sam adres z ciasteczkiem —
     * Guzzle kończy ją po 5 przekierowaniach, Laravel owija w ConnectionException) — za kilka godzin może przejść.
     * Nie: nieznany host i plik większy niż limit (ansell.com: 81 MB, czas mija przy 2 MB za każdym razem).
     */
    private static function isTransientConnectionFailure(Throwable $e): bool
    {
        if (! $e instanceof ConnectionException && ! $e instanceof TooManyRedirectsException) {
            return false;
        }
        $message = $e->getMessage();
        if (str_contains($message, 'Could not resolve host')) {
            return false;
        }

        return ! (preg_match('/out of (\d+) bytes received/', $message, $m) === 1 && (int) $m[1] > self::MAX_BYTES);
    }

    public function downloadMany(Product $product, array $urls, int $max = 5): array
    {
        $saved = [];
        // Karta może już mieć zdjęcia z witryny dostawcy — zaczynanie od zera dawało drugi wiersz
        // z numerem 0 i drugie zdjęcie główne, a po chwili zdjęcie z sieci wracało na wierzch.
        $sort = (int) (ProductImage::query()->where('product_id', $product->id)->max('sort_order') ?? -1) + 1;
        $this->failures = [];
        $this->retryLater = [];
        $profile = $urls !== [] ? $this->profileFor($product) : null;

        foreach (array_values(array_unique($urls)) as $url) {
            if (count($saved) >= $max) {
                break;
            }
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }
            if (ProductImageRejection::blocksUrl((int) $product->id, $url)) {
                $this->failures[$url] = 'Zdjęcie usunięte wcześniej z tej karty';

                continue;
            }
            // grafika witryny (logo, baner, zaślepka) albo reklama producenta z profilu — bez pobierania
            $blocked = ImageUrlBlocklist::blocked($url, $profile);
            if ($blocked !== null) {
                $this->failures[$url] = $blocked;
                Log::info('Product image download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => $blocked,
                ]);

                continue;
            }
            if (! self::looksLikeImageUrl($url)) {
                $this->failures[$url] = 'URL nie wygląda na plik obrazka';
                Log::info('Product image download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => 'URL nie wygląda na plik obrazka',
                ]);

                continue;
            }

            try {
                $image = $this->downloadOne($product, $url, $sort);
            } catch (Throwable $e) {
                $this->failures[$url] = $e->getMessage();
                if ($e->getCode() === self::RETRY_LATER || self::isTransientConnectionFailure($e)) {
                    $this->retryLater[] = $url;
                }
                Log::info('Product image download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($image === null) {
                continue;
            }

            $saved[] = $image;
            $sort++;
        }

        if ($saved !== []) {
            ProductImage::resequence((int) $product->id);
        }

        return $saved;
    }

    /**
     * Klucz pliku zdjęcia: dwa adresy o tym samym kluczu to dla karty ten sam obraz.
     *
     * Shopify wydaje ten sam plik pod domeną sklepu i pod cdn.shopify.com, a do tego dokleja żądany rozmiar
     * w zapytaniu („…/cdn/shop/files/AROX.png?v=17&width=1728”). Pobrane osobno dają różne bajty, więc dedup
     * po sumie kontrolnej ich nie scala — karta dostawała dwa wiersze z tym samym butem. Poza Shopify kluczem
     * jest cały adres: u dystrybutorów zapytanie bywa jedynym, co odróżnia dwa różne obrazy.
     */
    public static function sameFileKey(string $url): string
    {
        $url = self::preferFullSizeUrl($url);
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (preg_match('#^/cdn/shop/files/([^/]+)$#i', $path, $m) === 1
            || ($host === 'cdn.shopify.com' && preg_match('#^/s/files/[\d/]+/files/([^/]+)$#i', $path, $m) === 1)) {
            return 'shopify:'.mb_strtolower($m[1]);
        }

        return mb_strtolower($url);
    }

    /**
     * Miniatury sklepów → wariant pełny (WP, Demar, Presta, Magento cache/hash).
     */
    public static function preferFullSizeUrl(string $url): string
    {
        $url = preg_replace('/-(\d{2,4})x(\d{2,4})(\.(jpe?g|png|webp))$/i', '$3', $url) ?? $url;
        $url = preg_replace('/_([sm])(\.(jpe?g|png|webp))$/i', '$2', $url) ?? $url;
        $url = preg_replace(
            '#/(\d+)-(?:medium_default|home_default|pdt_\d+|small_default)/#i',
            '/$1-large_default/',
            $url
        ) ?? $url;
        // ICD / Magento: …/cache/{32hex}/f/a/plik.jpg → oryginał katalogu (nie miniatura 80×80)
        $url = preg_replace('#(/media/catalog/product/)cache/[a-f0-9]{32}/#i', '$1', $url) ?? $url;
        // Shoper: productGfx_{id}_750_750 → _0_0 (oryginał). 0_0 to pełny rozmiar, nie pusta miniatura.
        $url = preg_replace_callback(
            '#(/environment/cache/images/productGfx_)(\d+)_(\d+)_(\d+)/#i',
            static function (array $m): string {
                $w = (int) $m[3];
                $h = (int) $m[4];
                if (($w === 0 && $h === 0) || ($w >= 400 && $h >= 400)) {
                    return $m[1].$m[2].'_0_0/';
                }

                return $m[0];
            },
            $url
        ) ?? $url;

        return $url;
    }

    /** Cache Shoper → oryginał /userdata/public/gfx/{id}/plik.jpg */
    public static function shoperOriginalUrl(string $url): ?string
    {
        if (preg_match(
            '~^(https?://[^/]+)/environment/cache/images/productGfx_(\d+)_\d+_\d+/([^/?]+)~i',
            $url,
            $m
        ) !== 1) {
            return null;
        }
        $file = preg_replace('/\.(webp|avif)$/i', '.jpg', $m[3]) ?? $m[3];

        return $m[1].'/userdata/public/gfx/'.$m[2].'/'.$file;
    }

    /**
     * Grafika witryny Ansella, nie zdjęcie wyrobu: widżet doboru rozmiaru („glove-size-finder/chemical.ashx”
     * trafiło jako zdjęcie karty RINGERS), piktogramy norm z taksonomii PIM, ikony (social media, zmiana
     * regionu) i grafika zrównoważonego rozwoju. Leżą pod tym samym /-/media/ co packshoty i są poprawnymi
     * obrazkami, więc żaden późniejszy próg ich nie zatrzymuje.
     */
    public static function isManufacturerSiteGraphicUrl(string $url): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host !== 'ansell.com' && ! str_ends_with($host, '.ansell.com')) {
            return false;
        }
        $path = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return preg_match('#/(?:glove-size-finder|pim/taxonomy|icon|sustainability)/#', $path) === 1;
    }

    /**
     * Logo albo ikona witryny, nie zdjęcie wyrobu: WordPress zapisuje przycięte logo i ikonę witryny jako „cropped-…”
     * (portolana.pl: cropped-photo_2025-07-14_18-39-36-Edited-1.png jako og:image karty Ringers 259, 05.10.2026),
     * ikonę także jako „site-icon…”. Patrzymy tylko na nazwę pliku — „cropped” w środku nazwy (zdjęcie przycięte
     * przez sklep) zostaje.
     */
    public static function isSiteIdentityGraphicUrl(string $url): bool
    {
        $file = mb_strtolower(urldecode(basename((string) (parse_url($url, PHP_URL_PATH) ?? ''))));

        return str_starts_with($file, 'cropped-') || str_contains($file, 'site-icon');
    }

    /** Zdjęcie wyrobu w użyciu („ringers-074-chemical-application---examining-barrels.ashx”), nie packshot. */
    public static function isApplicationShotUrl(string $url): bool
    {
        $path = mb_strtolower(urldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return str_contains(basename($path), 'application');
    }

    /**
     * Packshot karty („ringers074.ashx”) przed jej zdjęciami z zastosowania — przy jednym zdjęciu
     * na kartę głównym zostawało zdjęcie beczek. Karta to host i katalog pliku (Ansell trzyma pliki
     * modelu w …/product-assets/ringers/r-074/). Zmienia się tylko kolejność w obrębie jednej karty;
     * pozostałe adresy zostają na swoich miejscach, a karta bez packshotu — bez zmian.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    public static function packshotsFirst(array $urls): array
    {
        $urls = array_values($urls);
        $positions = [];
        foreach ($urls as $i => $url) {
            $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            $dir = dirname(mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? '')));
            $positions[$host.$dir][] = $i;
        }

        $out = $urls;
        foreach ($positions as $group) {
            $packshots = [];
            $shots = [];
            foreach ($group as $i) {
                if (self::isApplicationShotUrl($urls[$i])) {
                    $shots[] = $urls[$i];
                } else {
                    $packshots[] = $urls[$i];
                }
            }
            if ($packshots === [] || $shots === []) {
                continue;
            }
            foreach ([...$packshots, ...$shots] as $k => $url) {
                $out[$group[$k]] = $url;
            }
        }

        return $out;
    }

    /** Shoper: _120_120 / _300_300 to kafle; _0_0 i ≥400 to karta. */
    public static function isSmallShoperCacheUrl(string $url): bool
    {
        if (preg_match('#/productgfx_\d+_(\d+)_(\d+)/#i', $url, $sm) !== 1) {
            return false;
        }
        $w = (int) $sm[1];
        $h = (int) $sm[2];

        return ($w > 0 && $w < 400) || ($h > 0 && $h < 400);
    }

    private function downloadOne(Product $product, string $url, int $sortOrder): ?ProductImage
    {
        $url = self::preferFullSizeUrl($url);
        $original = self::shoperOriginalUrl($url);
        if ($original !== null) {
            $url = $original;
        }

        // tylko serwery z adresem publicznym, każde przekierowanie sprawdzone (PublicUrlFetcher)
        $response = $this->urls->get(fn () => Http::timeout(12)
            ->connectTimeout(4)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                // Preferuj JPEG/WebP — część CDN (uvex) i tak zwróci AVIF; obsługujemy też AVIF.
                'Accept' => 'image/jpeg,image/webp,image/png,image/*,*/*;q=0.8',
                'Referer' => $this->refererFor($url),
            ]), $url, self::MAX_BYTES);

        $bytes = $response->successful() ? $response->body() : '';
        $mime = (string) ($response->header('Content-Type') ?: '');
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        $blocked = ! $response->successful()
            || $bytes === ''
            || str_contains($mime, 'text/html')
            || str_contains($mime, 'application/json');
        // Bez zrzutu przez Jinę: dla adresu pliku (nie strony HTML) r.jina.ai nie oddaje ani bajtów,
        // ani zrzutu — tylko 200 text/plain „Markdown Content: undefined” (bpbhp .jpg, Ansell .ashx,
        // sprawdzone 24.09.2026), a dla nieistniejącego pliku zrzut strony 404 w PNG, który szedłby
        // na kartę jako zdjęcie. Zablokowany plik wraca do ponowienia (products:retry-images).
        if ($blocked) {
            $status = $response->status();
            // strona zapory zamiast pliku albo odmowa chwilowa — nie 404 i nie błąd klienta
            $later = $response->successful() || in_array($status, [403, 429], true) || $status >= 500;
            throw new \RuntimeException(
                $response->successful()
                    ? 'Odpowiedź nie jest obrazem ('.$mime.')'
                    : 'HTTP '.$status,
                $later ? self::RETRY_LATER : 0
            );
        }

        $size = strlen($bytes);
        if ($bytes === '' || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Pusty lub zbyt duży plik');
        }
        $headerMime = $mime;
        if ($mime === '' || ! isset(self::ALLOWED_MIME[$mime])) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string) $finfo->buffer($bytes));
        }
        if (! isset(self::ALLOWED_MIME[$mime])) {
            throw new \RuntimeException('Niedozwolony typ MIME: '.$mime);
        }
        // GIF loadery Magento; zdjęcia produktów prawie zawsze JPG/PNG/WebP
        if ($mime === 'image/gif' && $size < 80_000) {
            throw new \RuntimeException('Pominięto mały GIF (loader/spinner)');
        }
        $dim = @getimagesizefromstring($bytes);
        if (! is_array($dim)) {
            // Nagłówek image/jpeg, a w środku strona „404 Not Found nginx” albo ekran weryfikacji Cloudflare: GD nie
            // czytał wymiarów i zapis szedł dalej — strona lądowała na karcie jako .jpg (HR Matting 10799/10801/10806,
            // checkrego 11089; audyt Coby 08.10.2026). Bajty, które wyglądają na obraz, a GD ich nie czyta, zostają
            // jak dotąd — bez pomiaru. Strona zapory wraca do ponowienia jak przy nagłówku text/html; inna strona
            // (404 nginx) to trwałe odrzucenie.
            $reason = self::nonImageContentReason($bytes, $headerMime);
            if ($reason !== null) {
                $head = strtolower(substr($bytes, 0, 65_536));
                foreach (self::FIREWALL_MARKERS as $marker) {
                    if (str_contains($head, $marker)) {
                        throw new \RuntimeException('Strona zapory zamiast obrazu ('.$marker.')', self::RETRY_LATER);
                    }
                }
                throw new \RuntimeException($reason);
            }
        } else {
            $w = (int) ($dim[0] ?? 0);
            $h = (int) ($dim[1] ?? 0);
            // miniatury WP (-80x80) i placeholdery — za małe na kartę produktu.
            // Packshot kombinezonu z cas-technik ma 190x417: wąski, ale to pełne
            // zdjęcie — liczy się pole i najkrótszy bok, nie sam próg 200 px.
            if ($w > 0 && $h > 0 && ($w < self::MIN_SIDE || $h < self::MIN_SIDE || $w * $h < self::MIN_AREA)) {
                throw new \RuntimeException("Obrazek za mały ({$w}x{$h}) — miniatura/placeholder");
            }
        }

        return $this->storeBytes($product, $bytes, $mime, $url, $sortOrder);
    }

    /**
     * Treść, która nie jest obrazem mimo nagłówka obrazka: strona HTML („<!DOCTYPE”, „<html”), grafika SVG albo
     * dokument XML (SVG nie jest przyjmowanym formatem — ALLOWED_MIME), a dalej wszystko, co libmagic rozpoznaje
     * jako inny typ (JSON, zwykły tekst). Null, gdy bajty wyglądają na obraz.
     */
    private static function nonImageContentReason(string $bytes, string $headerMime): ?string
    {
        $head = substr($bytes, 0, 512);
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }
        $head = strtolower(ltrim($head));
        $header = $headerMime !== '' ? ' (nagłówek '.$headerMime.')' : '';
        foreach (['<!doctype', '<html', '<head', '<body'] as $tag) {
            if (str_starts_with($head, $tag)) {
                return 'To strona HTML, nie obraz'.$header;
            }
        }
        if (str_starts_with($head, '<?xml') || str_starts_with($head, '<svg')) {
            return (str_contains($head, '<svg') ? 'To grafika SVG, nie zdjęcie' : 'To dokument XML, nie obraz').$header;
        }
        $sniffed = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if (isset(self::ALLOWED_MIME[$sniffed])) {
            return null;
        }

        return 'Odpowiedź nie jest obrazem ('.$sniffed.')'.$header;
    }

    /** Profil producenta karty — jak ProductEnrichmentService::profiles(), przez kontener (konstruktor bez zmian). */
    private function profileFor(Product $product): ?ManufacturerProfile
    {
        return app(ManufacturerProfiles::class)->for($product);
    }

    /**
     * Zapis pobranych już bajtów (np. z API B2B dostawcy, gdzie plik wymaga tokenu).
     * $sourceUrl trafia do bazy — bez tokenów w query.
     */
    public function storeBytes(
        Product $product,
        string $bytes,
        string $mime,
        string $sourceUrl,
        int $sortOrder,
        ?int $b2bAccountId = null,
    ): ?ProductImage {
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if (! isset(self::ALLOWED_MIME[$mime])) {
            return null;
        }
        $size = strlen($bytes);
        if ($bytes === '' || $size > self::MAX_BYTES) {
            return null;
        }

        $checksum = hash('sha256', $bytes);
        // usunięte z karty świadomie (panel, audyt zdjęć) — ani pod tym adresem, ani ten sam plik pod innym
        if (ProductImageRejection::blocksUrl((int) $product->id, $sourceUrl)
            || ProductImageRejection::blocksChecksum((int) $product->id, $checksum)) {
            return null;
        }
        $existing = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('checksum', $checksum)
            ->first();
        if ($existing !== null) {
            // Ten sam plik pod innym adresem: karta ma go z wcześniejszego pobrania, ale zapisany
            // z cudzym adresem. Bez dopisania bieżącego źródła pobieralibyśmy go przy każdym przebiegu
            // (dedup po adresie nigdy by nie trafił), a licznik za każdym razem meldowałby nowe zdjęcie.
            $patch = [];
            if ((string) $existing->source_url !== mb_substr($sourceUrl, 0, 2000)) {
                $patch['source_url'] = mb_substr($sourceUrl, 0, 2000);
            }
            if ($b2bAccountId !== null && $existing->b2b_account_id === null) {
                $patch['b2b_account_id'] = $b2bAccountId;
            }
            if ($patch !== []) {
                $existing->forceFill($patch)->save();
            }

            return $existing;
        }

        $ext = self::ALLOWED_MIME[$mime];
        $relative = 'products/'.$product->id.'/'.Str::lower(Str::random(16)).'.'.$ext;
        Storage::disk('public')->put($relative, $bytes);

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $b2bAccountId,
            'path' => $relative,
            'source_url' => mb_substr($sourceUrl, 0, 2000),
            'is_primary' => $sortOrder === 0,
            'sort_order' => $sortOrder,
            'checksum' => $checksum,
        ]);
    }

    private function refererFor(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $scheme.'://'.$host.'/' : 'https://www.google.com/';
    }
}
