<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ClientNote;
use App\Models\ErpSaleDocument;
use App\Models\Tender;
use App\Models\User;
use App\Services\Erp\ErpCustomerSync;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Oś czasu karty klienta: faktury i paragony z ERP XL, zapytania, przetargi, kampanie i notatki — każde źródło
 * najwyżej LIMIT wpisów z ostatnich MONTHS miesięcy, najnowsze pierwsze. Każda sekcja sprawdza własne uprawnienie
 * (jak w module, z którego pochodzi):
 *  - faktury i notatki — wystarczy karta klienta (clients.view),
 *  - zapytania — inquiries.use; własne, a z inquiries.view_all wszystkie; otworzyć cudze można z inquiries.view_others.
 *    Tylko zapytania z pewnym powiązaniem z klientem (client_id — wybrane przez handlowca, ten sam adres e-mail co
 *    w ERP XL albo NIP z maila; InquiryClientLinker), bez zgadywania po domenie. Kopie tego samego maila u kilku
 *    handlowców (łańcuch duplicate_of_id) — jeden wpis,
 *  - przetargi — tenders.view_all albo przetargi, w których użytkownik jest opiekunem lub zaproszonym,
 *  - kampanie — po starcie wysyłki; z campaigns.view / campaigns.manage wszystkie, inaczej własne (jak raport
 *    „Sprzedaż i oferty”); odbiorca to ten klient po kontrahencie XL albo po adresie e-mail z karty klienta.
 */
final class ClientTimeline
{
    public const TYPES = ['all', 'invoices', 'inquiries', 'tenders', 'campaigns', 'notes'];

    /** Najwięcej wpisów z jednego źródła. */
    public const LIMIT = 200;

    /** Okres osi czasu (karta pokazuje 24 miesiące). */
    public const MONTHS = 24;

    /** Kampanie po starcie wysyłki (jak raport „Sprzedaż i oferty”). */
    private const CAMPAIGN_STARTED = [Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    /** Wynik zapytania, który znaczy „klient zamówił” (całość albo część). */
    public const ORDERED_OUTCOMES = ['ordered', 'partial'];

    /** Kolejność źródeł przy tym samym dniu. */
    private const TYPE_ORDER = ['note' => 0, 'invoice' => 1, 'inquiry' => 2, 'tender' => 3, 'campaign' => 4];

    /**
     * Które sekcje użytkownik widzi (faktury i notatki zawsze — to część karty klienta).
     *
     * @return array{inquiries: bool, tenders: bool, campaigns: bool}
     */
    public static function sections(User $user): array
    {
        return [
            'inquiries' => $user->can('inquiries.use'),
            'tenders' => $user->canAny(['tenders.view_own', 'tenders.view_all']),
            'campaigns' => $user->canAny(['campaigns.use', 'campaigns.view', 'campaigns.manage']),
        ];
    }

    /** Początek okresu osi czasu: dziś w Polsce minus MONTHS miesięcy (00:00). */
    public static function from(): CarbonImmutable
    {
        return PolishTime::today()->subMonthsNoOverflow(self::MONTHS);
    }

    /**
     * @return array{data: list<array<string, mixed>>, truncated: array<string, bool>}
     */
    public function build(Client $client, User $user, string $type = 'all'): array
    {
        $from = self::from();
        $sections = self::sections($user);
        $events = [];
        $truncated = [];

        $wants = static fn (string $source): bool => $type === 'all' || $type === $source;

        if ($wants('invoices')) {
            [$items, $truncated['invoices']] = $this->invoices($client, $user, $from, $sections['inquiries']);
            array_push($events, ...$items);
        }
        if ($wants('inquiries') && $sections['inquiries']) {
            $groups = $this->inquiryGroups($client, $user, $from, self::LIMIT);
            $truncated['inquiries'] = $groups['truncated'];
            array_push($events, ...$this->inquiryEvents($groups['groups'], $user));
        }
        if ($wants('tenders') && $sections['tenders']) {
            [$items, $truncated['tenders']] = $this->tenders($client, $user, $from);
            array_push($events, ...$items);
        }
        if ($wants('campaigns') && $sections['campaigns']) {
            [$items, $truncated['campaigns']] = $this->campaigns($client, $user, $from);
            array_push($events, ...$items);
        }
        if ($wants('notes')) {
            [$items, $truncated['notes']] = $this->notes($client, $user, $from);
            array_push($events, ...$items);
        }

        usort($events, static function (array $a, array $b): int {
            return [$b['_sort'], self::TYPE_ORDER[$a['type']], $b['id']] <=> [$a['_sort'], self::TYPE_ORDER[$b['type']], $a['id']];
        });

        return [
            'data' => array_map(static function (array $event): array {
                unset($event['_sort']);

                return $event;
            }, $events),
            'truncated' => $truncated,
        ];
    }

    /**
     * Wpis notatki na osi czasu (także odpowiedź po zapisie notatki).
     *
     * @return array<string, mixed>
     */
    public static function noteEvent(ClientNote $note, User $user): array
    {
        $author = $note->author;

        return [
            'type' => 'note',
            'id' => (int) $note->id,
            'date' => $note->created_at?->toIso8601String(),
            'body' => (string) $note->body,
            'author' => $author instanceof User ? ['id' => (int) $author->id, 'name' => (string) $author->name] : null,
            'remind_on' => $note->remind_on?->format('Y-m-d'),
            'can_edit' => self::canEditNote($note, $user),
        ];
    }

    /** Edycja i usuwanie notatki: autor albo clients.manage. */
    public static function canEditNote(ClientNote $note, User $user): bool
    {
        return ($note->user_id !== null && (int) $note->user_id === (int) $user->id) || $user->can('clients.manage');
    }

    /**
     * Zapytania klienta złożone w grupy jednego maila (oryginał i kopie u innych handlowców) — od $from, w zakresie
     * widocznym dla użytkownika, najnowsze pierwsze. Wpis grupy: wiersz użytkownika, gdy go ma, inaczej oryginał.
     *
     * @return array{groups: list<array{row: object, date: CarbonImmutable, replied_at: ?CarbonImmutable, outcome: ?string}>, truncated: bool}
     */
    public function inquiryGroups(Client $client, User $user, CarbonImmutable $from, int $limit): array
    {
        $fetch = $limit * 3;
        $rows = DB::table('client_inquiries')
            ->where('client_id', $client->id)
            ->when(! $user->can('inquiries.view_all'), static fn ($q) => $q->where('user_id', $user->id))
            ->whereRaw('COALESCE(source_sent_at, created_at) >= ?', [self::dbTime($from)])
            ->orderByRaw('COALESCE(source_sent_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit($fetch + 1)
            ->get(['id', 'user_id', 'duplicate_of_id', 'source_subject', 'reply_subject', 'source_sent_at', 'created_at', 'replied_at', 'outcome', 'client_link_source'])
            ->all();
        $truncated = count($rows) > $fetch;
        $rows = array_slice($rows, 0, $fetch);

        $roots = $this->roots($rows);
        $groups = [];
        foreach ($rows as $row) {
            $root = $roots[(int) $row->id];
            $groups[$root][] = $row;
        }

        $out = [];
        foreach ($groups as $root => $members) {
            $own = array_values(array_filter($members, static fn (object $r): bool => (int) $r->user_id === (int) $user->id));
            $rootRow = array_values(array_filter($members, static fn (object $r): bool => (int) $r->id === (int) $root));
            $representative = $own[0] ?? $rootRow[0] ?? $members[count($members) - 1];

            $date = null;
            $replied = null;
            foreach ($members as $member) {
                $start = self::parse($member->source_sent_at ?? $member->created_at);
                if ($start !== null && ($date === null || $start->lt($date))) {
                    $date = $start;
                }
                $answered = self::parse($member->replied_at);
                if ($answered !== null && ($replied === null || $answered->lt($replied))) {
                    $replied = $answered;
                }
            }
            $outcome = is_string($representative->outcome) ? $representative->outcome : null;
            foreach ($members as $member) {
                $outcome ??= is_string($member->outcome) ? $member->outcome : null;
            }

            $out[] = ['row' => $representative, 'date' => $date ?? CarbonImmutable::now(), 'replied_at' => $replied, 'outcome' => $outcome];
        }
        usort($out, static fn (array $a, array $b): int => [$b['date'], (int) $b['row']->id] <=> [$a['date'], (int) $a['row']->id]);
        if (count($out) > $limit) {
            $truncated = true;
            $out = array_slice($out, 0, $limit);
        }

        return ['groups' => $out, 'truncated' => $truncated];
    }

    /**
     * Przetargi klienta widoczne dla użytkownika od $from (dzień terminu, a bez terminu — dzień założenia).
     *
     * @return Builder<Tender>
     */
    public static function tendersQuery(Client $client, User $user, CarbonImmutable $from): Builder
    {
        return Tender::query()
            ->when(! $user->can('tenders.view_all'), static fn ($q) => $q->accessibleBy($user))
            ->where('client_id', $client->id)
            ->where(static function ($q) use ($from): void {
                $q->whereDate('deadline', '>=', $from->toDateString())
                    ->orWhere(static function ($q) use ($from): void {
                        $q->whereNull('deadline')->where('created_at', '>=', self::dbTime($from));
                    });
            });
    }

    /**
     * @param  list<array{row: object, date: CarbonImmutable, replied_at: ?CarbonImmutable, outcome: ?string}>  $groups
     * @return list<array<string, mixed>>
     */
    private function inquiryEvents(array $groups, User $user): array
    {
        if ($groups === []) {
            return [];
        }
        $ids = array_map(static fn (array $g): int => (int) $g['row']->id, $groups);
        $itemCounts = [];
        foreach (ClientInquiry::query()->whereIn('id', $ids)->get(['id', 'analysis']) as $inquiry) {
            $analysis = is_array($inquiry->analysis) ? $inquiry->analysis : [];
            $itemCounts[(int) $inquiry->id] = is_array($analysis['line_items'] ?? null) ? count($analysis['line_items']) : 0;
        }
        $userIds = array_values(array_unique(array_map(static fn (array $g): int => (int) $g['row']->user_id, $groups)));
        $names = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')->all();
        $viewOthers = $user->can('inquiries.view_others');

        $events = [];
        foreach ($groups as $group) {
            $row = $group['row'];
            $authorId = (int) $row->user_id;
            $subject = trim((string) ($row->source_subject ?? '')) !== '' ? (string) $row->source_subject : $row->reply_subject;
            $source = in_array($row->client_link_source, [InquiryClientLinker::SOURCE_EMAIL, InquiryClientLinker::SOURCE_NIP], true)
                ? (string) $row->client_link_source
                : InquiryClientLinker::SOURCE_MANUAL;
            $events[] = [
                'type' => 'inquiry',
                'id' => (int) $row->id,
                'date' => $group['date']->toIso8601String(),
                'subject' => $subject !== null && trim((string) $subject) !== '' ? (string) $subject : null,
                'items_count' => $itemCounts[(int) $row->id] ?? 0,
                'replied_at' => $group['replied_at']?->toIso8601String(),
                'outcome' => $group['outcome'],
                'user' => ['id' => $authorId, 'name' => (string) ($names[$authorId] ?? 'Użytkownik #'.$authorId)],
                'can_open' => $authorId === (int) $user->id || $viewOthers,
                'link_source' => $source,
                '_sort' => self::sortKey($group['date']),
            ];
        }

        return $events;
    }

    /**
     * Korzeń grupy każdego wiersza: idzie po duplicate_of_id (brakujące ogniwa doczytuje z bazy, najwyżej 10 kroków
     * w głąb), aż trafi na wiersz, który nie jest kopią.
     *
     * @param  list<object>  $rows
     * @return array<int, int>
     */
    private function roots(array $rows): array
    {
        /** @var array<int, int|null> $parent */
        $parent = [];
        foreach ($rows as $row) {
            $parent[(int) $row->id] = $row->duplicate_of_id !== null ? (int) $row->duplicate_of_id : null;
        }
        for ($depth = 0; $depth < 10; $depth++) {
            $missing = array_values(array_unique(array_filter(
                $parent,
                static fn (?int $p): bool => $p !== null && ! array_key_exists($p, $parent),
            )));
            if ($missing === []) {
                break;
            }
            foreach (DB::table('client_inquiries')->whereIn('id', $missing)->get(['id', 'duplicate_of_id']) as $row) {
                $parent[(int) $row->id] = $row->duplicate_of_id !== null ? (int) $row->duplicate_of_id : null;
            }
            // oryginał skasowany — ogniwo kończy łańcuch
            foreach ($missing as $id) {
                $parent[$id] ??= null;
            }
        }

        $roots = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $seen = [];
            while (($parent[$id] ?? null) !== null && ! isset($seen[$id])) {
                $seen[$id] = true;
                $id = (int) $parent[$id];
            }
            $roots[(int) $row->id] = $id;
        }

        return $roots;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function invoices(Client $client, User $user, CarbonImmutable $from, bool $inquiriesVisible): array
    {
        $rows = ErpSaleDocument::query()
            ->where('client_id', $client->id)
            ->where('issued_at', '>=', $from->toDateString())
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT + 1)
            ->get(['id', 'document_number', 'kind', 'issued_at', 'net_value']);
        $truncated = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);

        // dokument potwierdzony przez handlowca jako zamówienie z zapytania (wynik zapytania) — tylko zapytania,
        // które użytkownik widzi; can_open jak przy wpisie zapytania: własne albo z inquiries.view_others (sam
        // inquiries.view_all pokazuje cudze na liście, ale ich nie otwiera). Przy kilku zapytaniach z tym samym
        // dokumentem pierwszeństwo ma takie, które użytkownik może otworzyć.
        $confirmed = [];
        $numbers = $rows->pluck('document_number')->filter()->unique()->values()->all();
        if ($inquiriesVisible && $numbers !== []) {
            $myId = (int) $user->id;
            $viewOthers = $user->can('inquiries.view_others');
            $query = DB::table('client_inquiries')
                ->where('client_id', $client->id)
                ->whereIn('outcome', self::ORDERED_OUTCOMES)
                ->whereIn('outcome_document_number', $numbers)
                ->when(! $user->can('inquiries.view_all'), static fn ($q) => $q->where('user_id', $myId))
                ->orderBy('id')
                ->get(['id', 'user_id', 'outcome_document_number', 'source_sent_at', 'created_at']);
            foreach ($query as $row) {
                $number = (string) $row->outcome_document_number;
                $canOpen = (int) $row->user_id === $myId || $viewOthers;
                if (isset($confirmed[$number]) && ($confirmed[$number]['can_open'] || ! $canOpen)) {
                    continue;
                }
                $date = self::parse($row->source_sent_at ?? $row->created_at);
                $confirmed[$number] = ['id' => (int) $row->id, 'date' => $date?->toIso8601String(), 'can_open' => $canOpen];
            }
        }

        $events = [];
        foreach ($rows as $doc) {
            /** @var ErpSaleDocument $doc */
            $day = $doc->issued_at?->format('Y-m-d') ?? '';
            $events[] = [
                'type' => 'invoice',
                'id' => (int) $doc->id,
                'date' => $day,
                'document_number' => (string) $doc->document_number,
                'kind' => (string) $doc->kind,
                'net_value' => number_format((float) $doc->net_value, 2, '.', ''),
                'confirmed_inquiry' => $confirmed[(string) $doc->document_number] ?? null,
                '_sort' => $day.' 12:00:00',
            ];
        }

        return [$events, $truncated];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function tenders(Client $client, User $user, CarbonImmutable $from): array
    {
        $rows = self::tendersQuery($client, $user, $from)
            ->orderByRaw('COALESCE(deadline, created_at) DESC')
            ->orderByDesc('id')
            ->limit(self::LIMIT + 1)
            ->get(['id', 'number', 'title', 'status', 'result_status', 'deadline', 'created_at']);
        $truncated = $rows->count() > self::LIMIT;

        $events = [];
        foreach ($rows->take(self::LIMIT) as $tender) {
            /** @var Tender $tender */
            $day = $tender->deadline !== null
                ? $tender->deadline->format('Y-m-d')
                : ($tender->created_at !== null ? CarbonImmutable::instance($tender->created_at)->setTimezone(PolishTime::TIMEZONE)->format('Y-m-d') : '');
            $events[] = [
                'type' => 'tender',
                'id' => (int) $tender->id,
                'date' => $day,
                'number' => (string) $tender->number,
                'title' => (string) $tender->title,
                'status' => (string) $tender->status,
                'result_status' => $tender->result_status !== null ? (string) $tender->result_status : null,
                'url' => '/tenders/'.$tender->id,
                '_sort' => $day.' 12:00:00',
            ];
        }

        return [$events, $truncated];
    }

    /**
     * Kampanie, w których klient był odbiorcą (po kontrahencie XL albo adresie e-mail z karty klienta) — jeden wpis
     * na kampanię: dzień wysyłki, kliknięcia (bez skanerów poczty) i czy odpowiedział.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function campaigns(Client $client, User $user, CarbonImmutable $from): array
    {
        $customerIds = $client->xl_gid !== null
            ? DB::table('erp_customers')->where('xl_gid', $client->xl_gid)->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : [];
        $emails = self::clientEmails($client);
        if ($customerIds === [] && $emails === []) {
            return [[], false];
        }

        $fetch = self::LIMIT * 5;
        $rows = DB::table('campaign_recipients as r')
            ->join('campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->whereIn('c.status', self::CAMPAIGN_STARTED)
            ->when(! $user->canAny(['campaigns.view', 'campaigns.manage']), static fn ($q) => $q->where('c.user_id', $user->id))
            ->where('r.status', CampaignRecipient::STATUS_SENT)
            ->whereNotNull('r.sent_at')
            ->where('r.sent_at', '>=', self::dbTime($from))
            ->where(static function ($q) use ($customerIds, $emails): void {
                if ($customerIds !== []) {
                    $q->whereIn('r.erp_customer_id', $customerIds);
                }
                if ($emails !== []) {
                    $q->orWhereIn('r.email', $emails);
                }
            })
            ->orderByDesc('r.sent_at')
            ->orderByDesc('r.id')
            ->limit($fetch + 1)
            ->get(['r.campaign_id', 'c.name', 'c.subject', 'c.user_id', 'r.sent_at', 'r.clicks', 'r.replied_at'])
            ->all();
        $truncated = count($rows) > $fetch;
        $rows = array_slice($rows, 0, $fetch);

        $byCampaign = [];
        foreach ($rows as $row) {
            $id = (int) $row->campaign_id;
            $sent = self::parse($row->sent_at);
            $entry = $byCampaign[$id] ?? [
                'name' => trim((string) $row->name) !== '' ? (string) $row->name : (string) $row->subject,
                'user_id' => (int) $row->user_id,
                'date' => $sent,
                'clicks' => 0,
                'replied' => false,
            ];
            if ($sent !== null && ($entry['date'] === null || $sent->lt($entry['date']))) {
                $entry['date'] = $sent;
            }
            $entry['clicks'] += (int) $row->clicks;
            $entry['replied'] = $entry['replied'] || $row->replied_at !== null;
            $byCampaign[$id] = $entry;
        }
        if (count($byCampaign) > self::LIMIT) {
            $truncated = true;
            $byCampaign = array_slice($byCampaign, 0, self::LIMIT, true);
        }
        $names = DB::table('users')->whereIn('id', array_values(array_unique(array_column($byCampaign, 'user_id'))))->pluck('name', 'id')->all();

        $events = [];
        foreach ($byCampaign as $id => $entry) {
            $date = $entry['date'] ?? CarbonImmutable::now();
            $events[] = [
                'type' => 'campaign',
                'id' => $id,
                'date' => $date->toIso8601String(),
                'name' => $entry['name'],
                'clicks' => $entry['clicks'],
                'replied' => $entry['replied'],
                'user' => ['id' => $entry['user_id'], 'name' => (string) ($names[$entry['user_id']] ?? 'Użytkownik #'.$entry['user_id'])],
                '_sort' => self::sortKey($date),
            ];
        }

        return [$events, $truncated];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function notes(Client $client, User $user, CarbonImmutable $from): array
    {
        $rows = ClientNote::query()
            ->with('author:id,name')
            ->where('client_id', $client->id)
            ->where('created_at', '>=', self::dbTime($from))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT + 1)
            ->get();
        $truncated = $rows->count() > self::LIMIT;

        $events = [];
        foreach ($rows->take(self::LIMIT) as $note) {
            /** @var ClientNote $note */
            $created = $note->created_at !== null ? CarbonImmutable::instance($note->created_at) : CarbonImmutable::now();
            $events[] = [...self::noteEvent($note, $user), '_sort' => self::sortKey($created)];
        }

        return [$events, $truncated];
    }

    /**
     * Adresy e-mail z karty klienta: adresy firmy (clients.emails) i osób kontaktowych (małe litery, jak odbiorcy kampanii).
     *
     * @return list<string>
     */
    public static function clientEmails(Client $client): array
    {
        $fields = is_array($client->emails) ? array_values(array_filter($client->emails, 'is_string')) : [];
        foreach (is_array($client->contacts) ? $client->contacts : [] as $contact) {
            if (is_array($contact) && is_string($contact['email'] ?? null)) {
                $fields[] = $contact['email'];
            }
        }

        return ErpCustomerSync::normalizeEmails($fields);
    }

    /** Chwila do sortowania: data i godzina w Polsce. */
    private static function sortKey(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(PolishTime::TIMEZONE)->format('Y-m-d H:i:s');
    }

    /** Granica okresu (północ w Polsce) w strefie zapisu bazy. */
    public static function dbTime(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, (string) config('app.timezone', 'UTC'));
    }
}
