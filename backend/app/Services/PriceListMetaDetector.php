<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Producent / wersja cennika z nazwy pliku i treści (nie z „zastygłego” formularza).
 */
final class PriceListMetaDetector
{
    /** @var array<string, list<string>> */
    private const BRANDS = [
        '3M' => ['3m'],
        'Ansell' => ['ansell', 'alphatec', 'hyflex', 'microflex', 'touchntuff', 'versatouch', 'bioclean'],
        'EMA' => ['ema body', 'ema '],
        'PROS' => ['pros-cennik', 'pros cennik', 'pros_', 'ajgroup', 'aj group', 'aj-group'],
        'Lebon' => ['lebon'],
        'Debstoko' => ['debstoko', 'deb stoko', 'stoko'],
        'uvex' => ['uvex'],
        'MAPA' => ['mapa'],
        'Rostaing' => ['rostaing'],
        'Honeywell' => ['honeywell'],
        'DuPont' => ['dupont', 'tyvek'],
        'MSA' => ['msa '],
        'Dräger' => ['drager', 'dräger', 'draeger'],
        'Portwest' => ['portwest'],
        'JSP' => ['jsp'],
        'Moldex' => ['moldex'],
        'Scott' => ['scott safety'],
        'KCL' => ['kcl'],
        'Showa' => ['showa'],
        'ATLAS' => ['atlas'],
        'Cerva' => ['cerva'],
        'Reis' => ['reis'],
        'PANTHER' => ['panther'],
        'Ardon' => ['ardon', 'ardon.cz'],
        'Bogaro' => ['bogaro'],
        'JS GLOVES' => ['js gloves', 'js-gloves', 'szewczyk'],
        'PPO' => ['ppo strzelce', 'strzelce opolskie'],
        'RENEX' => ['renex'],
    ];

    /**
     * @return array{manufacturer: ?string, version: ?string}
     */
    public function fromFilename(?string $filename): array
    {
        if ($filename === null || trim($filename) === '') {
            return ['manufacturer' => null, 'version' => null];
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $norm = $this->normalize($base);

        return [
            'manufacturer' => $this->matchBrand($norm, $base),
            'version' => $this->matchVersion($base),
        ];
    }

    public function fromText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        // krótki początek wystarczy na nagłówek cennika
        $sample = mb_substr($text, 0, 4000);

        return $this->matchBrand($this->normalize($sample), $sample);
    }

    /**
     * Priorytet: AI → nazwa pliku → treść → podpowiedź użytkownika.
     *
     * @return array{manufacturer: string, version: string, source: string}
     */
    public function resolve(
        ?string $userHint,
        ?string $filename,
        ?string $aiDetected,
        ?string $textSample = null,
        ?string $userVersion = null,
    ): array {
        $fromFile = $this->fromFilename($filename);
        $fromText = $this->fromText($textSample);

        $manufacturer = $this->firstNonEmpty([
            $aiDetected,
            $fromFile['manufacturer'],
            $fromText,
            $userHint,
        ]) ?? 'Nieznany';

        // EMA to linia Ansell — spójna nazwa w bazie
        if (strcasecmp($manufacturer, 'EMA') === 0) {
            $manufacturer = 'Ansell';
        }

        $version = $this->firstNonEmpty([
            $fromFile['version'],
            $this->normalizeUserVersion($userVersion),
        ]) ?? date('Y-m');

        $source = 'user';
        if ($this->sameBrand($manufacturer, $aiDetected)) {
            $source = 'ai';
        } elseif ($this->sameBrand($manufacturer, $fromFile['manufacturer'])) {
            $source = 'filename';
        } elseif ($this->sameBrand($manufacturer, $fromText)) {
            $source = 'content';
        }

        return [
            'manufacturer' => $manufacturer,
            'version' => $version,
            'source' => $source,
        ];
    }

    private function matchBrand(string $normalizedHaystack, string $original): ?string
    {
        foreach (self::BRANDS as $brand => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalizedHaystack, $this->normalize($needle))) {
                    return $brand;
                }
            }
        }

        // „Ceník … pro PL” itd. — bez marki
        unset($original);

        return null;
    }

    private function matchVersion(string $filename): ?string
    {
        // 2021-07 / 2021_07 / 2021.07
        if (preg_match('/(?<![A-Za-z0-9])(20\d{2})[-_.](\d{2})(?![A-Za-z0-9])/', $filename, $m) === 1) {
            return $m[1].'-'.$m[2];
        }
        // od 01.07.2021 / 01-07-2021 / 15.02.2020
        if (preg_match('/(?<![A-Za-z0-9])(\d{1,2})[.\-](\d{1,2})[.\-](20\d{2})(?![A-Za-z0-9])/', $filename, $m) === 1) {
            return sprintf('%s-%02d', $m[3], (int) $m[2]);
        }
        // 20200817
        if (preg_match('/(?<![A-Za-z0-9])(20\d{2})(\d{2})(\d{2})(?![A-Za-z0-9])/', $filename, $m) === 1) {
            return $m[1].'-'.$m[2];
        }
        // Q1/2026 / Q1-2026
        if (preg_match('/(?<![A-Za-z0-9])(Q[1-4])[\/\-_\s]?(20\d{2})(?![A-Za-z0-9])/i', $filename, $m) === 1) {
            return strtoupper($m[1]).'/'.$m[2];
        }
        // samo 2026 także po podkreślniku: EUR_2026
        if (preg_match('/(?<![A-Za-z0-9])(20\d{2})(?![A-Za-z0-9])/', $filename, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ź', 'ż'], ['a', 'c', 'e', 'l', 'n', 'o', 's', 'z', 'z'], $s);

        return $s;
    }

    private function normalizeUserVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $version = trim($version);

        return $version !== '' ? $version : null;
    }

    /**
     * @param  list<?string>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return null;
    }

    private function sameBrand(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return strcasecmp(trim($a), trim($b)) === 0;
    }
}
