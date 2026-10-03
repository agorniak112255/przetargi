<?php

declare(strict_types=1);

namespace App\Services\Inquiries;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ErpItemLink;
use App\Models\InquiryOrderHint;
use App\Services\ClientInquiryService;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Podpowiedź „możliwe, że to zamówienie z tej oferty” (inquiries:order-hints, co noc 05:55 czasu polskiego).
 *
 * Dla zapytań z odpowiedzią z ostatnich N dni (domyślnie 60), bez wpisanego wyniku i z pewnym klientem z ERP XL
 * (InquiryClientLinker: wybór handlowca, ten sam adres e-mail, NIP z maila — klient z clients.xl_gid): pozycje FS, PA
 * i FSE tych kontrahentów z ERP XL (ErpXlGateway::customerDocumentLines, od najwcześniejszego dnia odpowiedzi).
 * Towary oferty = ClientInquiryService::offeredProductIds() (wyroby z listu i zatwierdzone zamienniki), porównywane
 * przez powiązania kart z towarami XL o statusie auto albo confirmed (bez towarów usuniętych z XL). Zapisujemy
 * dokumenty z co najmniej jednym trafionym towarem wystawione w oknie [dzień odpowiedzi, +60 dni]: numer, datę,
 * wartość dokumentu (suma jego pozycji sprzedaży), wartość trafionych pozycji, ile z ilu zaoferowanych towarów
 * i ile z nich ma powiązanie z XL.
 *
 * To wniosek, nie fakt (klient mógł kupić z innego powodu) — wyniku zapytania ta klasa nigdy nie wpisuje.
 */
final class OrderHintBuilder
{
    /** Dokument liczy się do podpowiedzi, gdy wystawiono go najpóźniej tyle dni po dniu odpowiedzi. */
    public const WINDOW_DAYS = 60;

    /** Kiedy ostatnio przeliczono podpowiedzi (ISO) — „stan na” przy zapytaniu bez podpowiedzi. */
    public const COMPUTED_AT_CACHE_KEY = 'inquiries.order_hints.computed_at';

    /** Statusy powiązania karty z towarem XL, którym ufamy (suggested i rejected — nie). */
    private const TRUSTED_LINKS = [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED];

    public function __construct(
        private readonly ErpXlGateway $gateway,
        private readonly ClientInquiryService $inquiries,
    ) {}

    /**
     * @return array{inquiries: int, without_client: int, customers: int, documents: int, hints: int, removed: int, errors: int}
     */
    public function run(int $days = self::WINDOW_DAYS): array
    {
        DB::disableQueryLog();
        $startedAt = CarbonImmutable::now()->startOfSecond();
        $since = PolishTime::today()->subDays(max(1, $days) - 1);
        $stats = ['inquiries' => 0, 'without_client' => 0, 'customers' => 0, 'documents' => 0, 'hints' => 0, 'removed' => 0, 'errors' => 0];

        // 1. zapytania: odpowiedź w oknie, bez wyniku; klient pewny i z ERP XL
        $xlByClient = Client::query()->whereNotNull('xl_gid')->pluck('xl_gid', 'id')->map(static fn ($gid): int => (int) $gid)->all();
        /** @var array<int, array{customer: int, day: CarbonImmutable, products: list<int>, gids: array<int, int>, linked: int}> $plans */
        $plans = [];
        $skipped = [];
        $query = ClientInquiry::query()
            ->whereNotNull('replied_at')
            ->whereNull('outcome')
            ->where('replied_at', '>=', $since->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'))
            ->select(['id', 'client_id', 'replied_at', 'analysis', 'answers']);
        foreach ($query->lazyById(50) as $inquiry) {
            /** @var ClientInquiry $inquiry */
            $customer = $inquiry->client_id !== null ? ($xlByClient[(int) $inquiry->client_id] ?? null) : null;
            if ($customer === null) {
                $skipped[] = (int) $inquiry->id;
                $stats['without_client']++;

                continue;
            }
            try {
                $products = $this->inquiries->offeredProductIds($inquiry);
            } catch (Throwable $e) {
                report($e);
                $stats['errors']++;

                continue;
            }
            $plans[(int) $inquiry->id] = [
                'customer' => $customer,
                'day' => CarbonImmutable::instance($inquiry->replied_at)->setTimezone(PolishTime::TIMEZONE)->startOfDay(),
                'products' => $products,
                'gids' => [],
                'linked' => 0,
            ];
            $stats['inquiries']++;
        }

        // zapytania bez pewnego klienta z XL: stare podpowiedzi (np. sprzed zdjęcia powiązania) już nie obowiązują
        foreach (array_chunk($skipped, 500) as $chunk) {
            $stats['removed'] += InquiryOrderHint::query()->whereIn('client_inquiry_id', $chunk)->delete();
        }
        if ($plans === []) {
            Cache::forever(self::COMPUTED_AT_CACHE_KEY, $startedAt->toIso8601String());

            return $stats;
        }

        // 2. towary XL powiązane z wyrobami ofert: xl_gid towaru → id karty (jedna karta może mieć kilka towarów XL)
        $allProducts = array_values(array_unique(array_merge(...array_values(array_map(static fn (array $p): array => $p['products'], $plans)))));
        $productGids = $this->linkedItemGids($allProducts);
        foreach ($plans as &$plan) {
            foreach ($plan['products'] as $productId) {
                if (($productGids[$productId] ?? []) !== []) {
                    $plan['linked']++;
                    foreach ($productGids[$productId] as $gid) {
                        $plan['gids'][$gid] = $productId;
                    }
                }
            }
        }
        unset($plan);

        // 3. pozycje dokumentów XL kontrahentów — tylko towary, które ktoś im zaoferował; wartość dokumentu z wszystkich
        $wanted = [];
        foreach ($plans as $plan) {
            foreach (array_keys($plan['gids']) as $gid) {
                $wanted[$plan['customer']][$gid] = true;
            }
        }
        /** @var array<string, array{type: int, id: int, number: string, date: string, customer: int, net: float, items: array<int, float>}> $documents */
        $documents = [];
        if ($wanted !== []) {
            $from = min(array_map(static fn (array $p): CarbonImmutable => $p['day'], $plans));
            $stats['customers'] = count($wanted);
            foreach ($this->gateway->customerDocumentLines(array_keys($wanted), ClarionDate::fromDate($from)) as $row) {
                $date = ClarionDate::toDate($row['date']);
                if ($date === null) {
                    continue;
                }
                $key = $row['document_type'].':'.$row['document_id'];
                $documents[$key] ??= [
                    'type' => (int) $row['document_type'],
                    'id' => (int) $row['document_id'],
                    'number' => mb_substr((string) $row['document_number'], 0, 40),
                    'date' => $date->toDateString(),
                    'customer' => (int) $row['customer_gid'],
                    'net' => 0.0,
                    'items' => [],
                ];
                $documents[$key]['net'] += (float) $row['net_value'];
                if (isset($wanted[(int) $row['customer_gid']][(int) $row['item_gid']])) {
                    // ten sam towar w kilku pozycjach dokumentu — sumujemy
                    $documents[$key]['items'][(int) $row['item_gid']] = ($documents[$key]['items'][(int) $row['item_gid']] ?? 0.0) + (float) $row['net_value'];
                }
            }
            // dokumenty bez żadnego zaoferowanego towaru nie są potrzebne
            $documents = array_filter($documents, static fn (array $d): bool => $d['items'] !== []);
            $stats['documents'] = count($documents);
        }
        $byCustomer = [];
        foreach ($documents as $key => $document) {
            $byCustomer[$document['customer']][] = $key;
        }

        // 4. podpowiedzi zapytania: dokumenty klienta w oknie z co najmniej jednym towarem tej oferty
        foreach ($plans as $inquiryId => $plan) {
            try {
                $rows = [];
                $last = $plan['day']->addDays(self::WINDOW_DAYS)->toDateString();
                foreach ($byCustomer[$plan['customer']] ?? [] as $key) {
                    $document = $documents[$key];
                    if ($document['date'] < $plan['day']->toDateString() || $document['date'] > $last) {
                        continue;
                    }
                    $matchedProducts = [];
                    $matchedNet = 0.0;
                    foreach ($document['items'] as $gid => $net) {
                        if (isset($plan['gids'][$gid])) {
                            $matchedProducts[$plan['gids'][$gid]] = true;
                            $matchedNet += $net;
                        }
                    }
                    if ($matchedProducts === []) {
                        continue;
                    }
                    $rows[] = [
                        'client_inquiry_id' => $inquiryId,
                        'document_type' => $document['type'],
                        'document_id' => $document['id'],
                        'document_number' => $document['number'],
                        'issued_at' => $document['date'],
                        'document_net' => round($document['net'], 2),
                        'matched_net' => round($matchedNet, 2),
                        'offered_items' => min(65535, count($plan['products'])),
                        'linked_items' => min(65535, $plan['linked']),
                        'matched_items' => min(65535, count($matchedProducts)),
                        'computed_at' => $startedAt,
                        'created_at' => $startedAt,
                        'updated_at' => $startedAt,
                    ];
                }
                $stats['hints'] += count($rows);
                $stats['removed'] += $this->replaceHints($inquiryId, $rows);
            } catch (Throwable $e) {
                report($e);
                $stats['errors']++;
            }
        }

        Cache::forever(self::COMPUTED_AT_CACHE_KEY, $startedAt->toIso8601String());

        return $stats;
    }

    /**
     * Podpowiedzi zapytania do widoku (GET /inquiries/{id}: order_hints) z powodem, gdy ich nie ma.
     *
     * @return array{status: 'ok'|'no_client'|'no_xl'|'not_replied', rule: string, computed_at: string|null, hints: list<array<string, mixed>>}
     */
    public static function present(ClientInquiry $inquiry): array
    {
        $computed = Cache::get(self::COMPUTED_AT_CACHE_KEY);
        $computedAt = is_string($computed) && $computed !== '' ? $computed : null;
        $empty = static fn (string $status, string $rule): array => ['status' => $status, 'rule' => $rule, 'computed_at' => $computedAt, 'hints' => []];

        if ($inquiry->replied_at === null) {
            return $empty('not_replied', 'Podpowiedź z ERP XL pojawia się dopiero po wysłaniu odpowiedzi do klienta.');
        }
        $client = $inquiry->client_id === null ? null
            : ($inquiry->relationLoaded('client') ? $inquiry->client : Client::query()->select(['id', 'name', 'xl_gid'])->find($inquiry->client_id));
        if (! $client instanceof Client) {
            return $empty('no_client', 'Podpowiedzi z ERP XL nie ma, bo zapytanie nie jest pewnie powiązane z klientem z ERP XL. '
                .'Powiązanie jest pewne, gdy adres nadawcy jest taki sam jak w ERP XL, gdy w mailu jest NIP klienta albo gdy '
                .'klienta wybierze handlowiec. Po powiązaniu podpowiedź pojawi się po nocnym sprawdzeniu.');
        }
        if ($client->xl_gid === null) {
            return $empty('no_client', 'Podpowiedzi z ERP XL nie ma, bo klient „'.$client->name.'” nie pochodzi z ERP XL — '
                .'nie mamy jego faktur ani paragonów.');
        }
        if (! (bool) config('erpxl.enabled')) {
            return $empty('no_xl', 'Podpowiedzi z ERP XL nie ma, bo połączenie z ERP XL jest wyłączone.');
        }

        $hints = $inquiry->hints()->orderBy('issued_at')->orderBy('id')->get();
        $latest = $hints->max('computed_at');

        return [
            'status' => 'ok',
            'rule' => 'To wniosek, a nie fakt: w ciągu '.self::WINDOW_DAYS.' dni od dnia odpowiedzi ERP XL wystawił temu '
                .'klientowi fakturę albo paragon z co najmniej jednym towarem z tej oferty. Klient mógł kupić z innego powodu. '
                .'Towary porównujemy przez powiązania kart z towarami w ERP XL (automatyczne i potwierdzone). Sprawdzamy co noc; '
                .'wyniku zapytania nikt za Ciebie nie wpisuje.',
            'computed_at' => $latest !== null ? CarbonImmutable::instance($latest)->toIso8601String() : $computedAt,
            'hints' => $hints->map(static fn (InquiryOrderHint $h): array => [
                'id' => (int) $h->id,
                'document_number' => (string) $h->document_number,
                'issued_at' => $h->issued_at?->toDateString(),
                'document_net' => (string) $h->document_net,
                'matched_net' => (string) $h->matched_net,
                'offered_items' => (int) $h->offered_items,
                'linked_items' => (int) $h->linked_items,
                'matched_items' => (int) $h->matched_items,
            ])->values()->all(),
        ];
    }

    /**
     * Towary XL (xl_gid) powiązane z kartami: tylko powiązania auto i confirmed, bez towarów usuniętych z XL.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<int>> id karty → numery towarów XL
     */
    private function linkedItemGids(array $productIds): array
    {
        $out = [];
        foreach (array_chunk($productIds, 500) as $chunk) {
            $rows = DB::table('erp_item_links')
                ->join('erp_items', 'erp_items.id', '=', 'erp_item_links.erp_item_id')
                ->whereIn('erp_item_links.product_id', $chunk)
                ->whereIn('erp_item_links.status', self::TRUSTED_LINKS)
                ->whereNull('erp_items.removed_at')
                ->get(['erp_item_links.product_id', 'erp_items.xl_gid']);
            foreach ($rows as $row) {
                $out[(int) $row->product_id][] = (int) $row->xl_gid;
            }
        }

        return array_map(static fn (array $gids): array => array_values(array_unique($gids)), $out);
    }

    /**
     * Zapis podpowiedzi zapytania: nowe i zmienione dokumenty (upsert), znikają te, których już nie ma (dokument
     * anulowany, towar odpięty) — w jednej transakcji z blokadą wiersza zapytania. Wynik wpisany w międzyczasie
     * przez handlowca zostawia podpowiedzi bez zmian.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceHints(int $inquiryId, array $rows): int
    {
        return DB::transaction(static function () use ($inquiryId, $rows): int {
            $inquiry = ClientInquiry::query()->whereKey($inquiryId)->lockForUpdate()->first(['id', 'outcome']);
            if ($inquiry === null || $inquiry->outcome !== null) {
                return 0;
            }
            if ($rows !== []) {
                InquiryOrderHint::query()->upsert(
                    $rows,
                    ['client_inquiry_id', 'document_type', 'document_id'],
                    ['document_number', 'issued_at', 'document_net', 'matched_net', 'offered_items', 'linked_items', 'matched_items', 'computed_at', 'updated_at'],
                );
            }
            $keep = array_map(static fn (array $r): string => $r['document_type'].':'.$r['document_id'], $rows);
            $removed = 0;
            foreach (InquiryOrderHint::query()->where('client_inquiry_id', $inquiryId)->get(['id', 'document_type', 'document_id']) as $hint) {
                if (! in_array($hint->document_type.':'.$hint->document_id, $keep, true)) {
                    $hint->delete();
                    $removed++;
                }
            }

            return $removed;
        });
    }
}
