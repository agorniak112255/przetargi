<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;
use App\Models\ProductSourcePin;
use App\Services\PriceLists\Importers\ImportedRow;
use App\Services\PriceLists\Importers\MapContext;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\Importers\PriceListImporter;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use App\Services\PriceLists\Importers\ReadContext;
use App\Services\PriceLists\Importers\ReadResult;
use App\Services\PriceLists\Importers\SourceDecision;
use App\Services\PriceLists\PriceListIntakeRunner;
use Illuminate\Support\Facades\DB;

/**
 * Atrapa importera cennika do testów przyjęcia: plik CSV „kod;nazwa;cena;ean;opakowanie” z nagłówkiem
 * „kod;nazwa;cena;ean;opakowanie” (inny nagłówek → PriceListFormatChanged). mapSource: kod z $pins → strona
 * producenta, kod z $needsFetch bez pobierania → „nie sprawdzono w podglądzie”, reszta nierozwiązana. $writeOnMap —
 * importer, który (błędnie) zapisuje do bazy. install() podmienia rejestr importerów.
 */
final class FakePriceListImporter implements PriceListImporter
{
    public const KEY = 'fake-test';

    public const HEADER = 'kod;nazwa;cena;ean;opakowanie';

    public static int $version = 1;

    /** @var array<string, string> kod => adres strony */
    public static array $pins = [];

    /** @var array<string, true> kody, które rozstrzyga dopiero pobranie strony */
    public static array $needsFetch = [];

    public static bool $writeOnMap = false;

    /** @var array<string, list<string>> kod => linie spec przypiętej karty */
    public static array $specs = [];

    /** @var list<string> kody kart w kolejności wywołań mapSource */
    public static array $mapped = [];

    /** @var list<list<ImportedRow>> wiersze przekazane do mapSource (w kolejności wywołań) */
    public static array $rows = [];

    public static ?float $lastGlobalDiscount = null;

    public static function install(): void
    {
        self::$version = 1;
        self::$pins = [];
        self::$needsFetch = [];
        self::$writeOnMap = false;
        self::$specs = [];
        self::$mapped = [];
        self::$rows = [];
        self::$lastGlobalDiscount = null;
        app()->instance(PriceListImporterRegistry::class, new TestPriceListImporterRegistry([self::class]));
    }

    public static function key(): string
    {
        return self::KEY;
    }

    public static function label(): string
    {
        return 'Atrapa testowa';
    }

    public static function version(): int
    {
        return self::$version;
    }

    public static function manufacturerKeys(): array
    {
        return ['anro'];
    }

    public function read(string $path, string $originalName, ReadContext $ctx): ReadResult
    {
        self::$lastGlobalDiscount = $ctx->globalDiscountPercent;
        $lines = preg_split('/\r?\n/', trim((string) file_get_contents($path))) ?: [];
        if (trim((string) ($lines[0] ?? '')) !== self::HEADER) {
            throw PriceListFormatChanged::because('nagłówek „'.($lines[0] ?? '').'” zamiast „'.self::HEADER.'”');
        }
        $rows = [];
        $skipped = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $cells = array_map('trim', explode(';', $line));
            $ref = 'wiersz '.($i + 2);
            if (($cells[0] ?? '') === '' || ! is_numeric($cells[2] ?? null)) {
                $skipped[] = ['ref' => $ref, 'sku' => ($cells[0] ?? '') !== '' ? $cells[0] : null, 'reason' => 'brak kodu albo ceny'];

                continue;
            }
            $rows[] = new ImportedRow(
                sku: $cells[0],
                name: (string) ($cells[1] ?? ''),
                catalogPriceNet: (float) $cells[2],
                ref: $ref,
                ean: ($cells[3] ?? '') !== '' ? $cells[3] : null,
                packaging: ($cells[4] ?? '') !== '' ? $cells[4] : null,
            );
        }

        return new ReadResult($rows, $skipped, count($lines) - 1, ['uwaga importera testowego']);
    }

    public function mapSource(Product $card, array $rows, MapContext $ctx): SourceDecision
    {
        self::$mapped[] = (string) $card->sku;
        self::$rows[] = $rows;
        if (self::$writeOnMap) {
            DB::table('price_lists')->update(['importer_notes' => 'zapis z podglądu']);
        }
        $sku = (string) $card->sku;
        if (isset(self::$needsFetch[$sku]) && ! $ctx->liveFetch()) {
            return SourceDecision::unresolved(PriceListIntakeRunner::NOT_CHECKED_REASON);
        }
        if (isset(self::$pins[$sku])) {
            return SourceDecision::pinned(self::$pins[$sku], ProductSourcePin::KIND_MANUFACTURER, ProductSourcePin::MATCH_EXACT_CODE, $sku, null, 'Strona '.$sku, self::$specs[$sku] ?? []);
        }

        return SourceDecision::unresolved('brak strony z kodem '.$sku, [['url' => 'https://maker.test/szukaj?q='.$sku]]);
    }

    /** Treść pliku CSV atrapy. @param  list<array{0: string, 1: string, 2: float|string, 3?: string, 4?: string}>  $rows */
    public static function csv(array $rows): string
    {
        $lines = [self::HEADER];
        foreach ($rows as $row) {
            $lines[] = implode(';', [$row[0], $row[1], (string) $row[2], $row[3] ?? '', $row[4] ?? '']);
        }

        return implode("\n", $lines)."\n";
    }
}
