<?php

declare(strict_types=1);

namespace App\Services\Norms;

use App\Services\B2b\JspB2bClient;
use App\Services\Enrichment\ProductPageFetcher;
use App\Support\NormCode;
use DOMElement;

/**
 * Karta wyrobu na cxs.net.pl (Canis/CXS, Magento). Normy stoją w opisie, nie w ramce:
 *   „normy: EN 420, EN 388” (krótki opis, div.product.attribute.overview) i
 *   „Poziomy odporności dla normy EN388: - odporność na przetarcie - 2 - odporność na przecięcie - 1 …”
 *   (pełny opis, div.product.attribute.description).
 * Pary są dosłowne: jedna na normę z wiersza „normy:”, a przy EN 388 wartością są poziomy słowami, jak na stronie.
 * Kodu „2112” tu nie składamy — to byłby wniosek, nie tekst źródła; słowa czyta En388Code.
 *
 * Czytamy tylko te dwa bloki opisu: pod kartą stoją kafelki innych rękawic (div.block.upsell) i tabela rozmiarów
 * produktu grupowego, a meta description powtarza opis bez podziału na wiersze.
 */
final class CxsNormPageReader implements ManufacturerNormPageReader
{
    private const BLOCK_LIMIT = 1000;

    public function __construct(private readonly ProductPageFetcher $pages) {}

    public function supports(string $host): bool
    {
        $host = mb_strtolower(trim($host));

        return $host === 'cxs.net.pl' || str_ends_with($host, '.cxs.net.pl');
    }

    public function read(string $html, string $url): ?NormPageReading
    {
        $lines = $this->descriptionLines($html);
        if ($lines === []) {
            return null;
        }

        $normsLine = null;
        $labels = [];
        foreach ($lines as $line) {
            if (preg_match('/^normy\s*:\s*(.+)$/iu', $line, $m) === 1) {
                $normsLine = $line;
                $labels = $this->normLabels($m[1]);

                break;
            }
        }
        [$levelNorm, $levels, $levelLines] = $this->levelsBlock($lines);

        $rows = [];
        $levelsUsed = false;
        foreach ($labels as $label) {
            $value = null;
            if ($levels !== [] && ! $levelsUsed && self::sameNorm($label, (string) $levelNorm)) {
                $value = implode(', ', $levels);
                $levelsUsed = true;
            }
            $rows[] = ['label' => $label, 'value' => $value];
        }
        // poziomy bez wiersza „normy:” (albo z normą spoza niego) — para z normą z nagłówka poziomów, dosłownie
        if ($levels !== [] && ! $levelsUsed) {
            $rows[] = ['label' => (string) $levelNorm, 'value' => implode(', ', $levels)];
        }
        if ($rows === []) {
            return null;
        }

        $block = implode("\n", array_filter([$normsLine, ...$levelLines], static fn (?string $line): bool => $line !== null));

        return new NormPageReading($rows, mb_substr($block, 0, self::BLOCK_LIMIT), 'cxs');
    }

    /**
     * Wiersze krótkiego i pełnego opisu (pierwszy blok każdego rodzaju), po kolei, bez pustych; twarde spacje
     * („&nbsp; &nbsp; - odporność…”) jako zwykłe odstępy.
     *
     * @return list<string>
     */
    private function descriptionLines(string $html): array
    {
        $xpath = JspB2bClient::dom($html);
        $lines = [];
        foreach (['overview', 'description'] as $kind) {
            $node = $xpath->query('//div['.JspB2bClient::classPredicate('product').' and '
                .JspB2bClient::classPredicate('attribute').' and '.JspB2bClient::classPredicate($kind).']')->item(0);
            if (! $node instanceof DOMElement) {
                continue;
            }
            $inner = '';
            foreach ($node->childNodes as $child) {
                $inner .= (string) $node->ownerDocument?->saveHTML($child);
            }
            foreach (preg_split('/\R/u', $this->pages->mainTextFromHtml($inner)) ?: [] as $line) {
                $line = trim((string) preg_replace('/[\h\x{00A0}]+/u', ' ', $line));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Normy z wiersza „normy:” po przecinku. Kawałek, który nie zaczyna się oznaczeniem normy („EN ISO 20345, S3”),
     * zostaje przy poprzedniej normie — to jej dopisek, nie osobna norma.
     *
     * @return list<string>
     */
    private function normLabels(string $list): array
    {
        $labels = [];
        foreach (preg_split('/\s*[,;]\s*/u', trim($list)) ?: [] as $piece) {
            $piece = trim($piece, " \t.");
            if ($piece === '') {
                continue;
            }
            if ($labels !== [] && NormCode::leadFamily($piece) === '') {
                $labels[array_key_last($labels)] .= ', '.$piece;

                continue;
            }
            $labels[] = $piece;
        }

        return $labels;
    }

    /**
     * Nagłówek „Poziomy odporności dla normy EN388:” i wiersze „- odporność na … - N” pod nim (albo w tej samej
     * linii). Koniec na pierwszym wierszu bez punktora — dalej jest już zdanie o certyfikacie i pakowaniu.
     *
     * @param  list<string>  $lines
     * @return array{0: ?string, 1: list<string>, 2: list<string>} norma z nagłówka, poziomy, dosłowne wiersze źródła
     */
    private function levelsBlock(array $lines): array
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^poziomy\s+odporności\s+dla\s+normy\s+((?:PN-)?EN(?:\s*ISO)?\s*\d{3,5}(?::\s*\d{4})?)\s*:?\s*(.*)$/iu', $line, $m) !== 1) {
                continue;
            }
            $levels = [];
            $source = [$line];
            $rest = trim($m[2]);
            if ($rest !== '' && preg_match('/^[-–•]\s*(.+)$/u', $rest, $first) === 1) {
                $levels[] = trim($first[1]);
            }
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (preg_match('/^[-–•]\s*(.+)$/u', $lines[$j], $item) !== 1) {
                    break;
                }
                $levels[] = trim($item[1]);
                $source[] = $lines[$j];
            }

            return $levels === [] ? [null, [], []] : [trim($m[1]), $levels, $source];
        }

        return [null, [], []];
    }

    /** „EN 388” z wiersza „normy:” i „EN388” z nagłówka poziomów to ta sama norma. */
    private static function sameNorm(string $a, string $b): bool
    {
        $key = static fn (string $norm): string => NormCode::leadFamily($norm);

        return $key($a) !== '' && $key($a) === $key($b);
    }
}
