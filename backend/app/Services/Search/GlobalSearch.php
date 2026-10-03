<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Erp\ErpCodeSearch;
use App\Services\TenderWorkflowService;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Jedno pole wyszukiwania dla całej aplikacji (GET /search?q=, Ctrl+K). Grupy: Produkty, Przetargi, Zapytania,
 * Klienci — każda tylko z własnym uprawnieniem, każda z bazy najwyżej FETCH wierszy, w wyniku SHOWN i has_more.
 *
 * Czego tu celowo nie ma: żadnych cen ani wartości (zakupu, katalogowych, ofert) — wynik ogląda każdy z dostępem
 * do grupy; stan magazynu z ERP XL tylko z inventory.view (kopia z nocnej synchronizacji, XL nie jest pytany na żywo);
 * treść cudzych zapytań — cudze zapytania wchodzą tylko z inquiries.view_others, a treść maila (source_body) jest
 * przeszukiwana tylko w zapytaniach z ostatnich BODY_DAYS dni. Wszystkie warunki to LIKE „%…%” z limitem wierszy.
 */
final class GlobalSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /** Wyników na grupę w odpowiedzi. */
    public const SHOWN = 5;

    /** Pozycje przetargów (tender_items.requirement) dopiero od tej długości frazy — krótsza trafia w pół katalogu. */
    public const ITEM_SEARCH_MIN_LENGTH = 3;

    /** Treść maila zapytania przeszukiwana tylko w zapytaniach z tylu ostatnich dni. */
    public const BODY_DAYS = 90;

    /** Z bazy o jeden więcej niż w odpowiedzi — po nim wiadomo, czy jest „więcej”. */
    private const FETCH = self::SHOWN + 1;

    public function __construct(
        private readonly ProductListTextSearch $productText,
        private readonly ErpCodeSearch $erpCodes,
    ) {}

    /**
     * @return array{query: string, groups: list<array{key: string, label: string, items: list<array<string, mixed>>, has_more: bool, more_url: string|null}>}
     */
    public function search(User $user, string $q): array
    {
        $groups = [];
        if ($user->can('products.view')) {
            $groups[] = $this->group('products', 'Produkty', $this->products($user, $q), '/products?q='.rawurlencode($q));
        }
        if ($user->canAny(['tenders.view_own', 'tenders.view_all'])) {
            $groups[] = $this->group('tenders', 'Przetargi', $this->tenders($user, $q), null);
        }
        if ($user->can('inquiries.use')) {
            $groups[] = $this->group('inquiries', 'Zapytania', $this->inquiries($user, $q), '/inquiries?q='.rawurlencode($q));
        }
        if ($user->can('clients.view')) {
            $groups[] = $this->group('clients', 'Klienci', $this->clients($q), null);
        }

        return ['query' => $q, 'groups' => $groups];
    }

    /**
     * @param  list<array<string, mixed>>  $hits  najwyżej FETCH
     * @return array{key: string, label: string, items: list<array<string, mixed>>, has_more: bool, more_url: string|null}
     */
    private function group(string $key, string $label, array $hits, ?string $moreUrl): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'items' => array_slice($hits, 0, self::SHOWN),
            'has_more' => count($hits) > self::SHOWN,
            'more_url' => $moreUrl,
        ];
    }

    /**
     * Te same karty i ta sama kolejność co lista produktów (/products?q=, sortowanie po nazwie): SKU, nazwa, producent,
     * numer modelu, marka i kod towaru ERP XL (pewne powiązania).
     *
     * @return list<array<string, mixed>>
     */
    private function products(User $user, string $q): array
    {
        $erpCodes = $this->erpCodes->productCodes($q);
        $query = Product::query()->select(['id', 'sku', 'name', 'manufacturer']);
        if ($erpCodes !== []) {
            $query->where(fn (Builder $outer) => $outer
                ->where(fn (Builder $text) => $this->productText->applyTextSearch($text, $q))
                ->orWhereIn('id', array_keys($erpCodes)));
        } else {
            $this->productText->applyTextSearch($query, $q);
        }
        $this->productText->orderByMatch($query, $q, array_keys($erpCodes));
        $products = $query->orderBy('name')->orderBy('id')->limit(self::FETCH)->get();

        $stocks = $user->can('inventory.view') ? $this->stocks($products->pluck('id')->map(static fn ($id): int => (int) $id)->all()) : null;

        return $products->map(static function (Product $p) use ($erpCodes, $stocks): array {
            $id = (int) $p->id;
            $codes = $erpCodes[$id] ?? [];
            $hit = [
                'id' => $id,
                'title' => (string) $p->name,
                'subtitle' => self::join([
                    trim((string) $p->sku) !== '' ? 'kod produktu '.$p->sku : null,
                    $p->manufacturer,
                ]),
                'detail' => $codes !== [] ? 'kod w ERP XL: '.implode(', ', $codes) : null,
                'badge' => null,
                'url' => '/products/'.$id,
            ];
            if ($stocks !== null) {
                $hit['stock'] = $stocks[$id] ?? null;
            }

            return $hit;
        })->values()->all();
    }

    /**
     * Stan handlowy z ERP XL (kopia nocna) — suma towarów XL pewnie powiązanych z kartą (auto i potwierdzone,
     * bez usuniętych z XL); jak na karcie produktu (ErpCardStock) suma tylko przy jednej jednostce, inaczej null.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{quantity: string, unit: string|null}|null>
     */
    private function stocks(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $rows = DB::table('erp_item_links')
            ->join('erp_items', 'erp_items.id', '=', 'erp_item_links.erp_item_id')
            ->whereIn('erp_item_links.product_id', $productIds)
            ->whereIn('erp_item_links.status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED])
            ->whereNull('erp_items.removed_at')
            ->get(['erp_item_links.product_id', 'erp_items.unit', 'erp_items.stock_trade']);

        $byProduct = [];
        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $unit = trim((string) $row->unit);
            $byProduct[$productId] ??= ['sum' => 0.0, 'units' => [], 'unit' => $unit];
            $byProduct[$productId]['sum'] += (float) $row->stock_trade;
            $byProduct[$productId]['units'][mb_strtolower(rtrim($unit, '.'))] = true;
        }

        $out = [];
        foreach ($byProduct as $productId => $entry) {
            $out[$productId] = count($entry['units']) > 1 ? null : [
                'quantity' => self::quantity($entry['sum']),
                'unit' => $entry['unit'] !== '' ? $entry['unit'] : null,
            ];
        }

        return $out;
    }

    /**
     * Numer, numer ogłoszenia, tytuł i nazwa zamawiającego; od ITEM_SEARCH_MIN_LENGTH znaków także opis pozycji
     * (bez przetargów w archiwum). Kolejność jak lista przetargów — ostatnio ruszane pierwsze.
     *
     * @return list<array<string, mixed>>
     */
    private function tenders(User $user, string $q): array
    {
        $like = self::like($q);
        $withItems = mb_strlen($q) >= self::ITEM_SEARCH_MIN_LENGTH;

        $query = Tender::query()->with('client:id,name');
        if (! $user->can('tenders.view_all')) {
            $query->accessibleBy($user);
        }
        $tenders = $query
            ->where(static function (Builder $w) use ($like, $withItems): void {
                $w->where('number', 'like', $like)
                    ->orWhere('notice_number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhereIn('client_id', Client::query()->select('id')->where('name', 'like', $like));
                if ($withItems) {
                    $w->orWhere(static function (Builder $items) use ($like): void {
                        $items->where('status', '!=', 'archiwum')
                            ->whereIn('id', TenderItem::query()->select('tender_id')->where('requirement', 'like', $like));
                    });
                }
            })
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(self::FETCH)
            ->get(['id', 'number', 'notice_number', 'title', 'client_id', 'status', 'deadline', 'deadline_time']);

        // trafienie tylko w pozycji — pokazujemy, w której (pierwsza pasująca)
        $itemOnly = $withItems ? $tenders->reject(static fn (Tender $t): bool => self::contains($q, [
            $t->number, $t->notice_number, $t->title, $t->client?->name,
        ]))->pluck('id')->map(static fn ($id): int => (int) $id)->all() : [];
        $itemHits = [];
        if ($itemOnly !== []) {
            $items = TenderItem::query()
                ->whereIn('tender_id', $itemOnly)
                ->where('requirement', 'like', $like)
                ->orderBy('tender_id')
                ->orderBy('line_no')
                ->limit(200)
                ->get(['tender_id', 'line_no', 'requirement']);
            foreach ($items as $item) {
                $itemHits[(int) $item->tender_id] ??= 'pozycja '.$item->line_no.': '.self::snippet((string) $item->requirement, $q);
            }
        }

        return $tenders->map(static function (Tender $t) use ($itemHits): array {
            $deadline = PolishTime::formatDeadline($t);

            return [
                'id' => (int) $t->id,
                'title' => (string) $t->title,
                'subtitle' => self::join([$t->number, $t->client?->name]),
                'detail' => self::join([$itemHits[(int) $t->id] ?? null, $deadline !== '' ? 'termin '.$deadline : null]),
                'badge' => TenderWorkflowService::statusLabel($t->status),
                'url' => '/tenders/'.$t->id,
            ];
        })->values()->all();
    }

    /**
     * Własne zapytania; cudze tylko z inquiries.view_others (wtedy też ich treść). Temat, nadawca, adres e-mail
     * i firma z podpisu — bez limitu czasu; treść maila — tylko z ostatnich BODY_DAYS dni.
     *
     * @return list<array<string, mixed>>
     */
    private function inquiries(User $user, string $q): array
    {
        $like = self::like($q);
        $bodyFrom = CarbonImmutable::now()->subDays(self::BODY_DAYS);

        $query = ClientInquiry::query()->with('user:id,name');
        if (! $user->can('inquiries.view_others')) {
            $query->where('user_id', $user->id);
        }
        $rows = $query
            ->where(static function (Builder $w) use ($like, $bodyFrom): void {
                $w->where('source_subject', 'like', $like)
                    ->orWhere('source_from_name', 'like', $like)
                    ->orWhere('source_from_email', 'like', $like)
                    ->orWhere('contact->company', 'like', $like)
                    ->orWhere(static function (Builder $body) use ($like, $bodyFrom): void {
                        $body->where('created_at', '>=', $bodyFrom)->where('source_body', 'like', $like);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::FETCH)
            ->get(['id', 'user_id', 'source_subject', 'source_from_name', 'source_from_email', 'contact', 'source_body',
                'source_sent_at', 'replied_at', 'send_requested_at', 'created_at']);

        $myId = (int) $user->id;

        return $rows->map(static function (ClientInquiry $i) use ($q, $myId, $bodyFrom): array {
            $contact = is_array($i->contact) ? $i->contact : [];
            $company = isset($contact['company']) && is_string($contact['company']) ? trim($contact['company']) : '';
            $title = self::firstFilled([$company, $i->source_from_name, $i->source_from_email]) ?? 'Zapytanie nr '.$i->id;
            $header = self::contains($q, [$i->source_subject, $i->source_from_name, $i->source_from_email, $company]);
            $snippet = ! $header && $i->created_at !== null && $i->created_at->greaterThanOrEqualTo($bodyFrom)
                ? self::snippet((string) $i->source_body, $q)
                : null;
            $date = PolishTime::format($i->source_sent_at ?? $i->created_at, false);

            return [
                'id' => (int) $i->id,
                'title' => $title,
                'subtitle' => self::join([
                    $i->source_subject,
                    (int) $i->user_id !== $myId && $i->user !== null ? 'prowadzi '.$i->user->name : null,
                ]),
                'detail' => $snippet !== null ? '„'.$snippet.'”' : ($date !== '' ? 'z dnia '.$date : null),
                'badge' => match (true) {
                    $i->replied_at !== null => 'Wysłano',
                    $i->send_requested_at !== null => 'Czeka na Thunderbirda',
                    default => 'W przygotowaniu',
                },
                'url' => '/inquiries/'.$i->id,
            ];
        })->values()->all();
    }

    /**
     * Nazwa, akronim, miasto; NIP po samych cyfrach (wpis „526-10-00-000” i „5261000000” to ten sam numer) — gdy
     * fraza to cyfry (z odstępami, kreskami albo kropkami) i ma ich co najmniej 3.
     *
     * @return list<array<string, mixed>>
     */
    private function clients(string $q): array
    {
        $like = self::like($q);
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $byNip = strlen($digits) >= 3 && preg_match('/^[\d\s.\-]+$/u', $q) === 1;

        $clients = Client::query()
            ->where(static function (Builder $w) use ($like, $digits, $byNip): void {
                $w->where('name', 'like', $like)
                    ->orWhere('acronym', 'like', $like)
                    ->orWhere('city', 'like', $like);
                if ($byNip) {
                    $w->orWhereRaw("replace(replace(replace(coalesce(nip, ''), '-', ''), ' ', ''), '.', '') like ?", ['%'.$digits.'%']);
                }
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::FETCH)
            ->get(['id', 'name', 'acronym', 'nip', 'city']);

        return $clients->map(static fn (Client $c): array => [
            'id' => (int) $c->id,
            'title' => (string) $c->name,
            'subtitle' => self::join([
                $c->acronym !== null && trim((string) $c->acronym) !== '' && trim((string) $c->acronym) !== trim((string) $c->name) ? $c->acronym : null,
                $c->city,
                $c->nip !== null && trim((string) $c->nip) !== '' ? 'NIP '.$c->nip : null,
            ]),
            'detail' => null,
            'badge' => null,
            'url' => '/clients/'.$c->id,
        ])->values()->all();
    }

    private static function like(string $q): string
    {
        return '%'.addcslashes($q, '%_\\').'%';
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function join(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map(
            static fn (?string $p): string => trim((string) $p),
            $parts,
        ), static fn (string $p): bool => $p !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Czy któreś pole zawiera frazę (bez wielkości liter). Baza porównuje też bez polskich znaków, więc „nie” tu
     * znaczy tylko „nie widać frazy dosłownie” — wtedy pokazujemy fragment trafionego tekstu.
     *
     * @param  list<string|null>  $values
     */
    private static function contains(string $q, array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && mb_stripos($value, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fragment tekstu wokół frazy (białe znaki zwinięte do spacji); bez dosłownego trafienia — początek tekstu.
     */
    private static function snippet(string $text, string $q, int $before = 40, int $after = 80): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $pos = mb_stripos($text, $q);
        $start = $pos === false ? 0 : max(0, $pos - $before);
        $length = $pos === false ? $before + $after : ($pos - $start) + mb_strlen($q) + $after;
        $part = mb_substr($text, $start, $length);

        return ($start > 0 ? '…' : '').$part.($start + mb_strlen($part) < mb_strlen($text) ? '…' : '');
    }

    /** Ilość bez zbędnych zer: 1840, 12.5 (kropka dziesiętna — formatuje interfejs). */
    private static function quantity(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
