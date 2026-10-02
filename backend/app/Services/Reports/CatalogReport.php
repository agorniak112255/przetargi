<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Raport R1 „Baza wiedzy”: pokrycie kart opisem, zdjęciem, normami i dokumentami — całość, wg producenta,
 * karty sprzedawane w XL (ostatnie 12 mies.) i rozkład dat dodania / ostatniego wzbogacenia w tygodniach.
 *
 * Liczy tylko to, co jest w bazie: „z opisem” jak DashboardController / ProductCatalogHealthService
 * (TRIM(description) <> ''), normy tekstowe osobno od norm potwierdzonych u producenta (manufacturer_norms).
 * Całość jest wspólna dla wszystkich z products.view (sprawdza kontroler), więc cache bez filtra użytkownika.
 */
final class CatalogReport
{
    /** Opis krótszy niż tyle znaków (po obcięciu spacji) = „krótki”. */
    public const SHORT_DESCRIPTION_CHARS = 120;

    /** Karta „sprzedawana” = powiązany towar XL ze sprzedażą w tylu ostatnich miesiącach. */
    public const SOLD_WINDOW_MONTHS = 12;

    /** Tyle tygodni wstecz (z bieżącym) w serii weekly. */
    public const WEEKS = 26;

    /** Tyle sprzedawanych kart z lukami w gaps_sold. */
    public const GAPS_LIMIT = 15;

    public const CACHE_KEY = 'reports:catalog:v1';

    public const CACHE_SECONDS = 600;

    public const TIMEZONE = 'Europe/Warsaw';

    public const NO_MANUFACTURER = '(brak producenta)';

    private const ENRICHMENT_STATUSES = [
        Product::ENRICHMENT_NONE,
        Product::ENRICHMENT_QUEUED,
        Product::ENRICHMENT_RUNNING,
        Product::ENRICHMENT_DONE,
        Product::ENRICHMENT_FAILED,
        Product::ENRICHMENT_MANUAL,
    ];

    /** Kolejność braków w gaps_sold.missing. */
    private const GAP_FLAGS = [
        'description' => 'has_description',
        'image' => 'has_image',
        'manufacturer_norms' => 'has_manufacturer_norms',
        'documents' => 'has_documents',
    ];

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(User $user, array $params = []): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->compute());
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $now = CarbonImmutable::now();
        $manufacturers = $this->manufacturers();

        $totals = [
            'products' => 0,
            'with_description' => 0,
            'short_description' => 0,
            'with_image' => 0,
            'with_norms_text' => 0,
            'with_manufacturer_norms' => 0,
            'with_documents' => 0,
            'vector_indexed' => 0,
        ];
        foreach ($manufacturers as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $totals['enrichment'] = $this->enrichment();

        $sold = $this->sold();

        return [
            'generated_at' => $now->toIso8601String(),
            'short_description_chars' => self::SHORT_DESCRIPTION_CHARS,
            'totals' => $totals,
            'documents_by_kind' => $this->documentsByKind(),
            'sold' => $sold['summary'],
            'weekly' => $this->weekly($now),
            // krótkie opisy i indeks wektorowy są tylko w sumach — w wierszu producenta ich kontrakt nie przewiduje
            'manufacturers' => array_map(static function (array $row): array {
                unset($row['short_description'], $row['vector_indexed']);

                return $row;
            }, $manufacturers),
            'gaps_sold' => $sold['gaps'],
        ];
    }

    /**
     * Jedno przejście po products zgrupowane po producencie (puste/NULL → „(brak producenta)”); sumy całości to
     * suma tych wierszy. GROUP BY po aliasie: MySQL z ONLY_FULL_GROUP_BY nie uznaje powtórzonego wyrażenia
     * z literałem tekstowym za to samo (błąd 1055 sprawdzony lokalnie), alias przyjmuje MySQL i SQLite.
     *
     * @return list<array{manufacturer: string, products: int, with_description: int, short_description: int, with_image: int, with_norms_text: int, with_manufacturer_norms: int, with_documents: int, vector_indexed: int, enrichment_failed: int}>
     */
    private function manufacturers(): array
    {
        $label = "COALESCE(NULLIF(TRIM(products.manufacturer), ''), '".self::NO_MANUFACTURER."')";

        $rows = DB::table('products')
            ->selectRaw($label.' AS manufacturer_label')
            ->selectRaw('COUNT(*) AS product_count')
            ->selectRaw(self::sum(self::hasDescriptionSql()).' AS with_description')
            ->selectRaw(self::sum(self::shortDescriptionSql()).' AS short_description')
            ->selectRaw(self::sum(self::hasImageSql()).' AS with_image')
            ->selectRaw(self::sum("products.norms IS NOT NULL AND TRIM(products.norms) <> ''").' AS with_norms_text')
            ->selectRaw(self::sum('products.manufacturer_norms IS NOT NULL').' AS with_manufacturer_norms')
            ->selectRaw(self::sum(self::hasDocumentsSql()).' AS with_documents')
            ->selectRaw(self::sum('products.embedding_synced_at IS NOT NULL').' AS vector_indexed')
            ->selectRaw(self::sum("products.enrichment_status = '".Product::ENRICHMENT_FAILED."'").' AS enrichment_failed')
            ->groupBy('manufacturer_label')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'manufacturer' => (string) $row->manufacturer_label,
                'products' => (int) $row->product_count,
                'with_description' => (int) $row->with_description,
                'short_description' => (int) $row->short_description,
                'with_image' => (int) $row->with_image,
                'with_norms_text' => (int) $row->with_norms_text,
                'with_manufacturer_norms' => (int) $row->with_manufacturer_norms,
                'with_documents' => (int) $row->with_documents,
                'vector_indexed' => (int) $row->vector_indexed,
                'enrichment_failed' => (int) $row->enrichment_failed,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['products'], mb_strtolower($a['manufacturer'])]
            <=> [$a['products'], mb_strtolower($b['manufacturer'])]);

        return $out;
    }

    /**
     * Statusy wzbogacania; NULL liczony jako none, status spoza listy pominięty (nie zgadujemy, czym jest).
     *
     * @return array<string, int>
     */
    private function enrichment(): array
    {
        $out = array_fill_keys(self::ENRICHMENT_STATUSES, 0);
        $rows = DB::table('products')
            ->select('enrichment_status')
            ->selectRaw('COUNT(*) AS cnt')
            ->groupBy('enrichment_status')
            ->get();
        foreach ($rows as $row) {
            $status = $row->enrichment_status ?? Product::ENRICHMENT_NONE;
            if (array_key_exists($status, $out)) {
                $out[$status] += (int) $row->cnt;
            }
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, products: int}>
     */
    private function documentsByKind(): array
    {
        $out = DB::table('product_documents')
            ->select('kind')
            ->selectRaw('COUNT(DISTINCT product_id) AS product_count')
            ->groupBy('kind')
            ->get()
            ->map(static fn (object $row): array => ['kind' => (string) $row->kind, 'products' => (int) $row->product_count])
            ->all();
        usort($out, static fn (array $a, array $b): int => [$b['products'], $a['kind']] <=> [$a['products'], $b['kind']]);

        return $out;
    }

    /**
     * Karty powiązane (auto/confirmed) z nieusuniętym towarem XL sprzedanym w ostatnich 12 mies. Jedna karta może
     * mieć kilka towarów — liczona raz, data sprzedaży = najpóźniejsza. null, gdy nie ma żadnego powiązania kart
     * z XL (integracja nie działa — zera byłyby mylące).
     *
     * @return array{summary: array<string, int>|null, gaps: list<array<string, mixed>>}
     */
    private function sold(): array
    {
        $statuses = [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED];
        $linked = DB::table('erp_item_links')
            ->whereIn('status', $statuses)
            ->whereNotNull('product_id')
            ->exists();
        if (! $linked) {
            return ['summary' => null, 'gaps' => []];
        }

        // last_sale_at to DATE z XL (bez strefy) — granica jako data dzisiejsza w czasie polskim minus 12 mies.
        $since = CarbonImmutable::now(self::TIMEZONE)->subMonthsNoOverflow(self::SOLD_WINDOW_MONTHS)->toDateString();
        $soldIds = DB::table('erp_item_links')
            ->join('erp_items', 'erp_items.id', '=', 'erp_item_links.erp_item_id')
            ->whereIn('erp_item_links.status', $statuses)
            ->whereNotNull('erp_item_links.product_id')
            ->whereNull('erp_items.removed_at')
            ->where('erp_items.last_sale_at', '>=', $since)
            ->groupBy('erp_item_links.product_id')
            ->select('erp_item_links.product_id')
            ->selectRaw('MAX(erp_items.last_sale_at) AS last_sale_at');

        $flags = DB::table('products')
            ->joinSub($soldIds, 'sold', 'sold.product_id', '=', 'products.id')
            ->select(['products.id', 'products.sku', 'products.name', 'products.manufacturer', 'sold.last_sale_at'])
            ->selectRaw(self::flag(self::hasDescriptionSql()).' AS has_description')
            ->selectRaw(self::flag(self::hasImageSql()).' AS has_image')
            ->selectRaw(self::flag('products.manufacturer_norms IS NOT NULL').' AS has_manufacturer_norms')
            ->selectRaw(self::flag(self::hasDocumentsSql()).' AS has_documents');

        $summary = DB::query()
            ->fromSub($flags, 'f')
            ->selectRaw('COUNT(*) AS product_count')
            ->selectRaw('SUM(f.has_description) AS with_description')
            ->selectRaw('SUM(f.has_image) AS with_image')
            ->selectRaw('SUM(f.has_manufacturer_norms) AS with_manufacturer_norms')
            ->selectRaw('SUM(f.has_documents) AS with_documents')
            ->first();

        $present = '(f.has_description + f.has_image + f.has_manufacturer_norms + f.has_documents)';
        $gapRows = DB::query()
            ->fromSub($flags, 'f')
            ->select('f.*')
            ->whereRaw($present.' < '.count(self::GAP_FLAGS))
            ->orderByRaw($present.' ASC')
            ->orderByDesc('f.last_sale_at')
            ->orderBy('f.id')
            ->limit(self::GAPS_LIMIT)
            ->get();

        $gaps = [];
        foreach ($gapRows as $row) {
            $missing = [];
            foreach (self::GAP_FLAGS as $name => $column) {
                if ((int) $row->{$column} === 0) {
                    $missing[] = $name;
                }
            }
            $manufacturer = trim((string) ($row->manufacturer ?? ''));
            $gaps[] = [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                // DATE dosłownie — bez przeliczania strefy (SQLite trzyma „Y-m-d 00:00:00”)
                'last_sale_at' => substr((string) $row->last_sale_at, 0, 10),
                'missing' => $missing,
            ];
        }

        return [
            'summary' => [
                'window_months' => self::SOLD_WINDOW_MONTHS,
                'products' => (int) ($summary->product_count ?? 0),
                'with_description' => (int) ($summary->with_description ?? 0),
                'with_image' => (int) ($summary->with_image ?? 0),
                'with_manufacturer_norms' => (int) ($summary->with_manufacturer_norms ?? 0),
                'with_documents' => (int) ($summary->with_documents ?? 0),
            ],
            'gaps' => $gaps,
        ];
    }

    /**
     * Rozkład dat dodania kart i OSTATNIEGO wzbogacenia (status done) w tygodniach od poniedziałku, czas polski.
     * Kubełki w PHP (bez funkcji dat w SQL); pobierane same daty z ostatnich 26 tygodni.
     *
     * @return list<array{week_start: string, added: int, enriched: int}>
     */
    private function weekly(CarbonImmutable $now): array
    {
        $firstWeek = $now->setTimezone(self::TIMEZONE)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->subWeeks(self::WEEKS - 1);
        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $weeks[$firstWeek->addWeeks($i)->toDateString()] = ['added' => 0, 'enriched' => 0];
        }
        $fromUtc = $firstWeek->utc()->format('Y-m-d H:i:s');

        // przesunięcie strefy polskiej to pełne godziny — tydzień zależy tylko od „Y-m-d H” w UTC, więc Carbon
        // liczy go raz na godzinę, nie raz na kartę (cały katalog może mieć datę dodania z ostatnich 26 tygodni)
        $weekOfHour = [];
        $count = static function (Builder $query, string $column, string $key) use (&$weeks, &$weekOfHour): void {
            foreach ($query->pluck($column) as $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $hour = substr((string) $value, 0, 13);
                $week = $weekOfHour[$hour] ??= CarbonImmutable::parse($hour.':00:00', 'UTC')
                    ->setTimezone(self::TIMEZONE)
                    ->startOfWeek(CarbonInterface::MONDAY)
                    ->toDateString();
                if (isset($weeks[$week])) {
                    $weeks[$week][$key]++;
                }
            }
        };
        $count(DB::table('products')->where('created_at', '>=', $fromUtc), 'created_at', 'added');
        $count(
            DB::table('products')
                ->where('enrichment_status', Product::ENRICHMENT_DONE)
                ->where('enriched_at', '>=', $fromUtc),
            'enriched_at',
            'enriched',
        );

        $out = [];
        foreach ($weeks as $week => $counts) {
            $out[] = ['week_start' => $week, 'added' => $counts['added'], 'enriched' => $counts['enriched']];
        }

        return $out;
    }

    private static function hasDescriptionSql(): string
    {
        return "TRIM(COALESCE(products.description, '')) <> ''";
    }

    /** Długość w znakach: MySQL LENGTH liczy bajty (polskie litery = 2), więc tam CHAR_LENGTH. */
    private static function shortDescriptionSql(): string
    {
        $length = DB::connection()->getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';

        return self::hasDescriptionSql().' AND '.$length.'(TRIM(products.description)) < '.self::SHORT_DESCRIPTION_CHARS;
    }

    private static function hasImageSql(): string
    {
        return 'EXISTS (SELECT 1 FROM product_images WHERE product_images.product_id = products.id)';
    }

    private static function hasDocumentsSql(): string
    {
        return 'EXISTS (SELECT 1 FROM product_documents WHERE product_documents.product_id = products.id)';
    }

    private static function sum(string $condition): string
    {
        return 'SUM('.self::flag($condition).')';
    }

    private static function flag(string $condition): string
    {
        return 'CASE WHEN '.$condition.' THEN 1 ELSE 0 END';
    }
}
