<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Smalot\PdfParser\Parser;

final class PriceListPdfTextExtractor
{
    /**
     * Preferuje pdftotext (lepsze kolumny), fallback: smalot/pdfparser.
     * Duże PDF (dziesiątki MB) — bez sztucznego ucinania treści.
     */
    public function extract(string $path, ?int $maxChars = null): string
    {
        try {
            $text = $this->extractViaPdfToText($path) ?? $this->extractViaSmalot($path);
        } catch (\Throwable $e) {
            throw $this->scanOrRethrow($path, $e);
        }
        $text = $this->ensureUtf8($text);
        $text = $this->normalizeWhitespace($text);

        if (mb_strlen($text) < 40) {
            throw new RuntimeException(
                'PDF nie zawiera odczytywalnego tekstu (prawdopodobnie skan). Wgraj XLSX albo PDF z warstwą tekstową.'
            );
        }

        // domyślnie ~2 MB tekstu — wystarczy na duże cenniki; null = bez limitu
        $limit = $maxChars ?? 2_000_000;
        if ($limit > 0 && mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit)."\n\n[… tekst ucięty …]";
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    public function chunk(string $text, int $chunkChars = 28000, int $overlap = 800): array
    {
        $len = mb_strlen($text);
        if ($len <= $chunkChars) {
            return [$text];
        }

        $chunks = [];
        $pos = 0;
        while ($pos < $len) {
            $chunk = mb_substr($text, $pos, $chunkChars);
            $chunks[] = $chunk;
            $next = $pos + $chunkChars - $overlap;
            if ($next <= $pos) {
                break;
            }
            $pos = $next;
        }

        return $chunks;
    }

    private function extractViaPdfToText(string $path): ?string
    {
        $bin = $this->findPdfToText();
        if ($bin === null) {
            return null;
        }

        $cmd = escapeshellarg($bin).' -raw '.escapeshellarg($path).' -';
        $out = [];
        $code = 0;
        exec($cmd.' 2>NUL', $out, $code);
        if ($code !== 0 && $out === []) {
            return null;
        }
        $text = trim(implode("\n", $out));

        return $text !== '' ? $text : null;
    }

    private function findPdfToText(): ?string
    {
        $candidates = [
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
            'C:\\Program Files\\Git\\usr\\bin\\pdftotext.exe',
            'pdftotext',
        ];
        foreach ($candidates as $c) {
            if ($c === 'pdftotext') {
                $which = [];
                exec('where pdftotext 2>NUL', $which);
                if ($which !== []) {
                    return $which[0];
                }

                continue;
            }
            if (is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    private function extractViaSmalot(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Nie można odczytać pliku PDF.');
        }
        $bytes = $this->repairBrokenXref($bytes);
        try {
            return trim((new Parser)->parseContent($bytes)->getText());
        } catch (\Throwable $e) {
            throw new RuntimeException('Nie udało się odczytać PDF: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Stare skany (np. PPO Strzelce) mają xref „1 N” z wpisem free obiektu 0 — smalot wtedy nie widzi Catalog.
     */
    private function repairBrokenXref(string $pdf): string
    {
        $repaired = preg_replace(
            '/(xref[\r\n]+)1(\s+\d+[\r\n]+0000000000 65535 f)/',
            '${1}0${2}',
            $pdf,
            1
        );

        return is_string($repaired) ? $repaired : $pdf;
    }

    private function scanOrRethrow(string $path, \Throwable $e): RuntimeException
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Missing catalog') || (new PdfEmbeddedImageExtractor)->extract($path) !== []) {
            return new RuntimeException(
                'PDF nie zawiera odczytywalnego tekstu (prawdopodobnie skan). Wgraj XLSX albo PDF z warstwą tekstową.',
                0,
                $e
            );
        }

        return $e instanceof RuntimeException
            ? $e
            : new RuntimeException('Nie udało się odczytać PDF: '.$msg, 0, $e);
    }

    private function ensureUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($fixed) && $fixed !== '' && mb_check_encoding($fixed, 'UTF-8')) {
            return $fixed;
        }

        $from1252 = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        if (is_string($from1252) && $from1252 !== '' && mb_check_encoding($from1252, 'UTF-8')) {
            return $from1252;
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC0-\xFF]/', '', $text) ?? $text;
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
