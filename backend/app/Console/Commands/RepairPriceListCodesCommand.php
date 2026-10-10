<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\ErpItemLink;
use App\Models\ManufacturerPart;
use App\Models\OfferItem;
use App\Models\PrestaProductMatch;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Models\TenderItem;
use App\Services\Catalog\CardRedirectStore;
use App\Services\Catalog\PriceListFileReconciler;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\PriceListImportService;
use App\Services\ProductSizeMergeService;
use App\Services\SpreadsheetColumnMapper;
use App\Services\SpreadsheetMappingHeuristic;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

/**
 * Naprawa kart cennika z pliku, któremu dawny import uciął kody regułą „rozmiar na końcu kodu” (Coba 12.09.2026:
 * AF010005 → AF0100, DP010004 → DP0100-4), zlał pozycje o tej samej cenie w jedną kartę i dał części kart nazwę
 * z nagłówka grupy albo z sąsiedniego wiersza (decyzja właściciela 10.10.2026, plan
 * SUPON_AI_Coba_tabela_czesci/PLAN_naprawa_importu_2026-10-10.md, część A).
 *
 * Plik czyta POPRAWIONY importer (PriceListImportService::fileRowsForRepair — te same reguły co import), a porównanie
 * robi PriceListFileReconciler: karta z uciętym kodem dostaje kod wiersza, nazwa z pliku — nazwę własnego wiersza,
 * zlane pozycje i pozycje, których import nie wczytał (np. arkusz „Noże bezpieczne”) — nowe karty. Karta zachowuje id,
 * więc opisy, zdjęcia, historia cen, oferty, przetargi, ERP i powiązania B2B zostają. Nic nie jest usuwane.
 *
 * Mapowanie kolumn: --mapping=plik.json (jak w oknie importu) albo rozpoznanie nagłówków bez AI
 * (SpreadsheetMappingHeuristic + SpreadsheetColumnMapper::refineMapping, jak products:repair-price-list-names).
 * Kolumna „ilość w opakowaniu” odgadnięta z samych liczb (nagłówek jej nie nazywa — w Coba to „Waga [kg]”) jest
 * pomijana, żeby nowe karty nie dostały wagi jako liczby sztuk. Bezpiecznik: co najmniej 95% kart z dokładnym kodem musi
 * mieć w pliku tę samą cenę co slot pliku — inaczej mapowanie nie pasuje do pliku i nic nie zapisujemy.
 *
 * Domyślnie podgląd + raport CSV (storage/app/repair-reports). --apply: kopia zapasowa JSON (zapis i kontrolny odczyt),
 * każda karta w osobnej transakcji z blokadą i compare-and-set (kod, nazwa, updated_at z podglądu); pomija karty
 * w trakcie pobierania opisu i kody zajęte od podglądu; zapis przez model (indeks tekstowy, wektor); pamięć SKU
 * (product_enrichment_caches) idzie za nowym kodem; identyfikatory wierszy (product_identifiers file:{cennik}) na końcu
 * jednym zapisem. --restore cofa kod i nazwę (compare-and-set ze stanem po naprawie), pamięć SKU i identyfikatory;
 * nowe karty zostają (wypisane).
 */
final class RepairPriceListCodesCommand extends Command
{
    private const BACKUP_LABEL = 'repair-price-list-codes';

    private const SUPPLIER_SPECIAL_NEW_CARDS = 'Cennik ma ceny specjalne dostawcy, a mapowanie nie ma kolumny „Cena normalna (standardowa)” — '
        .'nowe karty nie zostaną założone (poprawki kodów i nazw tak). Podaj --mapping=plik.json z rolą standard_price.';

    private const ACTION_LABELS = [
        PriceListFileReconciler::ACTION_UPDATE => 'zmiana',
        PriceListFileReconciler::ACTION_KEEP => 'bez zmian',
        PriceListFileReconciler::ACTION_NEW => 'nowa karta',
        PriceListFileReconciler::ACTION_COLLISION => 'kolizja kodu',
        PriceListFileReconciler::ACTION_AMBIGUOUS => 'niejednoznaczne',
        PriceListFileReconciler::ACTION_BLOCKED => 'blokada',
        PriceListFileReconciler::ACTION_NO_ROW => 'bez wiersza',
    ];

    private const MATCH_LABELS = [
        PriceListFileReconciler::MATCH_REDIRECT => 'mapa połączeń',
        PriceListFileReconciler::MATCH_EXACT => 'kod dokładny',
        PriceListFileReconciler::MATCH_CUT => 'kod ucięty',
    ];

    protected $signature = 'products:repair-price-list-codes
                            {file? : Plik cennika XLSX, z którego importowano karty (np. storage/app/repair-inputs/…)}
                            {--price-list= : Numer cennika (price_lists.id)}
                            {--mapping= : Plik JSON z mapowaniem kolumn (domyślnie rozpoznanie nagłówków bez AI)}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd i raport CSV)}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć kody, nazwy, pamięć SKU i identyfikatory z kopii zapasowej i zakończ}
                            {--limit=40 : Wierszy w tabeli podglądu (0 = wszystkie)}';

    protected $description = 'Poprawia karty cennika z pliku: kody ucięte dawną regułą rozmiaru, nazwy z sąsiednich wierszy, nowe karty dla zlanych i pominiętych pozycji (podgląd bez --apply)';

    public function handle(
        PriceListImportService $importer,
        PriceListFileReconciler $reconciler,
        SpreadsheetMappingHeuristic $heuristic,
        SpreadsheetColumnMapper $columns,
        ProductIdentifierStore $identifierStore,
        ProductImportExclusions $exclusions,
        ProductSizeMergeService $sizeMerge,
    ): int {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $priceListId = (int) $this->option('price-list');
        $priceList = $priceListId > 0 ? PriceList::query()->find($priceListId) : null;
        if ($priceList === null) {
            $this->error($priceListId > 0 ? "Nie ma cennika numer {$priceListId}." : 'Podaj numer cennika, np. --price-list=14.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('file');
        if ($path === '' || ! is_file($path)) {
            $this->error("Brak pliku: {$path}");

            return self::FAILURE;
        }

        $mapping = $this->mapping($path, $heuristic, $columns);
        if (is_string($mapping)) {
            $this->error($mapping);

            return self::FAILURE;
        }

        $file = $importer->fileRowsForRepair($path, $mapping, (string) $priceList->manufacturer);
        if ($file['rows'] === []) {
            $this->error('Plik nie dał żadnej pozycji — nic nie zmieniam.');

            return self::FAILURE;
        }

        // karty tego cennika (slot „file”) i karty docelowe mapy połączeń tego cennika
        $sourceKey = ProductIdentifierStore::fileKey((int) $priceList->id);
        $slots = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('price_list_id', $priceList->id)
            ->get()
            ->keyBy('product_id');
        $redirects = [];
        foreach (CardRedirect::query()->where('source_key', $sourceKey)->get() as $entry) {
            $redirects[CardRedirectStore::key($sourceKey, (string) $entry->position_key)] = $entry->product_id !== null ? (int) $entry->product_id : null;
        }
        $ids = array_values(array_unique([
            ...array_map('intval', $slots->keys()->all()),
            ...array_values(array_filter($redirects, static fn (?int $id): bool => $id !== null)),
        ]));
        /** @var array<int, Product> $products */
        $products = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->get() as $product) {
                $products[(int) $product->id] = $product;
            }
        }
        $cards = [];
        foreach ($products as $id => $product) {
            $slot = $slots->get($id);
            $cards[$id] = [
                'id' => $id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'price' => $sizeMerge->filePriceBucket($product, $slot instanceof ProductSourcePrice ? $slot : null),
                'list_card' => $slot !== null,
            ];
        }

        $plan = $reconciler->plan(
            $file['rows'],
            $cards,
            $file['file_names'],
            $sourceKey,
            $redirects,
            $exclusions->forPriceList($priceList),
            $this->takenSkus(array_map(static fn (array $row): string => $row['sku'], $file['rows'])),
        );
        $entries = $plan['entries'];
        $check = $plan['price_check'];

        $report = $this->writeReport($priceList, $entries, $products, $slots->all(), $reconciler);
        $this->printSummary($priceList, $file, $entries, $products, $slots->count(), $check, $report);

        if ($check['exact'] === 0 || $check['ratio'] < PriceListFileReconciler::MIN_PRICE_AGREEMENT) {
            $this->error(sprintf(
                'Bezpiecznik: cena z pliku zgadza się tylko na %d z %d kart z dokładnym kodem (%.1f%%, wymagane %d%%) — mapowanie kolumn'
                .' nie pasuje do pliku albo to inny plik. Nic nie zapisuję; podaj --mapping=plik.json.',
                $check['agree'],
                $check['exact'],
                $check['ratio'] * 100,
                (int) round(PriceListFileReconciler::MIN_PRICE_AGREEMENT * 100),
            ));

            return self::FAILURE;
        }

        $changes = array_values(array_filter($entries, static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_UPDATE));
        $news = array_values(array_filter($entries, static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_NEW));
        if ($changes === [] && $news === []) {
            $this->info('Nic do zapisania — karty zgadzają się z plikiem.');

            return self::SUCCESS;
        }
        // Cennik z cenami specjalnymi dostawcy (has_supplier_special): nowa karta bez kolumny ceny normalnej dostałaby
        // cenę specjalną jako zwykły zakup (bez oceny, widoczny dla wszystkich). Poprawki kodów i nazw idą dalej — nie
        // zmieniają cen kart.
        $newCardsBlocked = $priceList->has_supplier_special && ! $importer->mappingHasStandardPrice($mapping);
        if ($newCardsBlocked && $news !== []) {
            $this->warn(self::SUPPLIER_SPECIAL_NEW_CARDS.' Nowych kart w pliku: '.count($news).'.');
        }
        if (! $this->option('apply')) {
            $this->info('Podgląd — przejrzyj raport CSV i uruchom z --apply, żeby zapisać.');

            return self::SUCCESS;
        }

        return $this->apply($priceList, $entries, $changes, $products, $importer, $identifierStore, $newCardsBlocked);
    }

    /**
     * Mapowanie z --mapping albo rozpoznane z nagłówków (bez AI). Błąd = tekst.
     *
     * @return array{sheets: list<array<string, mixed>>}|string
     */
    private function mapping(string $path, SpreadsheetMappingHeuristic $heuristic, SpreadsheetColumnMapper $columns): array|string
    {
        $mappingPath = trim((string) $this->option('mapping'));
        if ($mappingPath !== '') {
            if (! is_file($mappingPath)) {
                return "Brak pliku mapowania: {$mappingPath}";
            }
            try {
                $mapping = json_decode((string) file_get_contents($mappingPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return 'Plik mapowania nie jest poprawnym JSON: '.$e->getMessage();
            }
            if (! is_array($mapping) || ! is_array($mapping['sheets'] ?? null)) {
                return 'Plik mapowania musi mieć listę „sheets” (jak mapowanie w oknie importu).';
            }
            $this->line('Mapowanie z pliku: '.$mappingPath);
        } else {
            $mapping = $heuristic->detect($path);
            if ($mapping === null) {
                return 'Nie rozpoznano kolumn cennika — podaj --mapping=plik.json.';
            }
            $mapping = $this->withoutGuessedPackQty($path, $columns->refineMapping($path, $mapping), $columns);
        }
        foreach ($mapping['sheets'] as $sheet) {
            $used = array_filter(is_array($sheet['columns'] ?? null) ? $sheet['columns'] : [], static fn ($column): bool => $column !== null);
            $this->line(sprintf(
                'Arkusz „%s”: %s %s',
                (string) ($sheet['sheet'] ?? ''),
                ($sheet['include'] ?? false) ? 'wczytany' : 'pominięty ('.((string) ($sheet['role'] ?? '') ?: 'bez kolumn').')',
                ($sheet['include'] ?? false) ? json_encode($used, JSON_UNESCAPED_UNICODE) : '',
            ));
        }

        return $mapping;
    }

    /**
     * Kolumna ilości w opakowaniu, której nagłówek tego nie mówi (heurystyka odgadła ją z liczb — w Coba „Waga [kg]”),
     * wypada z mapowania: nowa karta dostałaby wagę jako liczbę sztuk w opakowaniu.
     *
     * @param  array{sheets: list<array<string, mixed>>}  $mapping
     * @return array{sheets: list<array<string, mixed>>}
     */
    private function withoutGuessedPackQty(string $path, array $mapping, SpreadsheetColumnMapper $columns): array
    {
        $guessed = [];
        foreach ($mapping['sheets'] as $sheet) {
            if (($sheet['include'] ?? false) && is_numeric($sheet['columns']['pack_qty'] ?? null)) {
                $guessed[(string) $sheet['sheet']] = max(1, (int) ($sheet['header_excel_row'] ?? 1));
            }
        }
        if ($guessed === []) {
            return $mapping;
        }
        $maxRow = max($guessed);
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(array_keys($guessed));
        // same nagłówki — cały plik Coby czytany bez filtra to setki MB
        $reader->setReadFilter(new class($maxRow) implements IReadFilter
        {
            public function __construct(private readonly int $maxRow) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRow && Coordinate::columnIndexFromString($columnAddress) <= 28;
            }
        });
        $book = $reader->load($path);
        foreach ($mapping['sheets'] as $i => $sheet) {
            $name = (string) ($sheet['sheet'] ?? '');
            if (! isset($guessed[$name]) || ($worksheet = $book->getSheetByName($name)) === null) {
                continue;
            }
            $labels = [];
            for ($c = 1; $c <= 28; $c++) {
                $labels[] = trim((string) $worksheet->getCell(Coordinate::stringFromColumnIndex($c).$guessed[$name])->getValue());
            }
            $column = (int) $sheet['columns']['pack_qty'];
            if ($columns->mapLabels($labels)['pack_qty'] !== $column) {
                unset($mapping['sheets'][$i]['columns']['pack_qty']);
                $this->line("Arkusz „{$name}”: kolumna „".($labels[$column] ?? '?').'” odgadnięta jako ilość w opakowaniu z samych liczb — pominięta.');
            }
        }
        $book->disconnectWorksheets();

        return $mapping;
    }

    /**
     * Kody wierszy pliku zajęte w bazie (bez rozróżniania wielkości liter — products.sku jest unikalne).
     *
     * @param  list<string>  $codes
     * @return array<string, int> kod małymi literami => id karty
     */
    private function takenSkus(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static fn (string $c): string => mb_strtolower(trim($c)), $codes))));
        $taken = [];
        foreach (array_chunk($codes, 500) as $chunk) {
            foreach (Product::query()->whereIn(DB::raw('LOWER(sku)'), $chunk)->get(['id', 'sku']) as $product) {
                $taken[mb_strtolower(trim((string) $product->sku))] = (int) $product->id;
            }
        }

        return $taken;
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  list<array<string, mixed>>  $entries
     * @param  array<int, Product>  $products
     * @param  array{exact: int, agree: int, ratio: float}  $check
     */
    private function printSummary(PriceList $priceList, array $file, array $entries, array $products, int $listCards, array $check, ?string $report): void
    {
        $limit = max(0, (int) $this->option('limit'));
        $shown = array_values(array_filter($entries, static fn (array $e): bool => $e['action'] !== PriceListFileReconciler::ACTION_KEEP));
        $rows = $limit > 0 ? array_slice($shown, 0, $limit) : $shown;
        $this->table(
            ['ID', 'Akcja', 'Dopasowanie', 'Kod teraz', 'Kod nowy', 'Nazwa teraz', 'Nazwa nowa', 'Arkusz:wiersz', 'Uwagi'],
            array_map(static function (array $e) use ($products): array {
                $card = $e['card_id'] !== null ? ($products[$e['card_id']] ?? null) : null;

                return [
                    $e['card_id'] ?? '—',
                    self::ACTION_LABELS[$e['action']] ?? $e['action'],
                    self::MATCH_LABELS[$e['match'] ?? ''] ?? '',
                    $card !== null ? (string) $card->sku : '',
                    $e['new_sku'] ?? ($e['action'] === PriceListFileReconciler::ACTION_NEW ? '' : (string) ($e['row']['sku'] ?? '')),
                    $card !== null ? mb_substr((string) $card->name, 0, 45) : '',
                    mb_substr((string) ($e['new_name'] ?? ''), 0, 45),
                    $e['row'] !== null ? ($e['row']['sheet'] ?? '').':'.($e['row']['row'] ?? '') : '',
                    mb_substr(implode('; ', $e['notes']), 0, 90),
                ];
            }, $rows),
        );

        $count = static fn (callable $filter): int => count(array_filter($entries, $filter));
        $update = PriceListFileReconciler::ACTION_UPDATE;
        $newBySheet = [];
        foreach ($entries as $e) {
            if ($e['action'] === PriceListFileReconciler::ACTION_NEW) {
                $sheet = (string) ($e['row']['sheet'] ?? '?');
                $newBySheet[$sheet] = ($newBySheet[$sheet] ?? 0) + 1;
            }
        }
        arsort($newBySheet);
        $this->line(sprintf('Plik: %d pozycji z %d arkuszy; wierszy z kodem bez ceny (pominięte): %d. Kart cennika #%d: %d.',
            count($file['rows']),
            count($file['sheets']),
            count($file['unpriced']),
            $priceList->id,
            $listCards,
        ));
        $this->line(sprintf(
            'Zmiany: kod %d, nazwa %d, kod+nazwa %d; bez zmian %d; nowe karty %d (%s); kolizje kodu %d; niejednoznaczne %d; blokady %d; karty bez wiersza %d; nazwy nie z pliku (zostają) %d.',
            $count(static fn (array $e): bool => $e['action'] === $update && $e['new_sku'] !== null && $e['new_name'] === null),
            $count(static fn (array $e): bool => $e['action'] === $update && $e['new_sku'] === null && $e['new_name'] !== null),
            $count(static fn (array $e): bool => $e['action'] === $update && $e['new_sku'] !== null && $e['new_name'] !== null),
            $count(static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_KEEP),
            array_sum($newBySheet),
            implode(', ', array_map(static fn (string $s, int $n): string => "{$s}: {$n}", array_keys($newBySheet), $newBySheet)) ?: '—',
            $count(static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_COLLISION || in_array(true, array_map(static fn (string $n): bool => str_contains($n, 'kod zostaje'), $e['notes']), true)),
            $count(static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_AMBIGUOUS),
            $count(static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_BLOCKED),
            $count(static fn (array $e): bool => $e['action'] === PriceListFileReconciler::ACTION_NO_ROW),
            $count(static fn (array $e): bool => in_array('nazwa nie z pliku — zostaje', $e['notes'], true)),
        ));
        $running = $count(static fn (array $e): bool => $e['action'] === $update
            && ($products[$e['card_id']] ?? null)?->enrichment_status === Product::ENRICHMENT_RUNNING);
        if ($running > 0) {
            $this->warn("Karty w trakcie pobierania opisu: {$running} — --apply je pominie (uruchom ponownie po zakończeniu pobierania).");
        }
        $this->line(sprintf('Bezpiecznik ceny: %d z %d kart z dokładnym kodem ma w pliku tę samą cenę (%.1f%%).', $check['agree'], $check['exact'], $check['ratio'] * 100));
        $this->line(sprintf('Szczyt pamięci: %d MB (limit %s).', (int) round(memory_get_peak_usage(true) / 1048576), (string) ini_get('memory_limit')));
        if ($report !== null) {
            $this->info('Raport CSV: '.$report);
        }
    }

    /**
     * Raport CSV podglądu (storage/app/repair-reports/price-list-codes-{cennik}-{data}.csv).
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<int, Product>  $products
     * @param  array<int, ProductSourcePrice>  $slots  id karty => slot pliku tego cennika
     */
    private function writeReport(PriceList $priceList, array $entries, array $products, array $slots, PriceListFileReconciler $reconciler): ?string
    {
        $dir = storage_path('app/repair-reports');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->warn("Raport CSV nie powstał: nie można utworzyć katalogu {$dir}");

            return null;
        }
        $path = $dir.'/price-list-codes-'.$priceList->id.'-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'w');
        if ($handle === false) {
            $this->warn("Raport CSV nie powstał: nie mogę zapisać pliku {$path}");

            return null;
        }
        $links = $this->linkCounts(array_keys($products));
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'id', 'akcja', 'dopasowanie', 'sku_teraz', 'sku_nowe', 'nazwa_teraz', 'nazwa_nowa', 'arkusz', 'wiersz',
            'cena_pliku', 'cena_karty', 'oferty', 'przetargi', 'erp', 'presta', 'b2b', 'wersje_opisu',
            'enrichment_status', 'parts_table_part', 'parts_table_via_short_code', 'przypiecie_do_sprawdzenia', 'uwagi',
        ], ';');
        foreach ($entries as $e) {
            $card = $e['card_id'] !== null ? ($products[$e['card_id']] ?? null) : null;
            $id = $card !== null ? (int) $card->id : null;
            $pin = $card !== null && is_array($card->enrichment_payload['parts_table'] ?? null) ? $card->enrichment_payload['parts_table'] : null;
            $slot = $id !== null ? ($slots[$id] ?? null) : null;
            fputcsv($handle, [
                $id ?? '',
                self::ACTION_LABELS[$e['action']] ?? $e['action'],
                self::MATCH_LABELS[$e['match'] ?? ''] ?? '',
                $card !== null ? (string) $card->sku : '',
                $e['new_sku'] ?? '',
                $card !== null ? (string) $card->name : '',
                $e['new_name'] ?? '',
                $e['row']['sheet'] ?? '',
                $e['row']['row'] ?? '',
                $e['row'] !== null ? $reconciler->rowPrice($e['row']) : '',
                $slot !== null ? (string) $slot->catalog_price_net : ($card !== null ? (string) $card->catalog_price_net : ''),
                $id !== null ? ($links['offers'][$id] ?? 0) : '',
                $id !== null ? ($links['tenders'][$id] ?? 0) : '',
                $id !== null ? ($links['erp'][$id] ?? 0) : '',
                $id !== null ? ($links['presta'][$id] ?? 0) : '',
                $id !== null ? ($links['b2b'][$id] ?? 0) : '',
                $id !== null ? ($links['versions'][$id] ?? 0) : '',
                $card !== null ? (string) $card->enrichment_status : '',
                $pin !== null ? (string) ($pin['part'] ?? '') : '',
                $pin !== null ? (! empty($pin['via_short_code']) ? 'tak' : 'nie') : '',
                $this->pinCheck($e, $card, $pin),
                implode('; ', [...$e['notes'], ...($e['candidates'] !== [] ? ['kandydaci: #'.implode(', #', $e['candidates'])] : [])]),
            ], ';');
        }
        fclose($handle);

        return $path;
    }

    /**
     * „Przypięcie do sprawdzenia” (tabela części coba.com, enrichment_payload.parts_table): nowa karta, karta ze zmianą
     * kodu bez przypięcia albo przypięta skrótem cennika lub do innego numeru części niż nowy kod.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|null  $pin
     */
    private function pinCheck(array $entry, ?Product $card, ?array $pin): string
    {
        if ($entry['action'] === PriceListFileReconciler::ACTION_NEW) {
            return 'tak (nowa karta)';
        }
        if ($entry['action'] !== PriceListFileReconciler::ACTION_UPDATE || $entry['new_sku'] === null || $card === null) {
            return '';
        }
        if ($pin === null) {
            return 'tak (bez przypięcia)';
        }
        if (! empty($pin['via_short_code'])) {
            return 'tak (przypięta skrótem cennika)';
        }

        return ManufacturerPart::codeKey((string) ($pin['part'] ?? '')) !== ManufacturerPart::codeKey((string) $entry['new_sku'])
            ? 'tak (inny numer części: '.(string) ($pin['part'] ?? '').')'
            : 'nie';
    }

    /**
     * Liczby powiązań kart (po id — zmiana kodu ich nie rusza, ale oferty i PDF wysłane mają stary kod).
     *
     * @param  list<int>  $ids
     * @return array<string, array<int, int>>
     */
    private function linkCounts(array $ids): array
    {
        $count = static function (string $model, string $column) use ($ids): array {
            $out = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($model::query()->toBase()->whereIn($column, $chunk)->selectRaw("{$column} as pid, count(*) as c")->groupBy($column)->get() as $row) {
                    $out[(int) $row->pid] = ($out[(int) $row->pid] ?? 0) + (int) $row->c;
                }
            }

            return $out;
        };
        $tenders = $count(TenderItem::class, 'main_product_id');
        foreach ($count(TenderItem::class, 'companion_product_id') as $id => $n) {
            $tenders[$id] = ($tenders[$id] ?? 0) + $n;
        }

        return [
            'offers' => $count(OfferItem::class, 'product_id'),
            'tenders' => $tenders,
            'erp' => $count(ErpItemLink::class, 'product_id'),
            'presta' => $count(PrestaProductMatch::class, 'product_id'),
            'b2b' => $count(B2bProductLink::class, 'product_id'),
            'versions' => $count(ProductDescriptionVersion::class, 'product_id'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $changes
     * @param  array<int, Product>  $products
     */
    private function apply(
        PriceList $priceList,
        array $entries,
        array $changes,
        array $products,
        PriceListImportService $importer,
        ProductIdentifierStore $identifierStore,
        bool $newCardsBlocked = false,
    ): int {
        $sourceKey = ProductIdentifierStore::fileKey((int) $priceList->id);
        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/price-list-codes-'.$priceList->id.'-'.now()->format('Ymd-His').'.json');
        }
        $backupData = [
            'label' => self::BACKUP_LABEL,
            'created_at' => now()->toIso8601String(),
            'price_list_id' => (int) $priceList->id,
            'products' => array_map(function (array $c) use ($products): array {
                $product = $products[$c['card_id']];
                $cacheKey = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);

                return [
                    'id' => (int) $product->id,
                    'sku' => (string) $product->sku,
                    'name' => (string) $product->name,
                    // stan po naprawie — --restore cofa kartę tylko wtedy, gdy od naprawy nikt jej nie zmienił
                    'written_sku' => $c['new_sku'] ?? (string) $product->sku,
                    'written_name' => $c['new_name'] ?? (string) $product->name,
                    'cache_id' => $c['new_sku'] !== null
                        ? ProductEnrichmentCache::query()->where('manufacturer', $cacheKey['manufacturer'])->where('sku', $cacheKey['sku'])->value('id')
                        : null,
                ];
            }, $changes),
            // identyfikatory wierszy tego cennika sprzed naprawy — --restore oddaje im kartę i znacznik zniknięcia
            'identifiers' => ProductIdentifier::query()->where('source_key', $sourceKey)->get(['id', 'product_id', 'removed_at'])
                ->map(static fn (ProductIdentifier $i): array => [
                    'id' => (int) $i->id,
                    'product_id' => (int) $i->product_id,
                    'removed_at' => $i->removed_at?->toDateTimeString(),
                ])->values()->all(),
            // 'created' (nowe karty) dopisuje drugi zapis po założeniu kart — bez niego --restore nie rusza identyfikatorów
        ];
        $error = $this->writeJson($backup, $backupData);
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }
        $saved = 0;
        $cacheKept = [];
        $skipped = [];
        foreach ($changes as $change) {
            $before = $products[$change['card_id']];
            $reason = DB::transaction(function () use ($change, $before, &$cacheKept): ?string {
                $product = Product::query()->lockForUpdate()->find($before->id);
                // compare-and-set: karta zmieniona od podglądu (człowiek, synchronizacja, import) zostaje, jak jest
                if ($product === null || $product->sku !== $before->sku || $product->name !== $before->name
                    || $product->updated_at?->toDateTimeString() !== $before->updated_at?->toDateTimeString()) {
                    return 'karta zmieniła się od podglądu';
                }
                if ($product->enrichment_status === Product::ENRICHMENT_RUNNING) {
                    return 'opis w trakcie pobierania';
                }
                if ($change['new_sku'] !== null && self::skuTakenBy($change['new_sku'], (int) $product->id) !== null) {
                    return "kod {$change['new_sku']} zajęty od podglądu";
                }
                $oldSku = (string) $product->sku;
                if ($change['new_sku'] !== null) {
                    $product->sku = $change['new_sku'];
                }
                if ($change['new_name'] !== null) {
                    $product->name = $change['new_name'];
                }
                // przez model: hak saving przelicza indeks tekstowy, updated zleca reindeks wektora
                $product->save();
                if ($change['new_sku'] !== null && ! $this->moveEnrichmentCache((string) $product->manufacturer, $oldSku, (string) $product->sku)) {
                    $cacheKept[] = '#'.$product->id;
                }

                return null;
            });
            if ($reason === null) {
                $saved++;
            } else {
                $skipped[] = '#'.$before->id.' ('.$reason.')';
            }
        }

        // nowe karty (zlane albo niewczytane pozycje) — przez ten sam kod co import
        /** @var array<int, int> $createdFor indeks wpisu => id nowej karty */
        $createdFor = [];
        $created = 0;
        foreach ($entries as $index => $entry) {
            if ($entry['action'] !== PriceListFileReconciler::ACTION_NEW) {
                continue;
            }
            $row = $entry['row'];
            if ($newCardsBlocked) {
                $skipped[] = $row['sku'].' (nowa karta: cennik ma ceny specjalne dostawcy, a mapowanie nie ma kolumny ceny normalnej)';

                continue;
            }
            try {
                $productId = DB::transaction(function () use ($row, $priceList, $importer): ?int {
                    if (self::skuTakenBy((string) $row['sku'], 0) !== null) {
                        return null;
                    }
                    $card = $importer->createFileCard($priceList, (string) $row['sku'], $row['payload']);
                    $importer->recordFileHistory((int) $card['product']->id, $card['slot'], $priceList, null);

                    return (int) $card['product']->id;
                });
            } catch (Throwable $e) {
                $skipped[] = $row['sku'].' (nowa karta: '.$e->getMessage().')';

                continue;
            }
            if ($productId === null) {
                $skipped[] = $row['sku'].' (kod zajęty od podglądu — bez nowej karty)';

                continue;
            }
            $createdFor[$index] = $productId;
            $created++;
        }
        // identyfikatory wierszy: wszystkie wiersze pliku naraz (recordFile oznacza zniknięte spoza listy); okno czasu
        // zapisu trafia do kopii — --restore rusza tylko wiersze z tego zapisu
        $writtenFrom = now()->startOfSecond();
        $gone = DB::transaction(fn (): int => $identifierStore->recordFile($priceList, null, $this->identifierRows($entries, $createdFor, $sourceKey)));
        $backupData['created'] = array_values($createdFor);
        $backupData['identifiers_written'] = [
            'from' => $writtenFrom->toDateTimeString(),
            'to' => now()->addSecond()->startOfSecond()->toDateTimeString(),
        ];
        $error = $this->writeJson($backup, $backupData);
        if ($error !== null) {
            $this->warn("Nie dopisano nowych kart do kopii zapasowej ({$error}) — --restore nie cofnie identyfikatorów.");
        }

        $this->info("Zapisano: {$saved} kart poprawionych, {$created} nowych kart. Identyfikatory wierszy zapisane (oznaczone jako zniknięte z pliku: {$gone}).");
        if ($cacheKept !== []) {
            $this->warn('Pamięć opisu pod nowym kodem już istniała — stara zostaje (sprawdź): '.implode(', ', $cacheKept));
        }
        if ($skipped !== []) {
            $this->warn('Pominięte przy zapisie: '.implode('; ', $skipped));
        }
        $this->info("Kopia zapasowa: {$backup}");
        $this->line("Przywrócenie stanu sprzed (nowe karty zostają): --restore=\"{$backup}\"");
        $this->line(sprintf('Szczyt pamięci: %d MB (limit %s).', (int) round(memory_get_peak_usage(true) / 1048576), (string) ini_get('memory_limit')));

        return self::SUCCESS;
    }

    /**
     * Pamięć opisu (manufacturer, sku) idzie za nowym kodem karty. Pod nowym kodem już coś jest — stara zostaje (false).
     */
    private function moveEnrichmentCache(string $manufacturer, string $oldSku, string $newSku): bool
    {
        $old = ProductEnrichmentCache::normalizeKey($manufacturer, $oldSku);
        $new = ProductEnrichmentCache::normalizeKey($manufacturer, $newSku);
        if ($old === $new) {
            return true;
        }
        $row = ProductEnrichmentCache::query()->where('manufacturer', $old['manufacturer'])->where('sku', $old['sku'])->first();
        if ($row === null) {
            return true;
        }
        if (ProductEnrichmentCache::query()->where('manufacturer', $new['manufacturer'])->where('sku', $new['sku'])->exists()) {
            return false;
        }
        $row->update(['sku' => $new['sku']]);

        return true;
    }

    /**
     * Identyfikatory wszystkich wierszy pliku przy kartach z planu. Wiersz bez karty (kolizja, niejednoznaczne,
     * blokada, nowa karta, której nie dało się założyć) zostawia swoje dotychczasowe identyfikatory przy ich kartach —
     * inaczej recordFile oznaczyłby je jako zniknięte z pliku, choć w pliku są.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<int, int>  $createdFor
     * @return array<int, list<array<string, mixed>>>
     */
    private function identifierRows(array $entries, array $createdFor, string $sourceKey): array
    {
        $existing = ProductIdentifier::query()->where('source_key', $sourceKey)->get()->groupBy(static fn (ProductIdentifier $i): string => mb_strtolower((string) $i->position_key));
        $rows = [];
        foreach ($entries as $index => $entry) {
            $row = $entry['row'];
            if ($row === null) {
                continue;
            }
            $productId = $createdFor[$index] ?? ($entry['action'] !== PriceListFileReconciler::ACTION_NEW ? $entry['card_id'] : null);
            if ($productId !== null && in_array($entry['action'], [PriceListFileReconciler::ACTION_UPDATE, PriceListFileReconciler::ACTION_KEEP, PriceListFileReconciler::ACTION_NEW], true)) {
                $rows[$productId] = [...($rows[$productId] ?? []), ...$row['identifiers']];

                continue;
            }
            foreach ($row['identifiers'] as $identifier) {
                foreach ($existing->get(mb_strtolower(mb_substr(trim((string) ($identifier['position'] ?? '')), 0, 64)), collect()) as $kept) {
                    $rows[(int) $kept->product_id][] = [
                        'position' => (string) $kept->position_key,
                        'type' => (string) $kept->type,
                        'value' => (string) $kept->value,
                        'field' => $kept->source_field,
                        'label' => $kept->variant_label,
                    ];
                }
            }
        }

        return $rows;
    }

    /** Karta (inna niż $exceptId) z tym kodem, bez rozróżniania wielkości liter — products.sku jest unikalne. */
    private static function skuTakenBy(string $sku, int $exceptId): ?int
    {
        $id = Product::query()->whereRaw('LOWER(sku) = ?', [mb_strtolower(trim($sku))])->where('id', '!=', $exceptId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Zapis JSON z kontrolnym odczytem (przed pierwszą zmianą kopia musi dać się odczytać w całości).
     *
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $path, array $data): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $json) === false) {
                return 'zapis pliku nie powiódł się';
            }
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return count($read['products'] ?? []) === count($data['products'])
            && count($read['identifiers'] ?? []) === count($data['identifiers'])
            ? null
            : 'kopia jest niekompletna';
    }

    /**
     * Identyfikatory wierszy cennika: wiersze sprzed naprawy, które naprawa ruszyła, wracają do swojej karty
     * i znacznika; dopisane przez naprawę do kart sprzed naprawy dostają removed_at (bez kasowania); identyfikatory
     * nowych kart zostają z nimi. Tylko wiersze z okna zapisu naprawy — a po imporcie tego cennika nowszym niż naprawa
     * wcale (import zapisał już identyfikatory według pliku). Null = identyfikatorów nie ruszano.
     *
     * @param  array<string, mixed>  $data
     */
    private function restoreIdentifiers(array $data): ?int
    {
        $written = is_array($data['identifiers_written'] ?? null) ? $data['identifiers_written'] : null;
        if (! array_key_exists('created', $data) || $written === null || ! isset($written['from'], $written['to'])) {
            $this->warn('Kopia bez zapisu identyfikatorów naprawy — identyfikatorów nie ruszam.');

            return null;
        }
        $from = Carbon::parse((string) $written['from']);
        $to = Carbon::parse((string) $written['to']);
        $priceListId = (int) $data['price_list_id'];
        if (PriceListImport::query()->where('price_list_id', $priceListId)->where('created_at', '>=', $from)->exists()) {
            $this->warn("Cennik #{$priceListId} był importowany po naprawie — identyfikatorów wierszy nie ruszam (przywrócone tylko kody i nazwy).");

            return null;
        }
        $touched = static fn (mixed $at): bool => $at !== null && Carbon::parse($at)->betweenIncluded($from, $to);
        $created = array_map('intval', (array) $data['created']);
        $snapshot = [];
        foreach ((array) ($data['identifiers'] ?? []) as $row) {
            $snapshot[(int) $row['id']] = $row;
        }
        $hidden = 0;
        DB::transaction(function () use ($priceListId, $snapshot, $created, $touched, &$hidden): void {
            foreach (ProductIdentifier::query()->where('source_key', ProductIdentifierStore::fileKey($priceListId))->get() as $identifier) {
                $before = $snapshot[(int) $identifier->id] ?? null;
                if ($before !== null) {
                    if (! $touched($identifier->last_seen_at) && ! $touched($identifier->removed_at) && ! $touched($identifier->updated_at)) {
                        continue;
                    }
                    // karta z kopii usunięta w międzyczasie (ręczne łączenie, merge-size-variants) — identyfikator zostaje
                    // przy karcie, na którą przeniosło go łączenie
                    if (! Product::query()->whereKey((int) $before['product_id'])->exists()) {
                        continue;
                    }
                    $identifier->forceFill(['product_id' => (int) $before['product_id'], 'removed_at' => $before['removed_at']]);
                    if ($identifier->isDirty()) {
                        $identifier->save();
                    }

                    continue;
                }
                if ($touched($identifier->first_seen_at) && ! in_array((int) $identifier->product_id, $created, true) && $identifier->removed_at === null) {
                    $identifier->forceFill(['removed_at' => now()])->save();
                    $hidden++;
                }
            }
        });

        return $hidden;
    }

    private function restore(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Nie przywrócono: brak kopii zapasowej: {$path}");

            return self::FAILURE;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Nie przywrócono: kopia zapasowa jest uszkodzona: {$e->getMessage()}");

            return self::FAILURE;
        }
        if (($data['label'] ?? null) !== self::BACKUP_LABEL || ! isset($data['price_list_id'])) {
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-price-list-codes.');

            return self::FAILURE;
        }

        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $reason = $id > 0 && isset($entry['sku'], $entry['name'], $entry['written_sku'], $entry['written_name'])
                ? DB::transaction(function () use ($id, $entry): ?string {
                    $product = Product::query()->lockForUpdate()->find($id);
                    // compare-and-set: kartę zmienioną od naprawy (człowiek, synchronizacja) zostawiamy, jak jest
                    if ($product === null) {
                        return 'karta nie istnieje';
                    }
                    if ($product->sku !== $entry['written_sku'] || $product->name !== $entry['written_name']) {
                        return 'karta zmieniła się od naprawy';
                    }
                    if ($entry['sku'] !== $product->sku && self::skuTakenBy((string) $entry['sku'], $id) !== null) {
                        return 'dawny kod '.$entry['sku'].' ma teraz inna karta';
                    }
                    $writtenSku = (string) $product->sku;
                    $product->update(['sku' => (string) $entry['sku'], 'name' => (string) $entry['name']]);
                    // pamięć opisu wraca pod dawny kod (ten sam wiersz, który naprawa przeniosła)
                    if (($entry['cache_id'] ?? null) !== null && $writtenSku !== (string) $entry['sku']) {
                        $written = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, $writtenSku);
                        $old = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $entry['sku']);
                        $cache = ProductEnrichmentCache::query()->find((int) $entry['cache_id']);
                        if ($cache !== null && $cache->sku === $written['sku']
                            && ! ProductEnrichmentCache::query()->where('manufacturer', $old['manufacturer'])->where('sku', $old['sku'])->exists()) {
                            $cache->update(['sku' => $old['sku']]);
                        }
                    }

                    return null;
                })
                : 'niepełny wpis kopii';
            if ($reason === null) {
                $restored++;
            } else {
                $skipped[] = '#'.$id.' ('.$reason.')';
            }
        }

        $hidden = $this->restoreIdentifiers($data);

        $this->info("Przywrócono {$restored} kart z kopii {$path}".($hidden !== null ? "; identyfikatory dopisane przez naprawę oznaczone jako zniknięte: {$hidden}." : '.'));
        $created = array_map('intval', (array) ($data['created'] ?? []));
        if ($created !== []) {
            $this->line('Nowe karty z naprawy zostają (nic nie usuwamy): #'.implode(', #', $created));
        }
        if ($skipped !== []) {
            $this->warn('Pominięte: '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }
}
