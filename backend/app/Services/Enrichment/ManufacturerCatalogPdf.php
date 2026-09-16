<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Services\PriceListPdfTextExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Katalog PDF producenta jako źródło opisu.
 *
 * Część producentów (SECURA, securabc.com) nie ma kart HTML per wyrób — cały asortyment opisuje jeden katalog
 * PDF z numerami katalogowymi. Bez tego źródła karta takiego wyrobu zostaje pusta albo opisana ze sklepu.
 *
 * Zasada nadrzędna: opis wolno wziąć TYLKO z bloku, który katalog przypisał temu numerowi katalogowemu.
 * Jeden plik opisuje setki wyrobów, więc dopasowanie po nazwie albo po podobieństwie podpięłoby opis
 * sąsiedniego modelu — dopasowujemy wyłącznie po dokładnym kodzie (po odrzuceniu znaków niealfanumerycznych).
 */
final class ManufacturerCatalogPdf
{
    /** Cache tekstu katalogu: storage/app/private/manufacturer-catalogs/<sha1 adresu>.txt */
    public const DISK = 'local';

    public const CACHE_DIR = 'manufacturer-catalogs';

    /** Katalog roczny zmienia się rzadko — częstsze pobieranie kilku MB nic nie wnosi. */
    private const REFRESH_AFTER_HOURS = 24;

    /** Tyle tekstu katalogu trzymamy w cache (katalog SECURA to ~130 kB tekstu). */
    private const TEXT_LIMIT = 2_000_000;

    /** Blok jednego wyrobu ma rozmiar akapitu z karty, nie rozdziału katalogu. */
    private const BLOCK_MAX_CHARS = 3000;

    /** Sam wiersz z kodem i jednostką miary to jeszcze nie opis. */
    private const BLOCK_MIN_CHARS = 80;

    /** Krótki kod trafiłby w przypadkowy token w tekście — takiego nie szukamy. */
    private const MIN_CODE_CHARS = 5;

    /** Ile wystąpień kodu sprawdzamy (katalog powtarza kod w tabelach wariantów). */
    private const MAX_HITS = 8;

    private const DOWNLOAD_TIMEOUT_SECONDS = 45;

    /** Katalog roczny waży kilka MB; większy plik to nie katalog. */
    private const MAX_BYTES = 80 * 1024 * 1024;

    /**
     * Kod katalogowy w tekście: token pisany wielkimi literami, bez separatorów, z cyframi
     * („S56322S2”, „T5920000”). Służy do wyznaczania granicy między wyrobami.
     */
    private const CODE_TOKEN = '/(?<![A-Za-z0-9])[A-Z][A-Z0-9]{4,19}(?![A-Za-z0-9])/u';

    /** Token, w którym może siedzieć nasz kod — z separatorami („S56212-10”). */
    private const LOOSE_TOKEN = '/(?<![A-Za-z0-9])[A-Za-z0-9][A-Za-z0-9._\/-]{3,29}(?![A-Za-z0-9])/u';

    public function __construct(
        private readonly ManufacturerDomainResolver $manufacturers,
    ) {}

    /**
     * Strony opisowe z katalogów producenta — jedna na katalog, w którym stoi kod tego wyrobu.
     *
     * @return list<array{url: string, text: string, title: string}>
     */
    public function pagesFor(Product $product): array
    {
        $sku = trim((string) $product->sku);
        if ($sku === '') {
            return [];
        }

        $out = [];
        foreach ($this->catalogUrlsFor($product) as $url) {
            $text = $this->catalogText($url);
            if ($text === '') {
                continue;
            }
            $block = $this->blockFor($text, $sku);
            if ($block === '') {
                continue;
            }
            $out[] = [
                'url' => $url,
                'text' => $block,
                'title' => 'Katalog producenta '.trim((string) $product->manufacturer).' — nr kat. '.$sku,
            ];
        }

        return $out;
    }

    /**
     * Adresy katalogów przypisane marce wyrobu. Klucz marki liczy ManufacturerDomainResolver,
     * dopasowanie klucza jest takie samo jak przy manufacturer_domains (marka z cennika bywa
     * zapisana szerzej niż klucz: „SECURA B.C.” → „secura-b-c”).
     *
     * @return list<string>
     */
    public function catalogUrlsFor(Product $product): array
    {
        $brand = $this->manufacturers->brandKey((string) $product->manufacturer);
        if ($brand === '') {
            return [];
        }

        $out = [];
        foreach ($this->configuredCatalogs() as $key => $urls) {
            if ($key !== $brand
                && (mb_strlen($key) < 4 || mb_strlen($brand) < 4
                    || (! str_contains($brand, $key) && ! str_contains($key, $brand)))) {
                continue;
            }
            foreach ($urls as $url) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Czy adres to katalog marki z konfiguracji. Broszura całej marki nie jest dokumentem wyrobu —
     * na karcie produktu nie ma jej czego szukać w „Plikach PDF”.
     */
    public function isConfiguredCatalogUrl(string $url): bool
    {
        $needle = $this->normalizeUrl($url);
        if ($needle === '') {
            return false;
        }
        foreach ($this->configuredCatalogs() as $urls) {
            foreach ($urls as $catalog) {
                if ($this->normalizeUrl($catalog) === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Blok tekstu, który katalog przypisał temu numerowi katalogowemu — '' gdy kodu w katalogu nie ma.
     *
     * Blok bierzemy w OBIE strony od wiersza z kodem. W tekście z `pdftotext -raw` (tej ścieżki używamy
     * w produkcji) opis stoi NAD tabelą z kodami, a w wersji czytanej w kolejności stron numer katalogowy
     * bywa wstawiony w środek akapitu — jednostronne cięcie „od kodu w dół” brałoby w obu układach opis
     * sąsiedniego wyrobu. Granicą jest pierwszy wiersz, który należy już do innego wyrobu:
     *  - wiersz z innym numerem katalogowym (zgodnie z wymogiem: przytnij blok przed obcym kodem),
     *  - nagłówek sekcji (wiersz pisany samymi wielkimi literami — „▶ SECURA 3000 + FILTR PRZECIWPYŁOWY P3”),
     *  - limit długości.
     * Wiersz z kilkoma kodami (tabela zestawień „półmaska + filtr”) nie jest blokiem żadnego z nich — takie
     * wystąpienia pomijamy, żeby nie przypisać wyrobowi opisu zestawu.
     */
    public function blockFor(string $catalogText, string $sku): string
    {
        $needle = $this->normalizeCode($sku);
        if (mb_strlen($needle) < self::MIN_CODE_CHARS) {
            return '';
        }

        $lines = preg_split('/\R/u', $catalogText) ?: [];
        if ($lines === []) {
            return '';
        }

        /** @var list<list<string>> $codes kody katalogowe w każdym wierszu */
        $codes = [];
        foreach ($lines as $line) {
            $codes[] = $this->codesInLine($line);
        }

        $best = '';
        $hits = 0;
        foreach ($lines as $i => $line) {
            if (! $this->lineCarriesCode($line, $needle)) {
                continue;
            }
            // wiersz zestawienia (nasz kod obok cudzego) — nie wiadomo, czyj byłby to opis
            if (array_diff($codes[$i], [$needle]) !== []) {
                continue;
            }
            if (++$hits > self::MAX_HITS) {
                break;
            }
            $block = $this->blockAround($lines, $codes, $i, $needle);
            if (mb_strlen($block) > mb_strlen($best)) {
                $best = $block;
            }
        }

        return mb_strlen($best) >= self::BLOCK_MIN_CHARS ? $best : '';
    }

    /**
     * @param  list<string>  $lines
     * @param  list<list<string>>  $codes
     */
    private function blockAround(array $lines, array $codes, int $index, string $needle): string
    {
        $budget = self::BLOCK_MAX_CHARS - mb_strlen($lines[$index]);

        $start = $index;
        while ($start > 0 && $budget > 0) {
            $line = $lines[$start - 1];
            if ($this->startsAnotherEntry($line, $codes[$start - 1], $needle)) {
                break;
            }
            $budget -= mb_strlen($line) + 1;
            if ($budget <= 0) {
                break;
            }
            $start--;
        }

        $end = $index;
        $last = count($lines) - 1;
        while ($end < $last && $budget > 0) {
            $line = $lines[$end + 1];
            if ($this->startsAnotherEntry($line, $codes[$end + 1], $needle)) {
                break;
            }
            $budget -= mb_strlen($line) + 1;
            if ($budget <= 0) {
                break;
            }
            $end++;
        }

        $block = [];
        for ($i = $start; $i <= $end; $i++) {
            $block[] = trim($lines[$i]);
        }

        return trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n", $block)) ?? implode("\n", $block));
    }

    /**
     * Wiersz należy już do innego wyrobu: niesie obcy kod albo otwiera nową sekcję katalogu.
     *
     * @param  list<string>  $codes
     */
    private function startsAnotherEntry(string $line, array $codes, string $needle): bool
    {
        if (array_diff($codes, [$needle]) !== []) {
            return true;
        }

        return $this->looksLikeHeading($line);
    }

    /**
     * Nagłówek sekcji: wiersz bez małych liter, z kilkoma literami („PÓŁMASKA FILTR ZASTOSOWANIE”,
     * „▶ SECURA 3000 + FILTR PRZECIWPYŁOWY P2”). W katalogu oddziela wyroby od siebie.
     *
     * Tytuł sekcji bywa też pisany zwykłą wielkością liter („▶ SECURA 3100 + Filtr przeciwpyłowy P3”)
     * — rozpoznajemy go po znaku wypunktowania ZE spacją. Wypunktowanie cech wyrobu w tym samym
     * katalogu idzie bez spacji („▶Ergonomiczny kształt…”), więc opisu wyrobu ta reguła nie tnie.
     */
    private function looksLikeHeading(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $line) ?: 0;
        if ($letters < 3) {
            return false;
        }
        if (preg_match('/^[\x{25B6}\x{25BA}\x{25A0}\x{25CF}\x{2023}\x{27A4}]\s/u', $line) === 1) {
            return true;
        }

        return preg_match('/\p{Ll}/u', $line) !== 1;
    }

    /**
     * @return list<string> znormalizowane kody katalogowe w wierszu
     */
    private function codesInLine(string $line): array
    {
        if (preg_match_all(self::CODE_TOKEN, $line, $m) === 0) {
            return [];
        }

        $out = [];
        foreach ($m[0] as $token) {
            // token bez cyfr to skrót („PÓŁMASKA”, „SECAIR”), nie numer katalogowy
            if ((preg_match_all('/\d/u', $token) ?: 0) < 2) {
                continue;
            }
            $out[$token] = $token;
        }

        return array_values($out);
    }

    /** Czy w wierszu stoi dokładnie ten kod (po odrzuceniu znaków niealfanumerycznych). */
    private function lineCarriesCode(string $line, string $needle): bool
    {
        if (preg_match_all(self::LOOSE_TOKEN, $line, $m) === 0) {
            return false;
        }
        foreach ($m[0] as $token) {
            if ($this->normalizeCode($token) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^a-z0-9]+/iu', '', $value));
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(trim(mb_strtolower($url)), '/');
    }

    /**
     * @return array<string, list<string>>
     */
    private function configuredCatalogs(): array
    {
        $map = config('enrichment.manufacturer_catalogs', []);
        if (! is_array($map)) {
            return [];
        }

        $out = [];
        foreach ($map as $key => $urls) {
            if (! is_string($key)) {
                continue;
            }
            $key = trim((string) preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower(trim($key))), '-');
            if ($key === '') {
                continue;
            }
            foreach ((array) $urls as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $out[$key][] = $url;
                }
            }
        }

        return $out;
    }

    /**
     * Tekst katalogu: z cache, a gdy jest starszy niż doba — pobrany raz i zapisany.
     * Żaden błąd (brak sieci, HTTP 404, PDF bez warstwy tekstowej) nie może wywrócić wzbogacania:
     * wtedy zostaje stary cache, a gdy i jego nie ma — pusty tekst i karta idzie dalej bez katalogu.
     */
    private function catalogText(string $url): string
    {
        $disk = Storage::disk(self::DISK);
        $path = self::CACHE_DIR.'/'.sha1($this->normalizeUrl($url)).'.txt';

        try {
            if ($disk->exists($path)) {
                $age = now()->getTimestamp() - (int) $disk->lastModified($path);
                if ($age < self::REFRESH_AFTER_HOURS * 3600) {
                    return (string) $disk->get($path);
                }
            }
        } catch (Throwable $e) {
            Log::info('Katalog producenta: nie udało się odczytać cache', ['url' => $url, 'error' => $e->getMessage()]);
        }

        // Znacznik próby PRZED pobraniem — gdy pdftotext zawiesi się na uszkodzonym katalogu,
        // job padnie po swoim limicie czasu, ale kolejne karty tej marki nie powtórzą zwisu
        // przez dobę. Istniejącego cache nie nadpisujemy: stary tekst musi przetrwać próbę.
        try {
            if (! $disk->exists($path)) {
                $disk->put($path, '');
            }
        } catch (Throwable $e) {
            Log::info('Katalog producenta: nie udało się zapisać znacznika próby', ['url' => $url, 'error' => $e->getMessage()]);
        }

        $text = '';
        try {
            $text = $this->download($url);
        } catch (Throwable $e) {
            Log::info('Katalog producenta: pobranie nie powiodło się', ['url' => $url, 'error' => $e->getMessage()]);
        }

        if ($text === '') {
            // odświeżenie się nie udało — stary tekst jest lepszy niż brak opisu
            try {
                return $disk->exists($path) ? (string) $disk->get($path) : '';
            } catch (Throwable) {
                return '';
            }
        }

        try {
            $disk->put($path, $text);
        } catch (Throwable $e) {
            Log::info('Katalog producenta: nie udało się zapisać cache', ['url' => $url, 'error' => $e->getMessage()]);
        }

        return $text;
    }

    /**
     * Pobranie katalogu i odczyt tekstu. Tekst wyciąga PriceListPdfTextExtractor — ta sama ścieżka
     * (pdftotext w osobnym procesie), której używa B2bDocumentText przy kartach technicznych.
     * Samego B2bDocumentText tu nie użyjemy: tnie tekst do 8000 znaków, bo opisuje JEDEN wyrób,
     * a katalog marki ma ich ponad sto i po takim cięciu zostałaby okładka.
     */
    private function download(string $url): string
    {
        $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
            ->connectTimeout(10)
            ->withHeaders(['Accept' => 'application/pdf,*/*'])
            ->get($url);
        if (! $response->successful()) {
            Log::info('Katalog producenta: HTTP '.$response->status(), ['url' => $url]);

            return '';
        }

        $bytes = $response->body();
        if (! str_starts_with($bytes, '%PDF')) {
            Log::info('Katalog producenta: odpowiedź nie jest plikiem PDF', ['url' => $url]);

            return '';
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            Log::info('Katalog producenta: plik większy niż limit', ['url' => $url, 'bytes' => strlen($bytes)]);

            return '';
        }

        $path = tempnam(sys_get_temp_dir(), 'mfr-catalog-');
        if ($path === false) {
            return '';
        }
        try {
            file_put_contents($path, $bytes);

            return trim(app(PriceListPdfTextExtractor::class)->extract($path, self::TEXT_LIMIT));
        } finally {
            @unlink($path);
        }
    }
}
