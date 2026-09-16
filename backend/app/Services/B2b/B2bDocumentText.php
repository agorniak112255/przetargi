<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tekst z pliku pobranego z panelu dostawcy (karta techniczna PDF). Odczytany raz — zapisujemy go przy dokumencie
 * karty (product_documents.text), więc kolejne pobranie cennika nie ściąga plików ponownie.
 *
 * Odczyt idzie w osobnym procesie PHP z twardym limitem czasu: 16.09.2026 pobranie UVEX stanęło na jednym pliku
 * (parser PDF liczył bez końca, przebieg nie ruszał się z miejsca). Pojedynczy plik nie może zatrzymać całego
 * cennika — po limicie proces jest ubijany, a karta zostaje bez sekcji z karty technicznej.
 *
 * Czego w pliku nie ma, tego nie dopisujemy: skan bez warstwy tekstowej i plik innego typu dają pusty tekst.
 */
final class B2bDocumentText
{
    /** Karty techniczne UVEX mają ~2 tys. znaków; dłuższy tekst i tak nie zmieściłby się w opisie karty. */
    public const LIMIT = 8000;

    /** Tyle czasu ma odczyt jednego pliku; typowa karta techniczna schodzi poniżej sekundy. */
    public const TIMEOUT_SECONDS = 20;

    /** Osobny proces dostaje własny limit pamięci — parser PDF wczytuje cały plik. */
    private const SUBPROCESS_MEMORY = '256M';

    /**
     * Wiersze stopki firmowej z karty technicznej: dane sprzedawcy, nie produktu (adres, telefon, e-mail,
     * NIP/REGON/KRS, sąd rejestrowy, numer strony). Na karcie produktu to czysty szum — przy wyszukiwaniu
     * pod przetargi mieszałby dane firmy z danymi wyrobu.
     */
    private const CONTACT_PATTERNS = [
        '/^(NIP|REGON|Regon)\b/u',
        '/^KRS\b/u',
        '/^(S[ąa]d Rejonowy|SR\s+w\s)/iu',
        '/Wydz\.?\s*Gosp/iu',
        '/^[TFEIP]\s+(\+?\d[\d\s()-]{6,}|[\w.+-]+@[\w.-]+|(www\.)?[\w-]+\.[a-z]{2,4})$/u',
        '/^[\w.+-]+@[\w.-]+$/u',
        '/^(www\.)?[\w-]+\.(pl|com|eu|de|net)(\/\S*)?$/iu',
        '/^(ul|al|pl)\.\s/u',
        '/^Strona\s+\d+\s+z\s+\d+/iu',
        // kontakt na początku wiersza: „tel.+48 42 29-29-500, handlowy@… , Fax:+48 …” (Protekt)
        '/^(tel|fax|faks|kom|e-?mail)\b/iu',
        // adres z etykietą działu przed nim: „DZIAŁ HANDLOWY ul. Skromna 6, 93-405 Łódź, POLSKA”
        '/\b(ul|al)\.\s.*\b\d{2}-\d{3}\b/u',
        // adres e-mail w dowolnym miejscu wiersza — na karcie wyrobu to zawsze kontakt handlowy
        '/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu',
    ];

    /** Wiersz z nazwą firmy poprzedzony taką etykietą to fakt o wyrobie (kto go robi) — zostaje. */
    private const KEPT_COMPANY_LABELS = '/^(producent|importer|dystrybutor|wytwórca|marka|jednostka notyfikowana)\b/iu';

    /**
     * Nagłówek cennika producenta. Od niego w dół karta katalogowa opisuje już nie ten jeden wyrób, tylko całą
     * rodzinę: tabelę cen katalogowych z numerami wszystkich wersji (u Protektu DOR × długość), nazwę rodziny
     * i stopkę. Na karcie pojedynczego wyrobu to szum, a przy cenach wręcz mylące — cena karty pochodzi z cennika
     * konta B2B (z rabatem) i jest inna niż katalogowa. Numery innych wersji mają własne karty, każdy ze swoją ceną.
     */
    private const PRICE_LIST_HEADING = '/^(CENNIK|CENY)\b/u';

    /** Dowód, że za nagłówkiem naprawdę idzie tabela cen; bez niego nagłówek nie jest granicą i nic nie ucinamy. */
    private const PRICE_LIST_EVIDENCE = '/\d+,\d{2}\s*(\/|zł|PLN)|cena\s+(netto|brutto)/iu';

    /**
     * Etykiety sekcji układu karty katalogowej. W tekście z PDF-a lądują obok treści, do której się odnoszą,
     * więc na końcu zostają same — bez niczego pod spodem nie mówią nic i tylko je wtedy zdejmujemy.
     */
    private const SECTION_LABELS = '/^(CECHY SZCZEGÓLNE|PARAMETRY|CENNIK|CENY|ZDJĘCIA DODATKOWE)$/u';

    /** Tekst z pliku; '' gdy nie da się go odczytać. */
    public function fromFile(string $bytes, string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if ($mime !== 'application/pdf' || ! str_starts_with($bytes, '%PDF')) {
            return '';
        }

        $path = tempnam(sys_get_temp_dir(), 'b2b-doc-');
        if ($path === false) {
            return '';
        }

        try {
            file_put_contents($path, $bytes);

            return mb_substr($this->readInSubprocess($path, strlen($bytes)), 0, self::LIMIT);
        } catch (Throwable) {
            return '';
        } finally {
            @unlink($path);
        }
    }

    /**
     * Tekst pliku przygotowany do opisu karty: bez cennika rodziny, bez stopki firmowej sprzedawcy i bez pustych
     * akapitów. Surowy tekst zostaje przy dokumencie (product_documents.text) — czyścimy tylko to, co widać na karcie.
     */
    public static function forCard(string $text): string
    {
        $source = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line)),
            preg_split('/\R/u', $text) ?: [],
        );
        $priceList = self::priceListStart($source);
        if ($priceList !== null) {
            $source = array_slice($source, 0, $priceList);
        }

        $lines = [];
        foreach ($source as $line) {
            if ($line !== '' && self::isCompanyFooter($line)) {
                continue;
            }
            // najwyżej jedna pusta linia pod rząd — karta techniczna bywa poprzetykana pustymi wierszami
            if ($line === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }
            $lines[] = $line;
        }

        while ($lines !== [] && (end($lines) === '' || preg_match(self::SECTION_LABELS, (string) end($lines)) === 1)) {
            array_pop($lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Numer wiersza, od którego zaczyna się cennik rodziny; null gdy karta go nie ma.
     *
     * @param  list<string>  $lines
     */
    private static function priceListStart(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match(self::PRICE_LIST_HEADING, $line) !== 1) {
                continue;
            }
            $rest = implode("\n", array_slice($lines, $index + 1));
            if (preg_match(self::PRICE_LIST_EVIDENCE, $rest) === 1) {
                return $index;
            }
        }

        return null;
    }

    private static function isCompanyFooter(string $line): bool
    {
        // etykieta wygrywa z każdym wzorcem stopki: „Producent: X Sp. z o.o., ul. …” to fakt o wyrobie
        if (preg_match(self::KEPT_COMPANY_LABELS, $line) === 1) {
            return false;
        }

        foreach (self::CONTACT_PATTERNS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        // nazwa firmy z formą prawną razem z adresem albo sama — nagłówek papieru firmowego
        // bez \b na końcu — po kropce granica słowa nie zachodzi („Sp. z o.o. ul. …”)
        // forma prawna także rozpisana słowem — Protekt podpisuje się „Spółka z o.o.”
        return preg_match('/(\bsp(\.|ółka)\s?z\s?o\.\s?o\.|\bsp\.\s?k\.|\bspółka\s+(akcyjna|jawna|komandytowa)|\bs\.a\.|\bgmbh\b|\bltd\b)/iu', $line) === 1
            && (preg_match('/\b(ul|al)\.\s|\d{2}-\d{3}/u', $line) === 1 || mb_strlen($line) <= 60);
    }

    /**
     * Odczyt w osobnym procesie: `php -r` z autoloaderem projektu i PriceListPdfTextExtractor (ta sama ścieżka
     * co przy cennikach PDF). Po TIMEOUT_SECONDS proces jest ubijany — plik zostaje bez tekstu.
     */
    private function readInSubprocess(string $path, int $size): string
    {
        // Błąd odczytu ma wyjść kodem wyjścia i strumieniem błędów — komunikat PHP na wyjściu trafiłby do opisu karty.
        $code = 'require $argv[1];'
            .' try { fwrite(STDOUT, (new App\Services\PriceListPdfTextExtractor)->extract($argv[2], '.self::LIMIT.')); }'
            .' catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }';
        $process = @proc_open(
            [
                PHP_BINARY,
                '-d', 'memory_limit='.self::SUBPROCESS_MEMORY,
                '-d', 'display_errors=stderr',
                '-r', $code,
                base_path('vendor/autoload.php'),
                $path,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        if (! is_resource($process)) {
            return '';
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $text = '';
        $timedOut = false;
        $exitCode = null;
        while (true) {
            $text .= (string) stream_get_contents($pipes[1]);
            // strumień błędów trzeba czytać, inaczej pełny bufor zatrzymałby proces
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(50_000);
        }
        $text .= (string) stream_get_contents($pipes[1]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $closed = proc_close($process);
        $exitCode ??= $closed;

        if ($timedOut) {
            Log::warning('B2B: odczyt tekstu z pliku przerwany po limicie czasu', [
                'seconds' => self::TIMEOUT_SECONDS,
                'size_bytes' => $size,
            ]);

            return '';
        }
        // nieudany odczyt (uszkodzony plik, skan, brak pamięci) — karta zostaje bez sekcji z karty technicznej
        if ($exitCode !== 0) {
            return '';
        }

        return trim($text);
    }
}
