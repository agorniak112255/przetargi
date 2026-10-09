<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Services\Catalog\PriceListFileReconciler;
use App\Services\PriceListPdfTextExtractor;
use App\Services\SpreadsheetCellReader;
use Illuminate\Console\Command;
use JsonException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Kontrola kart cennika wobec pliku, z którego go importowano — tylko odczyt (plan naprawy importu z 10.10.2026,
 * część C). Szuka kart z kodem uciętym dawną regułą rozmiaru (Coba 12.09.2026: AF010005 → AF0100) także w innych
 * cennikach: kod karty w pliku / podejrzenie ucięcia (kodu karty ani kodu bez końcówki „-N” nie ma w pliku, ale jest
 * dawnym rdzeniem kodu z pliku — ProductSizeVariant::legacyCutCodes) / brak. Żetony: komórki arkuszy (całe i słowa
 * z cyfrą) albo tekst PDF (pdftotext -layout). Ta sama logika co products:repair-price-list-codes
 * (PriceListFileReconciler::auditCards).
 *
 * Karty z bazy (slot „file” cennika --price-list) albo z --cards-json — zrzutu z produkcji (eksport READ ONLY:
 * {"price_lists": [{"id", "manufacturer", "original_filename", "cards": [{"id", "sku", "name"}]}]} albo
 * {"price_list": {...}, "cards": [...]}). Gdy {file} jest katalogiem, polecenie przechodzi po cennikach z zrzutu
 * i szuka w katalogu (z podkatalogami) pliku o nazwie original_filename.
 */
final class PriceListFileAuditCommand extends Command
{
    private const CLASS_LABELS = [
        PriceListFileReconciler::CLASS_IN_FILE => 'kod w pliku',
        PriceListFileReconciler::CLASS_SUSPECTED => 'podejrzenie ucięcia',
        PriceListFileReconciler::CLASS_MISSING => 'brak w pliku',
    ];

    protected $signature = 'products:price-list-file-audit
                            {file : Plik cennika (XLSX/XLS/CSV/PDF) albo katalog z plikami cenników}
                            {--price-list= : Numer cennika (price_lists.id)}
                            {--cards-json= : Zrzut kart z produkcji (zamiast kart z tej bazy)}
                            {--csv= : Zapisz klasy wszystkich kart do pliku CSV}
                            {--limit=15 : Przykładów na klasę (0 = bez przykładów)}';

    protected $description = 'Tylko odczyt: karty cennika wobec pliku — kod w pliku / podejrzenie ucięcia dawną regułą rozmiaru / brak';

    public function handle(PriceListFileReconciler $reconciler, SpreadsheetCellReader $cells, PriceListPdfTextExtractor $pdf): int
    {
        $path = (string) $this->argument('file');
        $lists = $this->lists();
        if (is_string($lists)) {
            $this->error($lists);

            return self::FAILURE;
        }

        if (is_dir($path)) {
            $files = $this->filesByName($path);
            $rows = [];
            foreach ($lists as $list) {
                $file = $files[mb_strtolower(trim((string) $list['original_filename']))] ?? null;
                if ($file === null) {
                    $rows[] = [$list['id'], $list['manufacturer'], $list['original_filename'], count($list['cards']), '—', '—', '—', '—', 'brak pliku w katalogu'];

                    continue;
                }
                try {
                    $tokens = $this->tokens($file, $reconciler, $cells, $pdf);
                    $audit = $reconciler->auditCards($list['cards'], $tokens);
                } catch (Throwable $e) {
                    $rows[] = [$list['id'], $list['manufacturer'], $list['original_filename'], count($list['cards']), '—', '—', '—', '—', 'błąd odczytu: '.mb_substr($e->getMessage(), 0, 60)];

                    continue;
                }
                $rows[] = [
                    $list['id'], $list['manufacturer'], $list['original_filename'], count($list['cards']),
                    $audit['counts'][PriceListFileReconciler::CLASS_IN_FILE],
                    $audit['counts'][PriceListFileReconciler::CLASS_SUSPECTED],
                    $audit['counts'][PriceListFileReconciler::CLASS_MISSING],
                    $audit['tokens_sharing_core_without_card'],
                    mb_substr($file, mb_strlen($path) + 1),
                ];
            }
            $this->table(['Cennik', 'Producent', 'Plik', 'Kart', 'Kod w pliku', 'Podejrzenie ucięcia', 'Brak', 'Kody pliku z rdzeniem karty, bez karty', 'Uwagi'], $rows);

            return self::SUCCESS;
        }

        if (! is_file($path)) {
            $this->error("Brak pliku: {$path}");

            return self::FAILURE;
        }
        if (count($lists) !== 1) {
            $this->error('Zrzut ma kilka cenników — podaj --price-list=numer albo katalog zamiast pliku.');

            return self::FAILURE;
        }
        $list = $lists[0];
        $tokens = $this->tokens($path, $reconciler, $cells, $pdf);
        $audit = $reconciler->auditCards($list['cards'], $tokens);

        $this->line(sprintf('Cennik #%s %s: %d kart; żetonów kodów w pliku: %d.', $list['id'], $list['manufacturer'], count($list['cards']), count($tokens['codes'])));
        foreach (self::CLASS_LABELS as $class => $label) {
            $this->line(sprintf('  %s: %d', $label, $audit['counts'][$class]));
        }
        $this->line('  kody z pliku z dawnym rdzeniem równym kodowi karty, bez własnej karty: '.$audit['tokens_sharing_core_without_card']
            .($audit['token_examples'] !== [] ? ' (np. '.implode(', ', array_slice($audit['token_examples'], 0, 10)).')' : ''));

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            foreach ([PriceListFileReconciler::CLASS_SUSPECTED, PriceListFileReconciler::CLASS_MISSING] as $class) {
                $examples = array_slice(array_values(array_filter($audit['cards'], static fn (array $c): bool => $c['class'] === $class)), 0, $limit);
                if ($examples === []) {
                    continue;
                }
                $this->line(self::CLASS_LABELS[$class].' — przykłady:');
                $this->table(['ID', 'Kod karty', 'Nazwa', 'Kody z pliku'], array_map(static fn (array $c): array => [
                    $c['id'], $c['sku'], mb_substr($c['name'], 0, 60), implode(', ', array_slice($c['evidence'], 0, 6)),
                ], $examples));
            }
        }

        $csv = trim((string) $this->option('csv'));
        if ($csv !== '') {
            $handle = fopen($csv, 'w');
            if ($handle === false) {
                $this->error("Nie mogę zapisać pliku: {$csv}");

                return self::FAILURE;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'sku', 'nazwa', 'klasa', 'kody_z_pliku'], ';');
            foreach ($audit['cards'] as $card) {
                fputcsv($handle, [$card['id'], $card['sku'], $card['name'], self::CLASS_LABELS[$card['class']], implode(', ', $card['evidence'])], ';');
            }
            fclose($handle);
            $this->info("CSV: {$csv}");
        }

        return self::SUCCESS;
    }

    /**
     * Cenniki z kartami: z --cards-json albo z bazy (--price-list). Błąd = tekst.
     *
     * @return list<array{id: int|string, manufacturer: string, original_filename: string, cards: list<array{id: int|string, sku: string, name: string}>}>|string
     */
    private function lists(): array|string
    {
        $only = trim((string) $this->option('price-list'));
        $json = trim((string) $this->option('cards-json'));
        if ($json !== '') {
            if (! is_file($json)) {
                return "Brak pliku zrzutu: {$json}";
            }
            try {
                $data = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return 'Zrzut nie jest poprawnym JSON: '.$e->getMessage();
            }
            $raw = is_array($data['price_lists'] ?? null)
                ? $data['price_lists']
                : [[...(is_array($data['price_list'] ?? null) ? $data['price_list'] : []), 'cards' => $data['cards'] ?? []]];
            $lists = [];
            foreach ($raw as $list) {
                if (! is_array($list) || ($only !== '' && (string) ($list['id'] ?? '') !== $only)) {
                    continue;
                }
                $lists[] = [
                    'id' => $list['id'] ?? '?',
                    'manufacturer' => (string) ($list['manufacturer'] ?? ''),
                    'original_filename' => (string) ($list['original_filename'] ?? ''),
                    'cards' => array_values(array_map(static fn (array $c): array => [
                        'id' => $c['id'] ?? '',
                        'sku' => (string) ($c['sku'] ?? ''),
                        'name' => (string) ($c['name'] ?? ''),
                    ], array_filter((array) ($list['cards'] ?? []), 'is_array'))),
                ];
            }

            return $lists !== [] ? $lists : 'W zrzucie nie ma cennika'.($only !== '' ? " numer {$only}" : '').'.';
        }

        $priceList = $only !== '' ? PriceList::query()->find((int) $only) : null;
        if ($priceList === null) {
            return $only !== '' ? "Nie ma cennika numer {$only}." : 'Podaj --price-list=numer albo --cards-json=zrzut.json.';
        }
        $ids = ProductSourcePrice::query()->where('source_key', ProductSourcePrice::SOURCE_FILE)->where('price_list_id', $priceList->id)->pluck('product_id')->all();
        $cards = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->orderBy('id')->get(['id', 'sku', 'name']) as $product) {
                $cards[] = ['id' => (int) $product->id, 'sku' => (string) $product->sku, 'name' => (string) $product->name];
            }
        }

        return [[
            'id' => (int) $priceList->id,
            'manufacturer' => (string) $priceList->manufacturer,
            'original_filename' => (string) $priceList->original_filename,
            'cards' => $cards,
        ]];
    }

    /**
     * @return array{codes: array<string, string>, keys: array<string, true>, literals: array<string, true>}
     */
    private function tokens(string $path, PriceListFileReconciler $reconciler, SpreadsheetCellReader $cells, PriceListPdfTextExtractor $pdf): array
    {
        if (str_ends_with(mb_strtolower($path), '.pdf')) {
            $text = $pdf->extractLayout($path, 0) ?? $pdf->extract($path, 0);
            $texts = [];
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                foreach (preg_split('/\s{2,}|\t/u', trim($line)) ?: [] as $cell) {
                    $texts[] = $cell;
                }
            }

            return $reconciler->tokensFromCells($texts);
        }
        $book = IOFactory::load($path);
        $texts = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            foreach ($cells->toRows($sheet) as $row) {
                foreach ($row as $cell) {
                    if ($cell !== '') {
                        $texts[] = $cell;
                    }
                }
            }
        }
        $book->disconnectWorksheets();

        return $reconciler->tokensFromCells($texts);
    }

    /**
     * Pliki katalogu (z podkatalogami) po nazwie małymi literami; przy powtórzonej nazwie — najnowszy.
     *
     * @return array<string, string>
     */
    private function filesByName(string $dir): array
    {
        $out = [];
        $times = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $key = mb_strtolower($file->getFilename());
            if (! isset($out[$key]) || $file->getMTime() > $times[$key]) {
                $out[$key] = $file->getPathname();
                $times[$key] = $file->getMTime();
            }
        }

        return $out;
    }
}
