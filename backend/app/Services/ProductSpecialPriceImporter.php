<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductSpecialPrice;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class ProductSpecialPriceImporter
{
    public function __construct(
        private readonly SpreadsheetColumnMapper $columns,
        private readonly CurrencyDetector $currencyDetector,
    ) {}

    public function importFromPath(string $path, string $manufacturer): int
    {
        if (! $this->looksLikeSpreadsheet($path)) {
            return 0;
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            return 0;
        }
        $imported = 0;
        $source = basename($path);

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $name = $sheet->getTitle();
            $rows = $sheet->toArray(null, true, true, false);
            if ($rows === []) {
                continue;
            }
            $header = array_map(static fn ($v) => trim((string) $v), $rows[0] ?? []);
            $kind = $this->columns->classifySheet($name, implode(' ', $header));
            if ($kind !== 'special') {
                continue;
            }

            $map = $this->columns->mapSpecialLabels($header);
            if (($map['purchase'] ?? null) === null || ($map['client_name'] ?? null) === null) {
                continue;
            }

            foreach (array_slice($rows, 1) as $row) {
                $imported += $this->persistRow($row, $map, $manufacturer, $source) ? 1 : 0;
            }
        }
        $spreadsheet->disconnectWorksheets();

        return $imported;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int|null>  $map
     */
    private function persistRow(array $row, array $map, string $manufacturer, string $source): bool
    {
        $clientName = trim((string) ($row[$map['client_name']] ?? ''));
        $price = $this->toPrice($row[$map['purchase']] ?? null);
        if ($clientName === '' || $price === null || $price <= 0) {
            return false;
        }

        $sku = isset($map['sku']) ? $this->normCode((string) ($row[$map['sku']] ?? '')) : '';
        $skuAlt = isset($map['sku_alt']) ? $this->normCode((string) ($row[$map['sku_alt']] ?? '')) : '';
        $ean = isset($map['ean']) ? $this->normCode((string) ($row[$map['ean']] ?? '')) : '';
        $product = $this->findProduct($manufacturer, $sku, $skuAlt, $ean);
        if ($product === null) {
            return false;
        }

        $currency = 'EUR';
        if (isset($map['currency'])) {
            $detected = $this->currencyDetector->detect((string) ($row[$map['currency']] ?? ''));
            if ($detected !== null) {
                $currency = $detected;
            }
        }

        $contract = isset($map['contract_ref'])
            ? $this->normCode((string) ($row[$map['contract_ref']] ?? ''))
            : '';
        $validFrom = isset($map['valid_from'])
            ? $this->toDate((string) ($row[$map['valid_from']] ?? ''))
            : null;

        ProductSpecialPrice::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'client_name' => $clientName,
                'contract_ref' => $contract !== '' ? $contract : '',
            ],
            [
                'client_id' => $this->matchClient($clientName),
                'price' => $price,
                'currency' => $currency,
                'valid_from' => $validFrom,
                'source' => $source,
            ],
        );

        return true;
    }

    private function findProduct(string $manufacturer, string $sku, string $skuAlt, string $ean): ?Product
    {
        $codes = array_values(array_filter([$sku, $skuAlt], static fn (string $v) => $v !== ''));
        if ($codes === [] && $ean === '') {
            return null;
        }

        return Product::query()
            ->where('manufacturer', $manufacturer)
            ->where(function ($query) use ($codes, $ean): void {
                if ($codes !== []) {
                    $query->whereIn('sku', $codes);
                }
                if ($ean !== '') {
                    $query->orWhere('ean', $ean);
                }
            })
            ->first();
    }

    private function matchClient(string $name): ?int
    {
        $exact = Client::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');
        if ($exact !== null) {
            return (int) $exact;
        }

        $needle = mb_strtolower($name);
        foreach (Client::query()->get(['id', 'name']) as $client) {
            $hay = mb_strtolower((string) $client->name);
            if ($hay !== '' && (str_contains($hay, $needle) || str_contains($needle, $hay))) {
                return (int) $client->id;
            }
        }

        return null;
    }

    private function toPrice(mixed $value): ?float
    {
        if ($value === null || $value === '' || strtoupper(trim((string) $value)) === 'N/A') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = str_replace(["\xc2\xa0", ' '], '', trim((string) $value));
        $s = str_replace(['€', 'eur', 'zł', 'pln'], '', mb_strtolower($s));
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    private function toDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strtoupper($value) === 'N/A') {
            return null;
        }
        foreach (['d/m/Y', 'j/n/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function normCode(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strtoupper($value) === 'N/A' || $value === '#N/A') {
            return '';
        }

        return $value;
    }

    private function looksLikeSpreadsheet(string $path): bool
    {
        $name = mb_strtolower($path);
        foreach (['.xlsx', '.xls', '.csv', '.ods'] as $ext) {
            if (str_ends_with($name, $ext)) {
                return true;
            }
        }
        if (! is_readable($path)) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 8);
        if ($head === '' || str_starts_with($head, '%PDF')) {
            return false;
        }

        return str_starts_with($head, 'PK') || str_starts_with($head, "\xD0\xCF");
    }
}
