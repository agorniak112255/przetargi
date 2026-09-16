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
