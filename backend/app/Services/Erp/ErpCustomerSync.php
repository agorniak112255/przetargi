<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpCustomer;
use App\Models\ErpCustomerItem;
use App\Models\ErpItem;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Klienci ERP XL do kampanii: kontrahenci z e-mailami (karta, potem aktywne adresy), liczba FS/PA z 24 mies. i operator,
 * który wystawił ich najwięcej („mój klient”), oraz co kupowali (erp_customer_items, przebudowa w całości). XL tylko
 * czytany. Kontrahentów nie kasujemy — tym, których XL już nie zwrócił, ustawiamy removed_at.
 */
final class ErpCustomerSync
{
    /** Okno sprzedaży: dokumenty, operator i zakupy z ostatnich 24 miesięcy. */
    public const MONTHS = 24;

    /** Klucz cache z nazwiskami operatorów XL (Ope_Ident → Ope_Nazwisko) dla panelu admina. */
    public const OPERATORS_CACHE_KEY = 'erp.operators';

    private const INSERT_CHUNK = 1000;

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @return array{customers: int, with_email: int, items: int}
     */
    public function run(): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        // bez ułamków sekund — synced_at zapisany z dokładnością do sekundy nie może wyjść „starszy” niż start
        $startedAt = CarbonImmutable::now()->startOfSecond();
        $from = ClarionDate::fromDate(CarbonImmutable::today()->subMonthsNoOverflow(self::MONTHS));

        [$documents, $mainOperators, $operatorNames] = $this->operators($from);

        $addressEmails = [];
        foreach ($this->gateway->customerAddressEmails() as $row) {
            $addressEmails[$row['gid']][] = $row['email'];
        }

        $stats = ['customers' => 0, 'with_email' => 0, 'items' => 0];
        $batch = max(1, (int) config('erpxl.batch', 500));
        $after = 0;
        while (true) {
            $customers = $this->gateway->customers($after, $batch);
            if ($customers === []) {
                break;
            }
            $after = max(array_column($customers, 'gid'));
            $stats['with_email'] += $this->saveBatch($customers, $addressEmails, $documents, $mainOperators, $startedAt);
            $stats['customers'] += count($customers);
            if (count($customers) < $batch) {
                break;
            }
        }
        unset($addressEmails, $documents, $mainOperators);

        // pusta odpowiedź XL to raczej błąd odczytu niż firma bez kontrahentów — nie oznaczamy wszystkich jako usuniętych
        if ($stats['customers'] === 0) {
            throw new RuntimeException('ERP XL nie zwrócił żadnego kontrahenta — przerywam bez zmian w zakupach klientów.');
        }

        ErpCustomer::query()
            ->whereNull('removed_at')
            ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', $startedAt))
            ->update(['removed_at' => $startedAt]);

        $stats['items'] = $this->rebuildItems($from, $startedAt);

        ksort($operatorNames);
        Cache::forever(self::OPERATORS_CACHE_KEY, $operatorNames);

        return $stats;
    }

    /**
     * Liczba FS/PA na klienta (wszystkie, także bez znanego operatora) i operator z największą liczbą dokumentów
     * (remis — ident alfabetycznie pierwszy, żeby wynik nie zależał od kolejności wierszy z XL).
     *
     * @return array{0: array<int, int>, 1: array<int, string>, 2: array<string, string|null>}
     */
    private function operators(int $from): array
    {
        $documents = [];
        $perOperator = [];
        $names = [];
        foreach ($this->gateway->customerOperators($from) as $row) {
            $gid = $row['customer_gid'];
            $documents[$gid] = ($documents[$gid] ?? 0) + $row['documents'];
            $ident = mb_strtoupper(trim($row['operator']));
            if ($ident === '') {
                continue;
            }
            $perOperator[$gid][$ident] = ($perOperator[$gid][$ident] ?? 0) + $row['documents'];
            if (($names[$ident] ?? null) === null) {
                $names[$ident] = $row['operator_name'] !== null && trim($row['operator_name']) !== '' ? trim($row['operator_name']) : null;
            }
        }

        $main = [];
        foreach ($perOperator as $gid => $counts) {
            $best = null;
            foreach ($counts as $ident => $count) {
                $ident = (string) $ident;
                if ($best === null || $count > $counts[$best] || ($count === $counts[$best] && strcmp($ident, $best) < 0)) {
                    $best = $ident;
                }
            }
            if ($best !== null) {
                $main[$gid] = $best;
            }
        }

        return [$documents, $main, $names];
    }

    /**
     * @param  list<array{gid: int, acronym: string, name: string, nip: ?string, city: ?string, email: ?string, archived: bool}>  $customers
     * @param  array<int, list<string>>  $addressEmails
     * @param  array<int, int>  $documents
     * @param  array<int, string>  $mainOperators
     * @return int ilu klientów z paczki ma choć jeden adres
     */
    private function saveBatch(array $customers, array $addressEmails, array $documents, array $mainOperators, CarbonImmutable $now): int
    {
        $rows = [];
        $withEmail = 0;
        foreach ($customers as $c) {
            $gid = $c['gid'];
            $emails = $this->normalizeEmails([$c['email'] ?? '', ...($addressEmails[$gid] ?? [])]);
            if ($emails !== []) {
                $withEmail++;
            }
            $rows[] = [
                'xl_gid' => $gid,
                'acronym' => mb_substr($c['acronym'], 0, 40),
                'name' => $this->cut($c['name'], 300),
                'nip' => $this->cut($c['nip'], 30),
                'city' => $this->cut($c['city'], 100),
                'emails' => $emails === [] ? null : json_encode($emails, JSON_UNESCAPED_UNICODE),
                'archived' => $c['archived'],
                'sale_documents_24m' => $documents[$gid] ?? 0,
                'main_operator' => isset($mainOperators[$gid]) ? mb_substr($mainOperators[$gid], 0, 20) : null,
                'synced_at' => $now,
                'removed_at' => null,
            ];
        }

        ErpCustomer::query()->upsert($rows, ['xl_gid'], [
            'acronym', 'name', 'nip', 'city', 'emails', 'archived', 'sale_documents_24m', 'main_operator', 'synced_at', 'removed_at',
        ]);

        return $withEmail;
    }

    /**
     * Zakupy klientów od nowa (delete + insert paczkami) i data ostatniej sprzedaży klienta — w jednej transakcji, żeby
     * kampania nie trafiła na pustą tabelę. Towary spoza erp_items pomijamy (datę sprzedaży klienta i tak liczą).
     */
    private function rebuildItems(int $from, CarbonImmutable $now): int
    {
        $customerIds = ErpCustomer::query()->whereNull('removed_at')->pluck('id', 'xl_gid')->all();
        $itemIds = ErpItem::query()->pluck('id', 'xl_gid')->all();
        $inserted = 0;

        DB::transaction(function () use ($from, $now, $customerIds, $itemIds, &$inserted): void {
            ErpCustomerItem::query()->delete();

            $lastSale = [];
            $buffer = [];
            foreach ($this->gateway->customerSales($from) as $row) {
                $customerId = $customerIds[$row['customer_gid']] ?? null;
                if ($customerId === null) {
                    continue;
                }
                $lastSale[$customerId] = max($lastSale[$customerId] ?? 0, $row['last_date']);
                $itemId = $itemIds[$row['item_gid']] ?? null;
                if ($itemId === null) {
                    continue;
                }
                $buffer[] = [
                    'erp_customer_id' => $customerId,
                    'erp_item_id' => $itemId,
                    'last_sale_at' => ClarionDate::toDate($row['last_date'])?->toDateString(),
                    'documents' => max(0, $row['documents']),
                    'quantity' => round($row['quantity'], 3),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($buffer) >= self::INSERT_CHUNK) {
                    ErpCustomerItem::query()->insert($buffer);
                    $inserted += count($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                ErpCustomerItem::query()->insert($buffer);
                $inserted += count($buffer);
            }

            // klient bez sprzedaży w oknie zostaje, ale bez daty ostatniego zakupu; zniknięci z XL — bez zmian
            ErpCustomer::query()->toBase()->whereNull('removed_at')->update(['last_sale_at' => null]);
            $byDate = [];
            foreach ($lastSale as $customerId => $date) {
                $day = ClarionDate::toDate($date)?->toDateString();
                if ($day !== null) {
                    $byDate[$day][] = $customerId;
                }
            }
            foreach ($byDate as $day => $ids) {
                foreach (array_chunk($ids, self::INSERT_CHUNK) as $chunk) {
                    ErpCustomer::query()->toBase()->whereIn('id', $chunk)->update(['last_sale_at' => $day]);
                }
            }
        });

        return $inserted;
    }

    /**
     * Adresy z pól XL (każde bywa listą rozdzieloną ; , albo spacją): bez otaczających znaków, małe litery, tylko
     * poprawny format, bez powtórzeń, w kolejności pól (najpierw karta, potem adresy).
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function normalizeEmails(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            foreach (preg_split('/[;,\s\x{00A0}]+/u', $field, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $email = mb_strtolower(rtrim(trim($part, " \t\n\r\0\x0B<>\"'()[]"), '.'));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && ! in_array($email, $out, true)) {
                    $out[] = $email;
                }
            }
        }

        return $out;
    }

    private function cut(?string $value, int $length): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
