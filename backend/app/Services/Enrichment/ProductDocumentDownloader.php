<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\B2b\B2bDocumentText;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductDocumentDownloader
{
    private const MAX_BYTES = 15_000_000;

    /** Pliki z panelu B2B: tylko te typy trafiają na kartę (wartość = rozszerzenie pliku na dysku). */
    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Hasła dopasowywane jako fragment (po normalizacji adresu/etykiety).
     *
     * @var list<string>
     */
    private const JUNK_DOCUMENT_PHRASES = [
        // korporacyjne / marketingowe
        'sustainability', 'nachhaltig', 'annual report', 'jahresbericht',
        'privacy', 'datenschutz', 'cookie', 'terms of', 'imprint', 'impressum',
        'newsletter', 'press release', 'investor', 'code of conduct', 'compliance report',
        'return policy', 'refund', 'shipping policy',
        // polskie / konsumenckie
        'polityka', 'regulamin', 'prywatnosc', 'ciasteczk',
        'ochrona danych', 'ochrony danych', 'dane osobowe', 'danych osobowych',
        'klauzula informacyjna', 'przetwarzania danych', 'przetwarzanie danych',
        'reklamacj', 'odstapien', 'zwrot towaru', 'zwrotu towaru', 'zwroty', 'zwrotow',
        'formularz', 'platnosci', 'warunki dostawy', 'zasady dostawy', 'koszty dostawy',
        'sposoby dostawy', 'czas dostawy', 'warunki sprzedazy', 'warunki wspolpracy',
        'ogolne warunki',
    ];

    /**
     * Hasła dopasowywane całym słowem — „rodo” jako fragment siedzi w „środowisko”,
     * a deklaracja środowiskowa jest dokumentem wyrobu.
     *
     * @var list<string>
     */
    private const JUNK_DOCUMENT_WORDS = ['rodo', 'gdpr', 'agb', 'platnosc', 'dostawa'];

    /**
     * Krótszy tekst z PDF to skan bez warstwy tekstowej albo sama metryczka — wtedy o przyjęciu
     * dokumentu decyduje jak dawniej adres. Prawdziwy certyfikat ze skanu nie może wypaść.
     */
    private const MIN_TEXT_FOR_MATCH = 400;

    /** Ile plików na jeden przebieg wolno przepuścić przez pdftotext (podproces z limitem czasu). */
    private const TEXT_CHECK_BUDGET_FACTOR = 2;

    /** Zostaje z downloadMany: ile jeszcze plików wolno odczytać w tym przebiegu. */
    private int $textBudget = 0;

    public function __construct(
        private readonly BlockedPageReader $blockedPages = new BlockedPageReader,
        private readonly ProductSearchIdentity $identity = new ProductSearchIdentity,
        private readonly B2bDocumentText $documentText = new B2bDocumentText,
    ) {}

    public static function looksLikePdfUrl(string $url): bool
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $full = mb_strtolower($url);

        return str_ends_with($path, '.pdf')
            || str_contains($full, '.pdf?')
            || str_contains($full, '/pdf/')
            || str_contains($full, 'filetype=pdf');
    }

    /**
     * Dokumenty „obsługi klienta” i korporacyjne: polityka prywatności, regulamin, RODO,
     * reklamacje, zwroty, warunki dostawy, raport CSR. Do zakładki „Pliki PDF” trafiały,
     * bo filtr znał wyłącznie hasła angielskie i niemieckie — polskie przechodziły.
     *
     * Jedna lista dla karty HTML (ProductPageFetcher) i dla readera (BlockedPageReader):
     * dwie kopie takiej listy już raz w tym projekcie rozjechały się między miejscami.
     *
     * @param  string  $hay  adres URL i/lub etykieta linku (dowolna wielkość liter)
     */
    public static function looksLikeJunkDocument(string $hay): bool
    {
        $norm = self::normalizeDocumentHay($hay);
        if (trim($norm) === '') {
            return false;
        }
        foreach (self::JUNK_DOCUMENT_PHRASES as $needle) {
            if (str_contains($norm, $needle)) {
                return true;
            }
        }
        foreach (self::JUNK_DOCUMENT_WORDS as $word) {
            if (str_contains($norm, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adres i etykieta do porównania z listą haseł: bez kodowania %20, bez polskich znaków
     * (URL-e bywają w ASCII), z myślnikiem/podkreśleniem/ukośnikiem zamienionym na spację —
     * „polityka-prywatnosci.pdf”, „polityka_prywatności” i „polityka%20prywatności” to jedno.
     * Spacje po bokach pozwalają dopasować hasła całym słowem (RODO vs „środowisko”).
     */
    private static function normalizeDocumentHay(string $hay): string
    {
        $low = mb_strtolower(urldecode($hay));
        $low = strtr($low, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        ]);
        $low = preg_replace('/[^a-z0-9]+/u', ' ', $low) ?? $low;

        return ' '.trim((string) preg_replace('/\s+/u', ' ', $low)).' ';
    }

    /**
     * PDF + trasy producenta (Ansell /pds|/doc|/ukdoc, IFU .ashx).
     */
    public static function looksLikeDocumentUrl(string $url): bool
    {
        if (self::looksLikePdfUrl($url)) {
            return true;
        }
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $full = mb_strtolower($url);

        if (preg_match('#/(pds|doc|ukdoc)(/|$)#i', $path) === 1) {
            return true;
        }
        if (str_ends_with($path, '.ashx') && preg_match(
            '#(ifu|datasheet|declaration|deklar|conform|pdb|pds|certificate|certyfik)#i',
            $full
        ) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Plik z zalogowanego panelu dostawcy — bajty przynosi łącznik B2B, bo adres wymaga sesji konta (tu nie ma
     * jak go pobrać samemu). Nazwa i rodzaj pochodzą ze strony dostawcy, nie ze zgadywania z adresu.
     *
     * Ten sam plik pod tym samym adresem = nic nie pobieramy ponownie; plik zmieniony u dostawcy (inna suma
     * kontrolna) podmienia poprzedni, razem z odczytanym tekstem.
     *
     * @param  string  $kind  ProductDocument::KIND_*
     * @param  string|null  $text  tekst odczytany z pliku; null = nie próbowano odczytać
     * @param  int|null  $b2bAccountId  konto, z którego panelu plik pochodzi — uzupełnianie AI takich nie kasuje
     * @return ProductDocument|null null = typ pliku nieobsługiwany albo rozmiar poza limitem
     */
    public function storeBytes(
        Product $product,
        string $bytes,
        string $mime,
        string $sourceUrl,
        string $title,
        string $kind,
        int $sortOrder,
        ?string $text = null,
        ?int $b2bAccountId = null,
        int $maxBytes = self::MAX_BYTES,
    ): ?ProductDocument {
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        $extension = self::ALLOWED_MIME[$mime] ?? null;
        $size = strlen($bytes);
        if ($extension === null || $size === 0 || $size > $maxBytes) {
            return null;
        }

        $checksum = hash('sha256', $bytes);
        $url = mb_substr($sourceUrl, 0, 2000);
        $existing = ProductDocument::query()
            ->where('product_id', $product->id)
            ->where('source_url', $url)
            ->first()
            ?? ProductDocument::query()
                ->where('product_id', $product->id)
                ->where('checksum', $checksum)
                ->first();

        if ($existing !== null && (string) $existing->checksum === $checksum) {
            // ten sam plik — uzupełniamy tylko tekst, gdy wcześniej go nie odczytano
            if ($text !== null && $existing->text === null) {
                $existing->forceFill(['text' => $text])->save();
            }

            return $existing;
        }

        $relative = 'products/'.$product->id.'/docs/'.Str::lower(Str::random(16)).'.'.$extension;
        Storage::disk('public')->put($relative, $bytes);
        $values = [
            'product_id' => $product->id,
            'b2b_account_id' => $b2bAccountId,
            'path' => $relative,
            'source_url' => $url,
            'title' => mb_substr($title, 0, 255),
            'text' => $text,
            'kind' => $kind,
            'sort_order' => $sortOrder,
            'checksum' => $checksum,
            'size_bytes' => $size,
        ];

        if ($existing === null) {
            return ProductDocument::query()->create($values);
        }

        $previous = (string) $existing->path;
        $existing->forceFill($values)->save();
        if ($previous !== '' && $previous !== $relative) {
            Storage::disk('public')->delete($previous);
        }

        return $existing;
    }

    /**
     * @param  list<string>  $urls
     * @return list<ProductDocument>
     */
    public function downloadMany(Product $product, array $urls, int $max = 3): array
    {
        $saved = [];
        $sort = 0;
        $this->textBudget = max(1, $max * self::TEXT_CHECK_BUDGET_FACTOR);

        foreach ($this->rankUrls($urls) as $url) {
            if (count($saved) >= $max) {
                break;
            }
            if (! is_string($url) || ! self::looksLikeDocumentUrl($url)) {
                continue;
            }
            // adresy przychodzą też z wyszukiwarki, nie tylko z karty — regulamin sklepu
            // odsiewamy w jednym miejscu dla wszystkich źródeł
            if (self::looksLikeJunkDocument($url)) {
                continue;
            }

            try {
                $doc = $this->downloadOne($product, $url, $sort);
            } catch (Throwable $e) {
                Log::info('Product PDF download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($doc === null) {
                continue;
            }

            $saved[] = $doc;
            $sort++;
        }

        return $saved;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function rankUrls(array $urls): array
    {
        $scored = [];
        foreach (array_values(array_unique($urls)) as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }
            $u = mb_strtolower(urldecode($url));
            $score = 10;
            if (preg_match('#(cert|conform|declaration|deklarac|zgodno|doc|ue|eu[-_]?doc|oeko|oeeko|reach)#iu', $u)) {
                $score += 80;
            }
            if (preg_match('#(datasheet|data[-_]?sheet|pds|tds|spec|karta|pdb)#i', $u)) {
                $score += 50;
            }
            if (preg_match('#/(pds|doc|ukdoc)(/|$)#i', $u)) {
                $score += 70;
            }
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '.pdf')) {
                $score += 20;
            }
            $scored[] = ['url' => $url, 'score' => $score];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $r): string => $r['url'], $scored);
    }

    private function downloadOne(Product $product, string $url, int $sortOrder): ?ProductDocument
    {
        $response = Http::timeout(20)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'application/pdf,*/*;q=0.8',
            ])
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            $fallback = $this->downloadBlockedDocument($product, $url, $sortOrder);
            if ($fallback !== null) {
                return $fallback;
            }
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $bytes = $response->body();
        $size = strlen($bytes);
        if ($bytes === '' || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Pusty lub zbyt duży PDF');
        }
        if (! str_starts_with($bytes, '%PDF')) {
            // Ansell /pds|/doc czasem zwraca HTML z linkiem do PDF albo challenge
            $fromHtml = $this->extractPdfUrlFromHtml($bytes, $url);
            if ($fromHtml !== null && $fromHtml !== $url) {
                return $this->downloadOne($product, $fromHtml, $sortOrder);
            }
            $fallback = $this->downloadBlockedDocument($product, $url, $sortOrder);
            if ($fallback !== null) {
                return $fallback;
            }
            throw new \RuntimeException('Plik nie wygląda na PDF');
        }

        $text = $this->readPdfText($bytes);
        $reason = $this->textRejects($text, $url, $product);
        if ($reason !== null) {
            Log::info('Product PDF rejected by content', [
                'product_id' => $product->id,
                'url' => $url,
                'reason' => $reason,
            ]);

            return null;
        }

        return $this->storePdfBytes($product, $bytes, $url, $sortOrder, $text !== '' ? $text : null);
    }

    /**
     * Tekst pliku tą samą drogą co karty techniczne z paneli B2B (pdftotext w osobnym procesie
     * z limitem czasu). Wyjątek albo timeout nie może wywrócić wzbogacania: pusty tekst = decyduje adres.
     */
    private function readPdfText(string $bytes): string
    {
        if ($this->textBudget <= 0) {
            return '';
        }
        $this->textBudget--;

        try {
            return $this->documentText->fromFile($bytes, 'application/pdf');
        } catch (Throwable $e) {
            Log::info('Product PDF text extraction failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Powód odrzucenia dokumentu po treści albo null, gdy dokument zostaje.
     *
     * Dwie bramki: treść regulaminu/polityki prywatności wyrzuca plik zawsze, a brak jakiejkolwiek
     * wzmianki o wyrobie — tylko gdy tekst w ogóle się wyciągnął i jest sensownej długości ORAZ gdy
     * sam adres też nie wiąże pliku z wyrobem. Skan bez warstwy tekstowej zostaje: lepiej zachować
     * niepewny dokument niż wyrzucić prawdziwy certyfikat z obrazka.
     */
    private function textRejects(string $text, string $url, Product $product): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        if (self::looksLikePolicyText($text)) {
            return 'treść to polityka prywatności / regulamin';
        }
        if (mb_strlen($text) < self::MIN_TEXT_FOR_MATCH) {
            return null;
        }
        if ($this->identity->hayHasProductCode(mb_strtolower(urldecode($url)), $product)) {
            return null;
        }
        if ($this->textMentionsProduct($text, $product)) {
            return null;
        }

        return 'treść nie wspomina o tym wyrobie';
    }

    /** Dokument mówi wprost, czym jest — nagłówek/pierwsza strona regulaminu albo polityki prywatności. */
    private static function looksLikePolicyText(string $text): bool
    {
        $head = self::normalizeDocumentHay(mb_substr($text, 0, 2000));
        foreach ([
            'polityka prywatnosci', 'polityki prywatnosci', 'privacy policy', 'privacy notice',
            'regulamin sklepu', 'regulamin serwisu', 'regulamin swiadczenia uslug',
            'ogolne warunki sprzedazy', 'ogolne warunki handlowe', 'terms and conditions',
            'klauzula informacyjna', 'administratorem danych osobowych', 'administratorem panstwa danych',
            'polityka plikow cookies', 'polityka cookies',
            'rozporzadzenie parlamentu europejskiego i rady ue 2016 679',
        ] as $needle) {
            if (str_contains($head, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kod artykułu, alias modelu albo wyróżniający token nazwy w treści pliku — narzędzia tożsamości
     * z ProductSearchIdentity, te same co przy ocenie kart i wyników wyszukiwania.
     */
    private function textMentionsProduct(string $text, Product $product): bool
    {
        $hay = mb_strtolower($text);
        if ($this->identity->hayHasProductCode($hay, $product)) {
            return true;
        }
        if ($this->identity->hayHasSpecificNameToken($hay, $product)) {
            return true;
        }
        $compact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        foreach ($this->identity->modelAliases($product) as $alias) {
            $alias = mb_strtolower(trim($alias));
            if (mb_strlen($alias) < 3) {
                continue;
            }
            $aliasCompact = preg_replace('/[^a-z0-9]+/iu', '', $alias) ?? $alias;
            if (str_contains($hay, $alias) || ($aliasCompact !== '' && str_contains($compact, $aliasCompact))) {
                return true;
            }
        }

        return false;
    }

    private function downloadBlockedDocument(
        Product $product,
        string $url,
        int $sortOrder,
    ): ?ProductDocument {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (! str_contains($host, 'ansell.com')
            || preg_match('#/(pds|doc|ukdoc)/#i', $path) !== 1) {
            return null;
        }

        $imageBytes = $this->blockedPages->fetchScreenshot($url);
        if ($imageBytes === null) {
            return null;
        }
        $mime = str_starts_with($imageBytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
        $pdfBytes = $this->renderImageAsPdf($imageBytes, $mime);

        return $this->storePdfBytes($product, $pdfBytes, $url, $sortOrder);
    }

    private function renderImageAsPdf(string $imageBytes, string $mime): string
    {
        $dompdf = new Dompdf;
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(
            '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page{margin:0}html,body{margin:0;padding:0}img{width:100%;height:auto;display:block}'
            .'</style></head><body><img src="data:'.$mime.';base64,'
            .base64_encode($imageBytes)
            .'"></body></html>'
        );
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  string|null  $text  tekst odczytany z pliku; null = nie udało się odczytać (skan, timeout)
     */
    private function storePdfBytes(
        Product $product,
        string $bytes,
        string $sourceUrl,
        int $sortOrder,
        ?string $text = null,
    ): ProductDocument {
        $size = strlen($bytes);
        if (! str_starts_with($bytes, '%PDF') || $size === 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Nie udało się utworzyć prawidłowego PDF');
        }

        $checksum = hash('sha256', $bytes);
        $existing = ProductDocument::query()
            ->where('product_id', $product->id)
            ->where('checksum', $checksum)
            ->first();
        if ($existing !== null) {
            // ten sam plik — uzupełniamy tylko tekst, gdy wcześniej go nie odczytano
            if ($text !== null && $existing->text === null) {
                $existing->forceFill(['text' => $text])->save();
            }

            return $existing;
        }

        $relative = 'products/'.$product->id.'/docs/'.Str::lower(Str::random(16)).'.pdf';
        Storage::disk('public')->put($relative, $bytes);

        $kind = $this->guessKind($sourceUrl);
        $title = $this->guessTitle($sourceUrl, $kind);

        return ProductDocument::query()->create([
            'product_id' => $product->id,
            'path' => $relative,
            'source_url' => mb_substr($sourceUrl, 0, 2000),
            'title' => $title,
            'text' => $text,
            'kind' => $kind,
            'sort_order' => $sortOrder,
            'checksum' => $checksum,
            'size_bytes' => $size,
        ]);
    }

    private function extractPdfUrlFromHtml(string $html, string $pageUrl): ?string
    {
        if (str_contains(mb_strtolower($html), 'incapsula') || str_contains(mb_strtolower($html), '_incapsula_resource')) {
            return null;
        }
        if (preg_match('#https?://[^"\'\s<>]+\.pdf(?:\?[^"\'\s<>]*)?#i', $html, $m) === 1) {
            return $m[0];
        }
        if (preg_match('#href=["\']([^"\']+\.pdf[^"\']*)["\']#i', $html, $m) === 1) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            if (str_starts_with($href, 'http')) {
                return $href;
            }
            $base = rtrim($pageUrl, '/');
            if (str_starts_with($href, '/')) {
                $parts = parse_url($pageUrl);

                return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$href;
            }

            return $base.'/'.ltrim($href, '/');
        }

        return null;
    }

    private function guessKind(string $url): string
    {
        $u = mb_strtolower(urldecode($url));
        if (preg_match('#(cert|conform|declaration|deklarac|zgodno|/doc/|ukdoc|oeko)#iu', $u)) {
            return ProductDocument::KIND_CERTIFICATE;
        }
        if (preg_match('#(datasheet|data[-_]?sheet|/pds/|pds|tds|spec|karta|pdb)#i', $u)) {
            return ProductDocument::KIND_DATASHEET;
        }

        return ProductDocument::KIND_OTHER;
    }

    private function guessTitle(string $url, string $kind): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $pathLower = mb_strtolower($path);
        if (str_contains($pathLower, '/ukdoc/')) {
            return 'Deklaracja zgodności UK.pdf';
        }
        if (str_contains($pathLower, '/doc/')) {
            return 'Deklaracja zgodności UE.pdf';
        }
        if (str_contains($pathLower, '/pds/')) {
            return 'Karta produktu.pdf';
        }
        $base = basename($path);
        $base = urldecode($base);
        if ($base !== '' && $base !== '/') {
            return mb_substr($base, 0, 255);
        }

        return match ($kind) {
            ProductDocument::KIND_CERTIFICATE => 'Certyfikat.pdf',
            ProductDocument::KIND_DATASHEET => 'Karta produktu.pdf',
            default => 'Dokument.pdf',
        };
    }
}
