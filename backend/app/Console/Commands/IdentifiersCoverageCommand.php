<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Catalog\ProductIdentifierStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pokrycie kart identyfikatorami ze źródeł cen (product_identifiers) — tylko odczyt, niczego nie zapisuje.
 *
 * Po wdrożeniu tabela zapełnia się przy kolejnych synchronizacjach kont B2B i importach cenników; polecenie pokazuje,
 * które źródło już opisało swoje karty kodami i EAN-ami, gdzie tabelka sklepu ma EAN, a tabela identyfikatorów nie
 * (łącznik go nie przekazuje albo konto nie było jeszcze synchronizowane), oraz ile kart różnych źródeł ma wspólny
 * EAN albo wspólny kod w tej samej marce — to liczba kandydatów do późniejszego łączenia kart.
 */
final class IdentifiersCoverageCommand extends Command
{
    protected $signature = 'identifiers:coverage
                            {--json : Wynik jako JSON (do porównań między wdrożeniami)}';

    protected $description = 'Pokrycie kart kodami i EAN-ami ze źródeł cen (tylko odczyt)';

    /** Rodzaje kodów porównywane w obrębie marki (kod dostawcy liczy się, bo u producenta to kod producenta). */
    private const CODE_TYPES = [
        ProductIdentifier::TYPE_MANUFACTURER_CODE,
        ProductIdentifier::TYPE_SOURCE_CODE,
        ProductIdentifier::TYPE_ALT_CODE,
    ];

    public function handle(B2bConnectorRegistry $connectors): int
    {
        $stats = $this->identifierStats();
        $sources = [...$this->b2bRows($connectors, $stats), ...$this->fileRows($stats)];
        $overlaps = $this->overlaps();
        $totals = [
            'cards_with_identifiers' => (int) ProductIdentifier::query()->whereNull('removed_at')->distinct()->count('product_id'),
            'identifiers_active' => (int) ProductIdentifier::query()->whereNull('removed_at')->count(),
            'identifiers_removed' => (int) ProductIdentifier::query()->whereNotNull('removed_at')->count(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['sources' => $sources, 'overlaps' => $overlaps, 'totals' => $totals],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Źródło', 'Karty', 'Z identyf.', 'Z EAN', 'EAN zły', 'Kod prod.', 'Tabelka: EAN bez identyf.', 'Zniknięte', 'Ostatnio'],
            array_map(static fn (array $s): array => [
                $s['label'],
                $s['cards'],
                $s['cards_with_identifiers'].' ('.self::percent($s['cards_with_identifiers'], $s['cards']).')',
                $s['cards_with_ean'],
                $s['invalid_ean'],
                $s['cards_with_manufacturer_code'],
                $s['shop_ean_without_identifier'] ?? '—',
                $s['removed'],
                $s['last_seen_at'] ?? '—',
            ], $sources),
        );
        $this->line(sprintf(
            'Karty z identyfikatorami: %d · identyfikatorów aktywnych: %d · oznaczonych jako zniknięte: %d',
            $totals['cards_with_identifiers'],
            $totals['identifiers_active'],
            $totals['identifiers_removed'],
        ));
        $this->line(sprintf(
            'Wspólny EAN na kartach różnych źródeł: %d EAN-ów, %d kart · wspólny kod w tej samej marce: %d kodów, %d kart',
            $overlaps['shared_ean'],
            $overlaps['shared_ean_cards'],
            $overlaps['shared_code'],
            $overlaps['shared_code_cards'],
        ));
        $this->line('„EAN zły” = zapisany dosłownie, ale ze złą sumą kontrolną — nie posłuży do łączenia kart.');

        return self::SUCCESS;
    }

    /**
     * Liczniki na źródło (source_key) jednym zapytaniem.
     *
     * @return array<string, array{cards_with_identifiers: int, cards_with_ean: int, invalid_ean: int, cards_with_manufacturer_code: int, removed: int, last_seen_at: string|null}>
     */
    private function identifierStats(): array
    {
        $rows = ProductIdentifier::query()->toBase()
            ->selectRaw('source_key')
            ->selectRaw('COUNT(DISTINCT CASE WHEN removed_at IS NULL THEN product_id END) AS cards')
            ->selectRaw("COUNT(DISTINCT CASE WHEN removed_at IS NULL AND type = 'ean' AND normalized IS NOT NULL THEN product_id END) AS ean_cards")
            ->selectRaw("SUM(CASE WHEN removed_at IS NULL AND type = 'ean' AND normalized IS NULL THEN 1 ELSE 0 END) AS bad_ean")
            ->selectRaw("COUNT(DISTINCT CASE WHEN removed_at IS NULL AND type = 'manufacturer_code' THEN product_id END) AS mfr_cards")
            ->selectRaw('SUM(CASE WHEN removed_at IS NOT NULL THEN 1 ELSE 0 END) AS removed')
            ->selectRaw('MAX(last_seen_at) AS last_seen')
            ->groupBy('source_key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->source_key] = [
                'cards_with_identifiers' => (int) $row->cards,
                'cards_with_ean' => (int) $row->ean_cards,
                'invalid_ean' => (int) $row->bad_ean,
                'cards_with_manufacturer_code' => (int) $row->mfr_cards,
                'removed' => (int) $row->removed,
                'last_seen_at' => $row->last_seen !== null ? substr((string) $row->last_seen, 0, 16) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stats
     * @return list<array<string, mixed>>
     */
    private function b2bRows(B2bConnectorRegistry $connectors, array $stats): array
    {
        $cards = DB::table('b2b_product_links')
            ->selectRaw('b2b_account_id, COUNT(DISTINCT product_id) AS cards')
            ->groupBy('b2b_account_id')
            ->pluck('cards', 'b2b_account_id');

        $out = [];
        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            $key = ProductSourcePrice::b2bKey((int) $account->id);
            // tabelka sklepu wymienia EAN, a z tego konta nie ma ani jednego identyfikatora EAN tej karty
            $shopEan = DB::table('product_shop_cards as c')
                ->where('c.b2b_account_id', $account->id)
                ->where(static function ($q): void {
                    $q->where('c.fields', 'like', '%EAN%')->orWhere('c.fields', 'like', '%Kod kreskowy%');
                })
                ->whereNotExists(static function ($q) use ($key): void {
                    $q->from('product_identifiers as i')
                        ->whereColumn('i.product_id', 'c.product_id')
                        ->where('i.source_key', $key)
                        ->where('i.type', ProductIdentifier::TYPE_EAN);
                })
                ->count();
            $label = $connectors->label($connectors->keyForAccount($account)) ?? 'konto';
            $out[] = [
                'source_key' => $key,
                'label' => $label.' (konto #'.$account->id.')',
                'cards' => (int) ($cards[$account->id] ?? 0),
                ...($stats[$key] ?? self::emptyStats()),
                'shop_ean_without_identifier' => $shopEan,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stats
     * @return list<array<string, mixed>>
     */
    private function fileRows(array $stats): array
    {
        $cards = ProductSourcePrice::query()->toBase()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereNotNull('price_list_id')
            ->selectRaw('price_list_id, COUNT(DISTINCT product_id) AS cards')
            ->groupBy('price_list_id')
            ->pluck('cards', 'price_list_id');

        $out = [];
        foreach (PriceList::query()->orderBy('manufacturer')->get() as $list) {
            $key = ProductIdentifierStore::fileKey((int) $list->id);
            $out[] = [
                'source_key' => $key,
                'label' => 'Cennik '.$list->manufacturer.' (wpis #'.$list->id.')',
                'cards' => (int) ($cards[$list->id] ?? 0),
                ...($stats[$key] ?? self::emptyStats()),
                'shop_ean_without_identifier' => null,
            ];
        }

        return $out;
    }

    /**
     * Kandydaci do łączenia kart: EAN na kartach z różnych źródeł i kod w tej samej marce na kartach z różnych źródeł.
     *
     * @return array{shared_ean: int, shared_ean_cards: int, shared_code: int, shared_code_cards: int}
     */
    private function overlaps(): array
    {
        $ean = ProductIdentifier::query()->toBase()
            ->where('type', ProductIdentifier::TYPE_EAN)
            ->whereNotNull('normalized')
            ->whereNull('removed_at')
            ->selectRaw('normalized, COUNT(DISTINCT product_id) AS cards')
            ->groupBy('normalized')
            ->havingRaw('COUNT(DISTINCT product_id) > 1 AND COUNT(DISTINCT source_key) > 1')
            ->get();

        $code = ProductIdentifier::query()->toBase()
            ->whereIn('type', self::CODE_TYPES)
            ->whereNotNull('normalized')
            ->whereNotNull('brand_key')
            ->whereNull('removed_at')
            ->selectRaw('brand_key, normalized, COUNT(DISTINCT product_id) AS cards')
            ->groupBy('brand_key', 'normalized')
            ->havingRaw('COUNT(DISTINCT product_id) > 1 AND COUNT(DISTINCT source_key) > 1')
            ->get();

        return [
            'shared_ean' => $ean->count(),
            'shared_ean_cards' => (int) $ean->sum('cards'),
            'shared_code' => $code->count(),
            'shared_code_cards' => (int) $code->sum('cards'),
        ];
    }

    /**
     * @return array{cards_with_identifiers: int, cards_with_ean: int, invalid_ean: int, cards_with_manufacturer_code: int, removed: int, last_seen_at: null}
     */
    private static function emptyStats(): array
    {
        return [
            'cards_with_identifiers' => 0,
            'cards_with_ean' => 0,
            'invalid_ean' => 0,
            'cards_with_manufacturer_code' => 0,
            'removed' => 0,
            'last_seen_at' => null,
        ];
    }

    private static function percent(int $part, int $whole): string
    {
        return $whole > 0 ? round($part * 100 / $whole).'%' : '—';
    }
}
