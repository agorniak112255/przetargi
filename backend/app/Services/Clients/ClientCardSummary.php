<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ErpItemLink;
use App\Models\ErpSaleDocument;
use App\Models\User;
use App\Services\Erp\ErpClientDocumentSync;
use App\Support\PolishTime;
use Illuminate\Support\Facades\DB;

/**
 * Nagłówek karty klienta (GET /clients/{client}): dane klienta, opiekun w ERP XL i opiekun w aplikacji osobno,
 * przypisanie do handlowca (ClientAssignment), kafelki i „Najczęściej kupuje (24 miesiące)”.
 *
 * Sprzedaż z jednego źródła — nocnej kopii dokumentów z ERP XL (erp_sale_documents). Do pierwszego udanego odczytu
 * (documents_synced_at = null) zakupy w roku i ostatni zakup pochodzą z zakładki Klienci (erp:clients, ta sama reguła
 * XL), a liczby faktur nie ma. XL nie jest pytany na żywo.
 *
 * Liczby zapytań i przetargów liczą tylko to, co użytkownik widzi w osi czasu (null bez uprawnienia do sekcji).
 */
final class ClientCardSummary
{
    /** Najczęściej kupowane — ile pozycji. */
    private const TOP_ITEMS = 10;

    public function __construct(
        private readonly ClientTimeline $timeline,
        private readonly ClientAssignment $assignment,
    ) {}

    /**
     * @return array<string, mixed> ClientCard (frontend/src/lib/api.ts)
     */
    public function build(Client $client, User $user): array
    {
        $client->loadMissing('owner:id,name');
        $sections = ClientTimeline::sections($user);
        $syncedAt = ErpClientDocumentSync::syncedAt();
        $canManage = $user->can('clients.manage');

        $card = [
            'client' => $client->toArray(),
            'xl_manager' => $this->xlManager($client),
            'app_owner' => $client->owner !== null ? ['id' => (int) $client->owner->id, 'name' => (string) $client->owner->name] : null,
            'assignment' => $this->assignment($client),
            'tiles' => [
                'sales_year' => $this->salesYear($client, $syncedAt !== null),
                'last_sale' => $this->lastSale($client, $syncedAt !== null),
                'last_12m' => $this->last12Months($client, $user, $sections),
            ],
            'top_items' => $this->topItems($client),
            'sections' => $sections,
            'can_manage' => $canManage,
            'documents_synced_at' => $syncedAt?->toIso8601String(),
        ];
        if ($canManage) {
            // wybór opiekuna w aplikacji (PATCH /clients/{client} owner_id) — tylko z clients.manage
            $card['owner_options'] = User::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
                ->all();
        }

        return $card;
    }

    /** @return array{name: ?string, email: ?string, user: array{id: int, name: string}|null}|null */
    private function xlManager(Client $client): ?array
    {
        if ($client->account_manager === null && $client->account_manager_email === null && $client->xl_manager_gid === null) {
            return null;
        }
        $user = $client->xl_manager_gid !== null
            ? User::query()->where('erp_employee_gid', $client->xl_manager_gid)->first(['id', 'name'])
            : null;

        return [
            'name' => $client->account_manager,
            'email' => $client->account_manager_email,
            'user' => $user !== null ? ['id' => (int) $user->id, 'name' => (string) $user->name] : null,
        ];
    }

    /** @return array{user: array{id: int, name: string}|null, source: 'xl'|'app'|null} */
    private function assignment(Client $client): array
    {
        $assigned = $this->assignment->forClients([(int) $client->id])[(int) $client->id] ?? ['user_id' => null, 'source' => null];
        $user = $assigned['user_id'] !== null ? User::query()->find($assigned['user_id'], ['id', 'name']) : null;

        return [
            'user' => $user !== null ? ['id' => (int) $user->id, 'name' => (string) $user->name] : null,
            'source' => $user !== null ? $assigned['source'] : null,
        ];
    }

    /** @return array{year: int, net: string, documents: int}|null */
    private function salesYear(Client $client, bool $synced): ?array
    {
        if ($client->xl_gid === null) {
            return null;
        }
        $year = (int) PolishTime::today()->year;
        if (! $synced) {
            return (int) $client->sales_year === $year
                ? ['year' => $year, 'net' => self::money($client->sales_net), 'documents' => (int) $client->sale_documents]
                : null;
        }
        $rows = ErpSaleDocument::query()
            ->where('client_id', $client->id)
            ->whereBetween('issued_at', [$year.'-01-01', $year.'-12-31'])
            ->get(['kind', 'net_value']);

        return [
            'year' => $year,
            'net' => self::money($rows->sum(static fn (ErpSaleDocument $d): float => (float) $d->net_value)),
            'documents' => $rows->filter(static fn (ErpSaleDocument $d): bool => in_array($d->kind, ErpSaleDocument::SALE_KINDS, true))->count(),
        ];
    }

    /** @return array{date: string, document_number: ?string}|null */
    private function lastSale(Client $client, bool $synced): ?array
    {
        if ($synced) {
            $doc = ErpSaleDocument::query()
                ->where('client_id', $client->id)
                ->whereIn('kind', ErpSaleDocument::SALE_KINDS)
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->first(['document_number', 'issued_at']);
            if ($doc !== null && $doc->issued_at !== null) {
                return ['date' => $doc->issued_at->format('Y-m-d'), 'document_number' => (string) $doc->document_number];
            }
        }
        // przed pierwszym odczytem dokumentów (albo bez dokumentów w oknie) — data z zakładki Klienci, bez numeru
        if ($client->last_sale_at !== null) {
            return ['date' => $client->last_sale_at->format('Y-m-d'), 'document_number' => null];
        }

        return null;
    }

    /**
     * @param  array{inquiries: bool, tenders: bool, campaigns: bool}  $sections
     * @return array{invoices: int, inquiries: int|null, tenders: int|null, ordered_inquiries: int|null}
     */
    private function last12Months(Client $client, User $user, array $sections): array
    {
        $from = PolishTime::today()->subMonthsNoOverflow(12);

        $inquiries = null;
        $ordered = null;
        if ($sections['inquiries']) {
            $groups = $this->timeline->inquiryGroups($client, $user, $from, 5000)['groups'];
            $inquiries = count($groups);
            $ordered = count(array_filter($groups, static fn (array $g): bool => in_array($g['outcome'], ClientTimeline::ORDERED_OUTCOMES, true)));
        }

        return [
            'invoices' => ErpSaleDocument::query()
                ->where('client_id', $client->id)
                ->whereIn('kind', ErpSaleDocument::SALE_KINDS)
                ->where('issued_at', '>=', $from->toDateString())
                ->count(),
            'inquiries' => $inquiries,
            'tenders' => $sections['tenders'] ? ClientTimeline::tendersQuery($client, $user, $from)->count() : null,
            'ordered_inquiries' => $ordered,
        ];
    }

    /**
     * Najczęściej kupowane towary XL (erp_customer_items — zakupy z 24 miesięcy, erp:customers), po liczbie dokumentów.
     * Karta katalogu tylko przy jednym pewnym powiązaniu (auto albo potwierdzone) — kilka kart = bez karty.
     *
     * @return list<array<string, mixed>>
     */
    private function topItems(Client $client): array
    {
        if ($client->xl_gid === null) {
            return [];
        }
        $customerId = DB::table('erp_customers')->where('xl_gid', $client->xl_gid)->whereNull('removed_at')->value('id');
        if ($customerId === null) {
            return [];
        }
        $rows = DB::table('erp_customer_items as ci')
            ->join('erp_items as i', 'i.id', '=', 'ci.erp_item_id')
            ->where('ci.erp_customer_id', $customerId)
            ->orderByDesc('ci.documents')
            ->orderByDesc('ci.quantity')
            ->orderBy('i.id')
            ->limit(self::TOP_ITEMS)
            ->get(['ci.erp_item_id', 'i.code', 'i.name', 'i.unit', 'ci.quantity', 'ci.documents', 'ci.last_sale_at']);
        if ($rows->isEmpty()) {
            return [];
        }

        $products = [];
        $links = DB::table('erp_item_links as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.erp_item_id', $rows->pluck('erp_item_id')->all())
            ->whereIn('l.status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED])
            ->get(['l.erp_item_id', 'p.id', 'p.name']);
        foreach ($links as $link) {
            $products[(int) $link->erp_item_id][(int) $link->id] = (string) $link->name;
        }

        return $rows->map(static function (object $r) use ($products): array {
            $linked = $products[(int) $r->erp_item_id] ?? [];
            $product = count($linked) === 1 ? ['id' => (int) array_key_first($linked), 'name' => (string) reset($linked)] : null;

            return [
                'erp_item_id' => (int) $r->erp_item_id,
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'unit' => $r->unit !== null && trim((string) $r->unit) !== '' ? (string) $r->unit : null,
                'quantity' => number_format((float) $r->quantity, 3, '.', ''),
                'documents' => (int) $r->documents,
                'last_sale_at' => $r->last_sale_at !== null ? substr((string) $r->last_sale_at, 0, 10) : null,
                'product' => $product,
            ];
        })->values()->all();
    }

    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
