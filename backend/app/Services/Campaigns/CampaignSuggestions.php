<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\ErpItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * „Zaproponuj pozycje”: ranking zalegającego towaru do kampanii — bez modelu AI, każdy punkt z podanym powodem.
 * Kandydaci jak w Zapasach: stan handlowy > 0 i brak sprzedaży od MIN_MONTHS (nigdy niesprzedany — gdy partia
 * starsza), bez pozycji tej kampanii i innych aktywnych kampanii. Punkty (0–100): wartość zapasu 40, czas zalegania 25,
 * klienci z e-mailem, którzy kupowali towar w 24 mies. (erp_customer_items) 20, gotowość do maila (karta, zdjęcie,
 * opis) 15.
 */
class CampaignSuggestions
{
    public const MIN_MONTHS = 6;

    /** Tylu najcenniejszych kandydatów liczymy — ranking to ich kolejność po punktach. */
    private const CANDIDATES = 300;

    /** Wartość zapasu, od której punkty za wartość są pełne (skala logarytmiczna). */
    private const FULL_VALUE = 50000;

    private const FULL_MONTHS = 36;

    private const FULL_BUYERS = 50;

    public function __construct(private readonly ErpItemCards $cards) {}

    /**
     * @return array{rows: list<array<string, mixed>>, mine_available: bool}
     */
    public function suggest(Campaign $campaign, bool $mine, int $limit): array
    {
        /** @var User|null $author */
        $author = $campaign->user;
        $ident = $author !== null ? strtoupper(trim((string) $author->getAttribute('erp_operator_ident'))) : '';
        if ($mine && $ident === '') {
            return ['rows' => [], 'mine_available' => false];
        }

        $cutoff = CarbonImmutable::today()->subMonthsNoOverflow(self::MIN_MONTHS);
        $qty = InventoryQuery::quantitySql('trade');
        $value = InventoryQuery::valueSql('trade');
        $sale = InventoryQuery::lastSaleSql();
        $lot = InventoryQuery::oldestLotSql('trade');

        $query = InventoryQuery::inStock('trade')->where('erp_items.archived', false);
        InventoryQuery::unsoldSince($query, $cutoff, true, 'trade');
        $busy = $this->busyItemIds($campaign);
        if ($busy !== []) {
            $query->whereNotIn('erp_items.id', $busy);
        }
        if ($mine) {
            // tylko towar, który kupowali klienci tego handlowca (opiekun w XL)
            $query->whereExists(fn ($q) => $this->buyersQuery($q, $ident)->whereColumn('ci.erp_item_id', 'erp_items.id'));
        }
        $candidates = $query
            ->selectRaw("erp_items.id, erp_items.code, erp_items.name, erp_items.unit, {$qty} as qty, {$value} as stock_val, {$sale} as last_sale, {$lot} as lot")
            ->orderByRaw("case when {$value} is null then 1 else 0 end")
            ->orderByRaw("{$value} desc")
            ->limit(self::CANDIDATES)
            ->get();
        if ($candidates->isEmpty()) {
            return ['rows' => [], 'mine_available' => $ident !== ''];
        }
        $ids = $candidates->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $buyers = $this->buyersQuery(DB::query(), $mine ? $ident : null)
            ->whereIn('ci.erp_item_id', $ids)
            ->groupBy('ci.erp_item_id')
            ->selectRaw('ci.erp_item_id, count(distinct ci.erp_customer_id) as n')
            ->pluck('n', 'erp_item_id');
        $cardOf = $this->cards->forItems(ErpItem::query()->whereIn('id', $ids)->with(ErpItemCards::eagerLinks())->get());
        $cardIds = array_values(array_filter(array_map(static fn (array $c): ?int => $c['card']['id'] ?? null, $cardOf)));
        $described = $cardIds === [] ? [] : Product::query()->whereIn('id', $cardIds)
            ->whereNotNull('description')->where('description', '!=', '')->pluck('id')->map(static fn ($id): int => (int) $id)->flip()->all();

        $today = CarbonImmutable::today();
        $rows = [];
        foreach ($candidates as $c) {
            $id = (int) $c->id;
            $card = $cardOf[$id]['card'] ?? null;
            $hasImage = $card !== null && ($card['thumb_url'] ?? null) !== null;
            $hasDescription = $card !== null && isset($described[(int) $card['id']]);
            $stockValue = $c->stock_val !== null ? (float) $c->stock_val : null;
            $lastSale = $c->last_sale !== null ? CarbonImmutable::parse($c->last_sale) : null;
            $lotAt = $c->lot !== null ? CarbonImmutable::parse($c->lot) : null;
            $since = $lastSale ?? $lotAt;
            $months = $since !== null ? (int) $since->diffInMonths($today) : null;
            $n = (int) ($buyers[$id] ?? 0);

            $score = ($stockValue !== null && $stockValue > 1 ? min(1, log10($stockValue) / log10(self::FULL_VALUE)) : 0) * 40
                + min(1, ($months ?? self::FULL_MONTHS) / self::FULL_MONTHS) * 25
                + min(1, log(1 + $n) / log(1 + self::FULL_BUYERS)) * 20
                + ($card !== null ? 5 : 0) + ($hasImage ? 5 : 0) + ($hasDescription ? 5 : 0);

            $unit = $c->unit !== null && $c->unit !== '' ? (string) $c->unit : 'szt';
            $rows[] = [
                'erp_item_id' => $id,
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'unit' => $unit,
                'stock' => round((float) $c->qty, 3),
                'stock_value' => $stockValue !== null ? round($stockValue, 2) : null,
                'last_sale_at' => $lastSale?->toDateString(),
                'oldest_lot_at' => $lotAt?->toDateString(),
                'buyers' => $n,
                'card' => $card === null ? null : ['id' => (int) $card['id'], 'sku' => (string) $card['sku'], 'name' => (string) $card['name'], 'thumb_url' => $card['thumb_url'] ?? null],
                'has_description' => $hasDescription,
                'score' => (int) round($score),
                'reasons' => $this->reasons($lastSale, $lotAt, $months, $stockValue, (float) $c->qty, $unit, $n, $mine, $card !== null, $hasImage, $hasDescription),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['score'], $b['stock_value'] ?? 0] <=> [$a['score'], $a['stock_value'] ?? 0]);

        return ['rows' => array_slice($rows, 0, $limit), 'mine_available' => $ident !== ''];
    }

    /**
     * Pozycje tej kampanii i innych aktywnych (projekt, zaplanowana, w wysyłce, wysłana w oknie częstotliwości) —
     * ten sam towar nie idzie drugi raz do klientów.
     *
     * @return list<int>
     */
    private function busyItemIds(Campaign $campaign): array
    {
        $since = Carbon::now()->subDays((int) config('campaigns.frequency_cap_days', 14));

        return DB::table('campaign_items as ci')
            ->join('campaigns as c', 'c.id', '=', 'ci.campaign_id')
            ->whereNotNull('ci.erp_item_id')
            ->where(static function ($q) use ($campaign, $since): void {
                $q->where('c.id', $campaign->id)
                    ->orWhereIn('c.status', [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED, Campaign::STATUS_SENDING])
                    ->orWhere(static fn ($s) => $s->where('c.status', Campaign::STATUS_SENT)->where('c.sent_at', '>=', $since));
            })
            ->distinct()
            ->pluck('ci.erp_item_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Klienci z e-mailem (nie archiwalni), którzy kupowali towar w 24 mies.; $ident = tylko klienci tego opiekuna. */
    private function buyersQuery($query, ?string $ident)
    {
        $query->from('erp_customer_items as ci')
            ->join('erp_customers as ec', 'ec.id', '=', 'ci.erp_customer_id')
            ->whereNull('ec.removed_at')
            ->where('ec.archived', false)
            ->whereNotNull('ec.emails');
        if ($ident !== null) {
            $query->where('ec.main_operator', $ident);
        }

        return $query;
    }

    /** @return list<string> */
    private function reasons(?CarbonImmutable $lastSale, ?CarbonImmutable $lot, ?int $months, ?float $value, float $qty, string $unit, int $buyers, bool $mine, bool $card, bool $image, bool $description): array
    {
        $out = [];
        $out[] = $lastSale !== null
            ? 'nie sprzedaje się od '.$months.' mies. (ostatnio '.$lastSale->format('d.m.Y').')'
            : 'nigdy nie sprzedany'.($lot !== null ? ' (partia z '.$lot->format('m.Y').')' : '');
        $quantity = rtrim(rtrim(number_format($qty, 3, ',', "\u{00A0}"), '0'), ',').' '.$unit;
        $out[] = $value !== null
            ? 'zapas '.number_format($value, 0, ',', "\u{00A0}")."\u{00A0}zł ({$quantity})"
            : "zapas {$quantity}, wartość nieznana";
        $out[] = $buyers > 0
            ? 'kupowało '.$buyers.($mine ? ' Twoich klientów' : ' klientów').' z e-mailem (24 mies.)'
            : 'nikt z klientów z e-mailem nie kupował w 24 mies.';
        if (! $card) {
            $out[] = 'bez karty — w mailu bez zdjęcia i opisu';
        } elseif (! $image || ! $description) {
            $out[] = 'karta '.(! $image && ! $description ? 'bez zdjęcia i opisu' : (! $image ? 'bez zdjęcia' : 'bez opisu'));
        }

        return $out;
    }
}
