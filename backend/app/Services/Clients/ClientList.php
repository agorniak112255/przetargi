<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Support\CityName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zakładka Klienci (GET /clients?page=…): jedna strona listy z pełnymi danymi klienta. Wyszukiwanie i filtry
 * (źródło, miejscowość, opiekun) liczone na lekkich kolumnach wszystkich klientów — te same reguły co wcześniej
 * w przeglądarce (e-maile i osoby kontaktowe są w JSON z polskimi znakami jako \uXXXX, więc LIKE w SQL by ich nie
 * znalazł); sortowanie i strona — w SQL. Opcje filtrów i liczniki w nagłówku zawsze z całej listy.
 */
final class ClientList
{
    public const SORTS = ['name', 'nip', 'city', 'manager', 'sales', 'last_sale', 'tenders'];

    public const SOURCES = ['all', 'xl', 'manual'];

    public const PER_PAGE = [25, 50, 100, 200];

    public const DEFAULT_PER_PAGE = 50;

    /** Filtr opiekuna: klient bez opiekuna w XL i w aplikacji. */
    public const NO_MANAGER = '__none';

    /** Opiekun jak w kolumnie „Opiekun”: z XL, a gdy go brak — opiekun w aplikacji. */
    private const MANAGER_SQL = "COALESCE(NULLIF(clients.account_manager, ''), (SELECT users.name FROM users WHERE users.id = clients.owner_id))";

    /**
     * @param  array{q?: ?string, source?: ?string, city?: ?string, manager?: ?string, sort?: ?string, dir?: ?string, page?: int|string|null, per_page?: int|string|null}  $params
     * @return array{data: list<Client>, meta: array{current_page: int, last_page: int, per_page: int, total: int}, summary: array<string, mixed>}
     */
    public function page(array $params): array
    {
        $q = trim((string) ($params['q'] ?? ''));
        $source = (string) ($params['source'] ?? 'all');
        $cityFilter = (string) ($params['city'] ?? '');
        $managerFilter = (string) ($params['manager'] ?? '');

        $ownerNames = DB::table('users')->pluck('name', 'id')->all();
        $rows = DB::table('clients')->orderBy('id')->get([
            'id', 'name', 'acronym', 'nip', 'regon', 'city', 'street', 'voivodeship', 'phone', 'phone2',
            'account_manager', 'emails', 'contacts', 'owner_id', 'xl_gid', 'sales_year',
        ]);

        $words = $q === '' ? [] : (preg_split('/\s+/u', mb_strtolower($q, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $qDigits = preg_replace('/\D+/', '', $q) ?? '';
        $filtered = $q !== '' || $source !== 'all' || $cityFilter !== '' || $managerFilter !== '';

        $ids = [];
        $summary = ['total' => 0, 'xl' => 0, 'without_manager' => 0, 'sales_year' => null];
        $cityCounts = [];
        $spellings = [];
        $managerCounts = [];
        foreach ($rows as $row) {
            $manager = self::blankToNull($row->account_manager)
                ?? ($row->owner_id !== null ? self::blankToNull($ownerNames[$row->owner_id] ?? null) : null);
            $city = trim(preg_replace('/\s+/u', ' ', (string) $row->city) ?? '');
            $cityKey = $city === '' ? null : CityName::key($city);

            $summary['total']++;
            if ($row->xl_gid !== null) {
                $summary['xl']++;
            }
            if ($row->sales_year !== null) {
                $summary['sales_year'] = max((int) $row->sales_year, (int) $summary['sales_year']);
            }
            if ($cityKey !== null) {
                $cityCounts[$cityKey] = ($cityCounts[$cityKey] ?? 0) + 1;
                $spellings[$cityKey][$city] = ($spellings[$cityKey][$city] ?? 0) + 1;
            }
            if ($manager === null) {
                $summary['without_manager']++;
            } else {
                $managerCounts[$manager] = ($managerCounts[$manager] ?? 0) + 1;
            }

            if (! $filtered) {
                continue;
            }
            if ($source !== 'all' && ($source === 'xl') !== ($row->xl_gid !== null)) {
                continue;
            }
            if ($cityFilter !== '' && $cityKey !== $cityFilter) {
                continue;
            }
            if ($managerFilter !== '' && ($managerFilter === self::NO_MANAGER ? $manager !== null : $manager !== $managerFilter)) {
                continue;
            }
            if ($q !== '' && ! self::matches($row, $words, $qDigits)) {
                continue;
            }
            $ids[] = (int) $row->id;
        }

        $cities = [];
        foreach ($cityCounts as $key => $count) {
            $cities[] = ['value' => (string) $key, 'label' => CityName::displaySpelling($spellings[$key]), 'count' => $count];
        }
        usort($cities, static fn (array $a, array $b): int => strcmp($a['value'], $b['value']));
        $managers = [];
        foreach ($managerCounts as $name => $count) {
            $managers[] = ['value' => (string) $name, 'label' => (string) $name, 'count' => $count];
        }
        usort($managers, static fn (array $a, array $b): int => strcmp(Str::lower(Str::ascii($a['label'])), Str::lower(Str::ascii($b['label']))));
        $summary['cities'] = $cities;
        $summary['managers'] = $managers;

        $sort = in_array($params['sort'] ?? null, self::SORTS, true) ? (string) $params['sort'] : 'sales';
        $dir = ($params['dir'] ?? null) === 'asc' ? 'asc' : 'desc';
        $perPage = in_array((int) ($params['per_page'] ?? 0), self::PER_PAGE, true) ? (int) $params['per_page'] : self::DEFAULT_PER_PAGE;

        $query = Client::query()->with(['owner:id,name'])->withCount('tenders')
            ->when($filtered, static fn ($query) => $query->whereIntegerInRaw('clients.id', $ids === [] ? [0] : $ids));
        // puste wartości zawsze na końcu, przy remisie kolejność po nazwie — jak sortowanie tabel w przeglądarce
        match ($sort) {
            'name' => $query->orderBy('clients.name', $dir),
            'nip', 'city' => $query->orderByRaw("CASE WHEN clients.{$sort} IS NULL OR clients.{$sort} = '' THEN 1 ELSE 0 END")
                ->orderBy('clients.'.$sort, $dir),
            'manager' => $query->orderByRaw('CASE WHEN '.self::MANAGER_SQL.' IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw(self::MANAGER_SQL.' '.$dir),
            'sales' => $query->orderByRaw('CASE WHEN clients.sales_net IS NULL THEN 1 ELSE 0 END')->orderBy('clients.sales_net', $dir),
            'last_sale' => $query->orderByRaw('CASE WHEN clients.last_sale_at IS NULL THEN 1 ELSE 0 END')->orderBy('clients.last_sale_at', $dir),
            'tenders' => $query->orderBy('tenders_count', $dir),
        };
        $paginator = $query->orderBy('clients.name')->orderBy('clients.id')
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($params['page'] ?? 1)));

        return [
            'data' => array_values($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * Każde słowo zapytania w danych klienta (nazwa, skrót, NIP, REGON, adres, telefony, opiekun z XL, e-maile, osoby
     * kontaktowe) albo — od 5 cyfr — cyfry zapytania w NIP-ie zapisanym z myślnikami.
     *
     * @param  list<string>  $words
     */
    private static function matches(object $row, array $words, string $qDigits): bool
    {
        $parts = [
            $row->name, $row->acronym, $row->nip, $row->regon, $row->city, $row->street, $row->voivodeship,
            $row->phone, $row->phone2, $row->account_manager,
        ];
        foreach (self::jsonList($row->emails) as $email) {
            $parts[] = is_scalar($email) ? (string) $email : null;
        }
        foreach (self::jsonList($row->contacts) as $person) {
            if (is_array($person)) {
                foreach (['name', 'email', 'phone', 'mobile'] as $field) {
                    $parts[] = isset($person[$field]) && is_scalar($person[$field]) ? (string) $person[$field] : null;
                }
            }
        }
        $hay = mb_strtolower(implode(' ', array_filter($parts, static fn ($part): bool => $part !== null && $part !== '')), 'UTF-8');

        $all = true;
        foreach ($words as $word) {
            if (! str_contains($hay, $word)) {
                $all = false;
                break;
            }
        }

        return $all || (strlen($qDigits) >= 5 && str_contains(preg_replace('/\D+/', '', (string) $row->nip) ?? '', $qDigits));
    }

    /** @return list<mixed> */
    private static function jsonList(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function blankToNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
