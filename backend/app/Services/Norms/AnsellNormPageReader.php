<?php

declare(strict_types=1);

namespace App\Services\Norms;

use App\Support\NormCode;

/**
 * Normy z karty wyrobu na ansell.com (czytanej przez reader — ReaderMarkdownPage). Sekcja „Normy i certyfikaty”
 * („Standards and certifications” na stronach angielskich) to ikony norm z podpisem i kodem tuż za obrazkiem:
 * „![Image 30: EN 388:2016](…) 4X43EP”, „![Image 31: EN407:2020 ](…) X1XXXX”, a normy bez ikony stoją zwykłą linią
 * („EN ISO 21420:2020”). Para = podpis ikony (oznaczenie normy) i kod za nią, dosłownie; ikony, które normą EN nie są
 * (CE, kontakt z żywnością, OEKO-TEX, ANSI/ISEA), odpadają. Karta produktu i deklaracja w PDF u Ansella to obrazy
 * bez warstwy tekstu (sprawdzone 23.09.2026) — ta sekcja strony to jedyne czytelne źródło poziomów.
 *
 * Czytamy tylko pierwszą taką sekcję: karuzela na stronie powtarza ikony, a dalsze bloki należą do innych wyrobów.
 */
final class AnsellNormPageReader implements ManufacturerNormPageReader
{
    private const SECTION = '/^#{2,4}\s*(?:Normy i certyfikaty|Standards and certifications)\s*$/imu';

    private const NEXT_SECTION = '/^#{1,4}\s+\S/mu';

    private const ICON = '/!\[(?:Image\s*\d+\s*:\s*)?([^\]]*)\]\([^)]*\)[ \t]*([0-9A-Z]{3,8})?/u';

    public function supports(string $host): bool
    {
        $host = preg_replace('/^www\./', '', mb_strtolower($host)) ?? $host;

        return $host === 'ansell.com' || str_ends_with($host, '.ansell.com');
    }

    public function read(string $html, string $url): ?NormPageReading
    {
        $markdown = ReaderMarkdownPage::markdownFrom($html);
        if ($markdown === null || preg_match(self::SECTION, $markdown, $s, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $section = substr($markdown, (int) $s[0][1] + strlen($s[0][0]));
        if (preg_match(self::NEXT_SECTION, $section, $next, PREG_OFFSET_CAPTURE) === 1) {
            $section = substr($section, 0, (int) $next[0][1]);
        }

        $rows = [];
        $block = [];
        foreach (preg_split('/\R/u', $section) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'Previous' || $line === 'Next') {
                continue;
            }
            if (preg_match_all(self::ICON, $line, $icons, PREG_SET_ORDER) > 0) {
                foreach ($icons as $icon) {
                    $label = trim($icon[1]);
                    if (NormCode::leadFamily($label) === '') {
                        continue;
                    }
                    $value = isset($icon[2]) && $icon[2] !== '' ? $icon[2] : null;
                    $this->push($rows, $block, $label, $value);
                }

                continue;
            }
            if (NormCode::leadFamily($line) !== '') {
                $this->push($rows, $block, $line, null);
            }
        }

        return $rows === [] ? null : new NormPageReading($rows, mb_substr(implode("\n", $block), 0, 1000), 'ansell');
    }

    /**
     * @param  list<array{label: string, value: ?string}>  $rows
     * @param  list<string>  $block
     */
    private function push(array &$rows, array &$block, string $label, ?string $value): void
    {
        $row = ['label' => $label, 'value' => $value];
        if (in_array($row, $rows, true)) {
            return;
        }
        $rows[] = $row;
        $block[] = $value !== null ? $label.' '.$value : $label;
    }
}
