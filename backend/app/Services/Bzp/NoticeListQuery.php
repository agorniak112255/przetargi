<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Models\ProcurementNoticeSkip;
use App\Models\ScheduledTaskRun;
use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Services\Reports\TenderEffectivenessReport;
use App\Support\NoticeNumber;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Zakładka „Ogłoszenia”: ogłoszenia o zamówieniu pobrane z Biuletynu (bzp:fetch), po jednym na postępowanie
 * (najnowsza wersja numeru ogłoszenia).
 *
 * Zakładki są rozłączne:
 *  - created: jest przetarg z tym postępowaniem (numer ogłoszenia przetargu bez wersji = bzp_number ogłoszenia albo
 *    przetarg powiązany z tym ogłoszeniem przez contract_notice_id) — także gdy ogłoszenie wcześniej pominięto,
 *  - skipped: decyzja „pominięte” postępowania (bzp_number — obejmuje nowe wersje ogłoszenia) i brak przetargu,
 *  - new: bez decyzji i bez przetargu; bez `past` tylko z terminem składania w przyszłości albo bez terminu.
 * Filtry: rodzaj zamówienia (config bzp.cpv_categories — kod pasuje jak w raporcie skuteczności: najdłuższy
 * pasujący początek; liczone w PHP na liście id, potem zwykłe stronicowanie w SQL), województwo (kod „PLxx”),
 * tekst (każde słowo w przedmiocie, nazwie lub mieście zamawiającego albo numerze ogłoszenia).
 * Bez GROUP BY (ONLY_FULL_GROUP_BY na produkcji); strona po 50, bez pełnej treści ogłoszeń.
 */
final class NoticeListQuery
{
    public const TAB_NEW = 'new';

    public const TAB_CREATED = 'created';

    public const TAB_SKIPPED = 'skipped';

    public const TABS = [self::TAB_NEW, self::TAB_CREATED, self::TAB_SKIPPED];

    /** Źródła listy (na razie tylko Biuletyn; kolejne — np. zapytania ofertowe z Logintrade — dojdą tutaj). */
    public const SOURCES = [ProcurementNotice::SOURCE_BZP];

    public const PER_PAGE = 50;

    public const SOURCE_NOTE = 'Źródło: Biuletyn Zamówień Publicznych — ogłoszenia o zamówieniu z kodami rodzaju zamówienia (CPV) '
        .'odzieży, obuwia, rękawic i sprzętu ochronnego, pobierane codziennie o 6:30. Ogłoszenia z Dziennika Urzędowego '
        .'Unii Europejskiej (TED) nie są pobierane. Zamówienia o wartości poniżej 130 000 zł nie mają wspólnego źródła '
        .'ogłoszeń, więc nie ma ich na tej liście.';

    private const ROW_COLUMNS = [
        'id', 'source', 'notice_number', 'bzp_number', 'object_id', 'published_at', 'submitting_offers_at', 'order_object',
        'cpv_codes', 'organization_name', 'organization_city', 'organization_province', 'organization_nip', 'parsed',
    ];

    /** @var array<string, list<int>> id ogłoszeń danego rodzaju zamówienia (klucz: źródło|rodzaj) */
    private array $categoryIds = [];

    /** @var array{bzp_numbers: list<string>, notice_ids: list<int>}|null */
    private ?array $linked = null;

    /** podpowiedź klienta — indeks klientów budowany od nowa przy każdym wywołaniu list() / row() */
    private ?NoticeClientMatcher $clients = null;

    /**
     * @param  array{tab?: ?string, category?: ?string, province?: ?string, q?: ?string, past?: bool, page?: int, source?: ?string}  $params
     * @return array<string, mixed>
     */
    public function list(User $user, array $params): array
    {
        $tab = in_array($params['tab'] ?? null, self::TABS, true) ? (string) $params['tab'] : self::TAB_NEW;
        $past = (bool) ($params['past'] ?? false);
        $now = CarbonImmutable::now();
        // zbiory liczone raz na wywołanie (liczniki i strona) — nie między wywołaniami tego samego obiektu
        $this->linked = null;
        $this->categoryIds = [];
        $this->clients = new NoticeClientMatcher;

        $counts = [];
        foreach (self::TABS as $name) {
            $counts[$name] = $this->inTab($this->filtered($params), $name, $past, $now)->count();
        }
        $total = $counts[$tab];
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($params['page'] ?? 1)), $lastPage);

        $ids = $this->inTab($this->filtered($params), $tab, $past, $now)
            ->orderByRaw('pn.submitting_offers_at is null')
            ->orderBy('pn.submitting_offers_at')
            ->orderByDesc('pn.published_at')
            ->orderByDesc('pn.id')
            ->forPage($page, self::PER_PAGE)
            ->pluck('pn.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'data' => $this->rows($ids, $user),
            'meta' => ['page' => $page, 'last_page' => $lastPage, 'total' => $total],
            'counts' => $counts,
            'categories' => self::categories(),
            'provinces' => self::provinces(),
            'fetched_at' => $this->fetchedAt()?->toJSON(),
            'source_note' => self::SOURCE_NOTE,
        ];
    }

    /**
     * Jeden wiersz listy (odpowiedź „Pomiń” / „Przywróć”).
     *
     * @return array<string, mixed>
     */
    public function row(ProcurementNotice $notice, User $user): array
    {
        $this->clients = new NoticeClientMatcher;

        return $this->rows([(int) $notice->id], $user)[0];
    }

    /**
     * Przetarg założony z tym postępowaniem (najstarszy, gdy jest kilka) — ta sama reguła co zakładka „created”.
     */
    public static function tenderFor(ProcurementNotice $notice): ?Tender
    {
        return self::tendersFor([$notice])[(int) $notice->id] ?? null;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function categories(): array
    {
        $out = [];
        foreach ((array) config('bzp.cpv_categories', []) as $key => $category) {
            $out[] = ['key' => (string) $key, 'label' => (string) ($category['label'] ?? $key)];
        }

        return $out;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function provinces(): array
    {
        $out = [];
        foreach ((array) config('bzp.provinces', []) as $code => $name) {
            $out[] = ['code' => (string) $code, 'name' => (string) $name];
        }

        return $out;
    }

    /**
     * Ogłoszenia o zamówieniu ze źródła, najnowsza wersja numeru w postępowaniu, z filtrami (bez zakładki).
     *
     * @param  array{category?: ?string, province?: ?string, q?: ?string, source?: ?string}  $params
     */
    private function filtered(array $params): Builder
    {
        $source = in_array($params['source'] ?? null, self::SOURCES, true) ? (string) $params['source'] : ProcurementNotice::SOURCE_BZP;
        $query = DB::table('procurement_notices as pn')
            ->where('pn.notice_type', ProcurementNotice::TYPE_CONTRACT)
            ->where('pn.source', $source)
            ->whereNotExists(static function (Builder $newer): void {
                $newer->selectRaw('1')
                    ->from('procurement_notices as pv')
                    ->where('pv.notice_type', ProcurementNotice::TYPE_CONTRACT)
                    ->whereColumn('pv.bzp_number', 'pn.bzp_number')
                    ->whereColumn('pv.notice_number', '>', 'pn.notice_number');
            });

        $province = trim((string) ($params['province'] ?? ''));
        if ($province !== '') {
            $query->where('pn.organization_province', $province);
        }

        $category = trim((string) ($params['category'] ?? ''));
        if ($category !== '') {
            $query->whereIntegerInRaw('pn.id', $this->categoryIds($source, $category));
        }

        // %, _ i \ usunięte (SQLite nie ma domyślnego znaku ucieczki w LIKE) — jak w wyszukiwaniu firm konkurencji
        $text = str_replace(['%', '_', '\\'], ' ', (string) ($params['q'] ?? ''));
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: [], static fn (string $w): bool => $w !== '')), 0, 8);
        foreach ($words as $word) {
            $like = '%'.$word.'%';
            $query->where(static function (Builder $any) use ($like): void {
                $any->where('pn.order_object', 'like', $like)
                    ->orWhere('pn.organization_name', 'like', $like)
                    ->orWhere('pn.organization_city', 'like', $like)
                    ->orWhere('pn.notice_number', 'like', $like);
            });
        }

        return $query;
    }

    private function inTab(Builder $query, string $tab, bool $past, CarbonImmutable $now): Builder
    {
        $linked = $this->linkedProcedures();
        // po kolumnach z indeksem (bzp_number, id) — bez porównywania z każdym przetargiem w SQL
        $hasTender = static function (Builder $match) use ($linked): void {
            $match->whereIn('pn.bzp_number', $linked['bzp_numbers'])
                ->orWhereIntegerInRaw('pn.id', $linked['notice_ids']);
        };
        $hasSkip = static function (Builder $skips): void {
            $skips->selectRaw('1')
                ->from('procurement_notice_skips as s')
                ->whereColumn('s.bzp_number', 'pn.bzp_number');
        };

        if ($tab === self::TAB_CREATED) {
            return $query->where($hasTender);
        }
        if ($tab === self::TAB_SKIPPED) {
            return $query->whereExists($hasSkip)->whereNot($hasTender);
        }

        $query->whereNotExists($hasSkip)->whereNot($hasTender);
        if (! $past) {
            $query->where(static function (Builder $open) use ($now): void {
                $open->whereNull('pn.submitting_offers_at')
                    ->orWhere('pn.submitting_offers_at', '>', $now->utc()->format('Y-m-d H:i:s'));
            });
        }

        return $query;
    }

    /**
     * Postępowania, dla których jest przetarg: numery Biuletynu bez wersji z numerów ogłoszeń przetargów
     * (NoticeNumber::parse — jak tendersFor) i ogłoszenia powiązane przez contract_notice_id. Raz na obiekt.
     *
     * @return array{bzp_numbers: list<string>, notice_ids: list<int>}
     */
    private function linkedProcedures(): array
    {
        if ($this->linked !== null) {
            return $this->linked;
        }
        $numbers = [];
        $ids = [];
        $tenders = Tender::query()
            ->where(static function ($query): void {
                $query->whereNotNull('contract_notice_id')->orWhere('notice_number', 'like', '%BZP%');
            })
            ->select(['id', 'notice_number', 'contract_notice_id'])
            ->lazyById(1000);
        foreach ($tenders as $tender) {
            $bzp = NoticeNumber::parse($tender->notice_number)['bzp_number'] ?? null;
            if ($bzp !== null) {
                $numbers[$bzp] = true;
            }
            if ($tender->contract_notice_id !== null) {
                $ids[(int) $tender->contract_notice_id] = true;
            }
        }

        return $this->linked = ['bzp_numbers' => array_map('strval', array_keys($numbers)), 'notice_ids' => array_keys($ids)];
    }

    /**
     * Id ogłoszeń o zamówieniu, w których któryś kod CPV należy do rodzaju — porcjami po samych id i kodach.
     *
     * @return list<int>
     */
    private function categoryIds(string $source, string $category): array
    {
        $cacheKey = $source.'|'.$category;
        if (isset($this->categoryIds[$cacheKey])) {
            return $this->categoryIds[$cacheKey];
        }
        $ids = [];
        $rows = DB::table('procurement_notices')
            ->where('notice_type', ProcurementNotice::TYPE_CONTRACT)
            ->where('source', $source)
            ->select(['id', 'cpv_codes'])
            ->lazyById(1000);
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->cpv_codes, true);
            if (in_array($category, self::categoryKeys(self::codes(is_array($decoded) ? $decoded : [])), true)) {
                $ids[] = (int) $row->id;
            }
        }

        return $this->categoryIds[$cacheKey] = $ids;
    }

    /**
     * @param  list<int>  $ids  w kolejności listy
     * @return list<array<string, mixed>>
     */
    private function rows(array $ids, User $user): array
    {
        if ($ids === []) {
            return [];
        }
        $notices = ProcurementNotice::query()->whereIn('id', $ids)->get(self::ROW_COLUMNS)->keyBy('id');
        // decyzja postępowania, nie wersji ogłoszenia
        $skips = ProcurementNoticeSkip::query()
            ->with('user:id,name')
            ->whereIn('bzp_number', $notices->pluck('bzp_number')->map(static fn (mixed $n): string => (string) $n)->unique()->values()->all())
            ->get()
            ->keyBy('bzp_number');
        $tenders = self::tendersFor($notices->all());
        $openable = $this->openableTenderIds($user, $tenders);
        $canCreate = $user->can('tenders.create');
        $now = CarbonImmutable::now();

        $rows = [];
        foreach ($ids as $id) {
            $notice = $notices->get($id);
            if (! $notice instanceof ProcurementNotice) {
                continue;
            }
            $tender = $tenders[$id] ?? null;
            $skip = $skips->get((string) $notice->bzp_number);
            $rows[] = $this->present($notice, $tender, $tender !== null && isset($openable[(int) $tender->id]), $skip, $canCreate && $tender === null, $now);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProcurementNotice $notice, ?Tender $tender, bool $canOpen, ?ProcurementNoticeSkip $skip, bool $matchClient, CarbonImmutable $now): array
    {
        $parsed = is_array($notice->parsed) ? $notice->parsed : [];
        $codes = self::codes(is_array($notice->cpv_codes) ? $notice->cpv_codes : []);
        $labels = [];
        $categoryKeys = self::categoryKeys($codes);
        foreach (self::categories() as $category) {
            if (in_array($category['key'], $categoryKeys, true)) {
                $labels[] = $category['label'];
            }
        }
        $province = $notice->organization_province;
        $provinces = (array) config('bzp.provinces', []);
        $lots = is_array($parsed['lots'] ?? null) ? $parsed['lots'] : [];
        $procedureUrl = is_string($parsed['procedure_url'] ?? null) && preg_match('~^https?://~i', $parsed['procedure_url']) === 1
            ? $parsed['procedure_url'] : null;
        $objectId = trim((string) $notice->object_id);
        $deadline = $notice->submitting_offers_at;
        $client = $matchClient ? ($this->clients ??= new NoticeClientMatcher)->resolve($notice->organization_nip, $notice->organization_name) : null;

        return [
            'id' => (int) $notice->id,
            'source' => (string) ($notice->source ?: ProcurementNotice::SOURCE_BZP),
            'notice_number' => $notice->notice_number,
            'published_at' => $notice->published_at?->toJSON(),
            'submitting_offers_at' => $deadline?->toJSON(),
            'deadline_local' => $deadline !== null ? PolishTime::format($deadline) : null,
            'order_object' => $notice->order_object,
            'organization' => [
                'name' => $notice->organization_name,
                'city' => $notice->organization_city,
                'province_code' => $province,
                'province_name' => is_string($province) && isset($provinces[$province]) ? (string) $provinces[$province] : null,
                'nip' => $notice->organization_nip,
            ],
            'categories' => $labels,
            'cpv_codes' => $codes,
            'total_value' => self::formatAmount($parsed['total_value'] ?? (empty($parsed['has_lots']) && count($lots) === 1 ? ($lots[0]['estimated_value'] ?? null) : null)),
            // 0 = zamówienie bez podziału na części (albo treści ogłoszenia nie odczytano)
            'lots_count' => ! empty($parsed['has_lots']) ? count($lots) : 0,
            'procedure_url' => $procedureUrl,
            'notice_url' => $objectId !== '' ? str_replace('{id}', rawurlencode($objectId), (string) config('bzp.notice_page_url')) : null,
            'tender' => $tender !== null ? ['id' => (int) $tender->id, 'number' => (string) $tender->number, 'can_open' => $canOpen] : null,
            'skipped' => $skip !== null ? [
                'by' => $skip->user !== null ? ['id' => (int) $skip->user->id, 'name' => (string) $skip->user->name] : null,
                'at' => $skip->created_at?->toJSON(),
            ] : null,
            'past' => $deadline !== null && $deadline->lessThanOrEqualTo($now),
            // podpowiedź do potwierdzenia „Załóż przetarg” (tylko z uprawnieniem tenders.create i bez przetargu):
            // istniejący klient (po NIP-ie albo nazwie); null i pusta lista kandydatów = zostanie założony nowy klient;
            // null i kandydaci = kilku pasujących klientów — zamawiającego wybiera człowiek
            'client_match' => $client['match'] ?? null,
            'client_candidates' => $client['candidates'] ?? [],
        ];
    }

    /**
     * Przetarg (najstarszy) dla każdego ogłoszenia: contract_notice_id = id ogłoszenia albo numer ogłoszenia
     * przetargu bez wersji = bzp_number.
     *
     * @param  array<int|string, ProcurementNotice>  $notices
     * @return array<int, Tender> po id ogłoszenia
     */
    private static function tendersFor(array $notices): array
    {
        if ($notices === []) {
            return [];
        }
        $ids = [];
        $numbers = [];
        foreach ($notices as $notice) {
            $ids[] = (int) $notice->id;
            $numbers[(string) $notice->bzp_number] = true;
        }
        $tenders = Tender::query()
            ->select(['id', 'number', 'owner_id', 'notice_number', 'contract_notice_id'])
            ->where(static function ($query) use ($ids, $numbers): void {
                $query->whereIn('contract_notice_id', $ids);
                foreach (array_keys($numbers) as $number) {
                    if ($number === '') {
                        continue;
                    }
                    $query->orWhere('notice_number', $number)
                        ->orWhere('notice_number', 'like', $number.'/%');
                }
            })
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($notices as $notice) {
            foreach ($tenders as $tender) {
                $bzp = NoticeNumber::parse($tender->notice_number)['bzp_number'] ?? null;
                if ((int) $tender->contract_notice_id === (int) $notice->id || ($bzp !== null && $bzp === $notice->bzp_number)) {
                    $out[(int) $notice->id] = $tender;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Przetargi, które użytkownik może otworzyć (jak TenderAccessService::canView).
     *
     * @param  array<int, Tender>  $tenders
     * @return array<int, true>
     */
    private function openableTenderIds(User $user, array $tenders): array
    {
        if ($tenders === []) {
            return [];
        }
        $out = [];
        if ($user->can('tenders.view_all')) {
            foreach ($tenders as $tender) {
                $out[(int) $tender->id] = true;
            }

            return $out;
        }
        if (! $user->can('tenders.view_own')) {
            return [];
        }
        $ids = array_map(static fn (Tender $t): int => (int) $t->id, array_values($tenders));
        $invited = TenderInvitation::query()
            ->where('user_id', $user->id)
            ->whereIn('tender_id', $ids)
            ->pluck('tender_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        foreach ($tenders as $tender) {
            if ((int) $tender->owner_id === (int) $user->id || in_array((int) $tender->id, $invited, true)) {
                $out[(int) $tender->id] = true;
            }
        }

        return $out;
    }

    /** Najpóźniejsze pobranie: zapis ogłoszenia albo udany przebieg bzp:fetch (przebieg bez nowych ogłoszeń też się liczy). */
    private function fetchedAt(): ?CarbonImmutable
    {
        $moments = array_filter([
            ProcurementNotice::query()->max('fetched_at'),
            ScheduledTaskRun::query()->where('task', 'bzp:fetch')->where('status', ScheduledTaskRun::STATUS_OK)->max('finished_at'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        $latest = null;
        foreach ($moments as $value) {
            $moment = CarbonImmutable::parse((string) $value, 'UTC');
            if ($latest === null || $moment->greaterThan($latest)) {
                $latest = $moment;
            }
        }

        return $latest;
    }

    /**
     * Kody CPV z zapisu ogłoszenia ([{code, name}]).
     *
     * @param  array<int|string, mixed>  $cpv
     * @return list<string>
     */
    private static function codes(array $cpv): array
    {
        $codes = [];
        foreach ($cpv as $row) {
            $code = is_array($row) ? ($row['code'] ?? null) : $row;
            if (is_string($code) && $code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Rodzaje zamówienia kodów (bez „Inny rodzaj”).
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private static function categoryKeys(array $codes): array
    {
        $keys = [];
        foreach ($codes as $code) {
            $category = TenderEffectivenessReport::categoryOf($code);
            if ($category !== null && $category['key'] !== TenderEffectivenessReport::OTHER_CATEGORY['key'] && ! in_array($category['key'], $keys, true)) {
                $keys[] = $category['key'];
            }
        }

        return $keys;
    }

    /**
     * Kwota z ogłoszenia ({amount: „1365178.85”, currency}) dla ludzi: „1 365 178,85 PLN”; bez waluty w ogłoszeniu —
     * sama liczba (bez zgadywania waluty).
     */
    public static function formatAmount(mixed $value): ?string
    {
        if (! is_array($value) || ! is_string($value['amount'] ?? null) || preg_match('/^(\d+)\.(\d{2})$/', $value['amount'], $m) !== 1) {
            return null;
        }
        $integer = strrev(implode(' ', str_split(strrev($m[1]), 3)));
        $currency = is_string($value['currency'] ?? null) && $value['currency'] !== '' ? ' '.$value['currency'] : '';

        return $integer.','.$m[2].$currency;
    }
}
