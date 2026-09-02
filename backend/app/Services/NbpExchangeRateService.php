<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class NbpExchangeRateService
{
    private const CACHE_KEY = 'nbp.table_a.rates';

    private const CACHE_SECONDS = 21600;

    /** @var array<string, float> */
    private const FALLBACK = [
        'PLN' => 1.0,
        'EUR' => 4.25,
        'USD' => 3.85,
        'GBP' => 5.10,
        'CHF' => 4.80,
        'CZK' => 0.175,
        'SEK' => 0.40,
        'NOK' => 0.38,
        'DKK' => 0.57,
    ];

    /** @var array{rates: array<string, float>, as_of: string|null, source: string}|null */
    private ?array $memo = null;

    /**
     * @return array{rates: array<string, float>, as_of: string|null, source: string}
     */
    public function snapshot(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['rates']) && is_array($cached['rates']) && $cached['rates'] !== []) {
            /** @var array{rates: array<string, float>, as_of: string|null, source: string} $cached */
            $this->memo = $cached;

            return $this->memo;
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get('https://api.nbp.pl/api/exchangerates/tables/A/?format=json');
            if ($response->successful()) {
                $parsed = $this->parseTable($response->json());
                if ($parsed['rates'] !== []) {
                    Cache::put(self::CACHE_KEY, $parsed, self::CACHE_SECONDS);
                    $this->memo = $parsed;

                    return $this->memo;
                }
            }
        } catch (Throwable) {
        }

        $this->memo = [
            'rates' => self::FALLBACK,
            'as_of' => null,
            'source' => 'fallback',
        ];

        return $this->memo;
    }

    /**
     * @return array<string, float>
     */
    public function rates(): array
    {
        return $this->snapshot()['rates'];
    }

    public function toPln(float $amount, ?string $currency): float
    {
        $code = strtoupper(trim((string) $currency));
        if ($code === '' || $code === 'PLN') {
            return round($amount, 2);
        }

        $rate = $this->rates()[$code] ?? 1.0;

        return round($amount * $rate, 2);
    }

    public function toPlnOrNull(float|string|null $amount, ?string $currency): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $value = (float) $amount;
        if ($value <= 0) {
            return null;
        }

        return $this->toPln($value, $currency);
    }

    public function isForeign(?string $currency): bool
    {
        $code = strtoupper(trim((string) $currency));

        return $code !== '' && $code !== 'PLN';
    }

    public function purchasePln(Product $product): ?float
    {
        return $this->toPlnOrNull($product->purchase_price, $product->currency);
    }

    public function catalogPln(Product $product): ?float
    {
        return $this->toPlnOrNull($product->catalog_price_net, $product->currency);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function appendPricePln(array $row): array
    {
        $currency = isset($row['currency']) ? (string) $row['currency'] : 'PLN';
        $row['price_pln'] = $this->toPln(
            (float) ($row['catalog_price_net'] ?? 0),
            $currency,
        );
        $row['purchase_price_pln'] = $this->toPlnOrNull($row['purchase_price'] ?? null, $currency);

        return $row;
    }

    public function priceOrderSql(string $amountColumn, string $currencyColumn): string
    {
        $cases = [];
        foreach ($this->rates() as $code => $rate) {
            if ($code === 'PLN' || preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                continue;
            }
            $cases[] = "WHEN '".$code."' THEN ".sprintf('%.6F', $rate);
        }
        $multiplier = $cases === []
            ? '1'
            : 'CASE UPPER(COALESCE('.$currencyColumn.", 'PLN')) ".implode(' ', $cases).' ELSE 1 END';

        return 'COALESCE('.$amountColumn.', 0) * ('.$multiplier.')';
    }

    /**
     * @return array{rates: array<string, float>, as_of: string|null, source: string}
     */
    private function parseTable(mixed $json): array
    {
        $table = is_array($json) ? ($json[0] ?? null) : null;
        if (! is_array($table) || ! isset($table['rates']) || ! is_array($table['rates'])) {
            return ['rates' => [], 'as_of' => null, 'source' => 'nbp'];
        }

        $rates = ['PLN' => 1.0];
        foreach ($table['rates'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $mid = isset($row['mid']) ? (float) $row['mid'] : 0.0;
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || $mid <= 0) {
                continue;
            }
            $rates[$code] = $mid;
        }

        return [
            'rates' => $rates,
            'as_of' => isset($table['effectiveDate']) ? (string) $table['effectiveDate'] : null,
            'source' => 'nbp',
        ];
    }
}
