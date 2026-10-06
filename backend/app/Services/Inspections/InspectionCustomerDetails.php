<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\ErpCustomer;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dane klienta z kartoteki ERP XL dla klientów z terminami przeglądów (nabywcy i odbiorcy z inspection_due): adres,
 * telefony, osoby kontaktowe (KntOsoby bez archiwalnych) i opiekun (KntOpiekun → PrcKarty) — zapis w erp_customers
 * (prośba właściciela 06.10.2026: „jak najwięcej informacji o kliencie”). Wartości dosłownie z XL. Kolumny, do których
 * login aplikacji nie ma prawa (np. telefony — GRANT po stronie administratora XL), zostają puste; ich lista trafia do
 * pamięci podręcznej (UNAVAILABLE_KEY), żeby ekran mógł powiedzieć „brak dostępu”, a nie „brak w kartotece”.
 * Woła erp:inspections po przeliczeniu terminów; XL tylko czytany, paczkami po CHUNK klientów.
 */
final class InspectionCustomerDetails
{
    public const UNAVAILABLE_KEY = 'inspections.customer_details_unavailable';

    private const CHUNK = 500;

    /** Najwyżej tyle osób kontaktowych na klienta (karta z setkami archiwalnych wpisów nie zapcha odpowiedzi). */
    private const MAX_CONTACTS = 30;

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /** @return array{customers: int, contacts: int, unavailable: list<string>} */
    public function sync(): array
    {
        DB::disableQueryLog();
        $gids = $this->customerGids();
        $now = CarbonImmutable::now()->startOfSecond();
        $today = ClarionDate::fromDate(PolishTime::today());
        $unavailable = [];
        $updated = 0;
        $contactCount = 0;

        foreach (array_chunk($gids, self::CHUNK) as $chunk) {
            $cards = $this->gateway->customerCards($chunk);
            $contacts = $this->gateway->customerContacts($chunk);
            $unavailable = array_values(array_unique([...$unavailable, ...$cards['unavailable'], ...array_map(
                static fn (string $field): string => 'contact_'.$field,
                $contacts['unavailable'],
            )]));

            $byCustomer = [];
            foreach ($contacts['rows'] as $row) {
                $gid = (int) $row['customer_gid'];
                if (count($byCustomer[$gid] ?? []) >= self::MAX_CONTACTS) {
                    continue;
                }
                $contact = array_filter([
                    'name' => $row['name'] ?? null,
                    'position' => $row['position'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'mobile' => $row['mobile'] ?? null,
                ], static fn ($v): bool => $v !== null && $v !== '');
                if ($contact !== []) {
                    $byCustomer[$gid][] = $contact;
                    $contactCount++;
                }
            }
            $managers = [];
            // pierwszy na kliencie = główny opiekun (zapytanie sortuje po KtO_Glowny)
            foreach ($this->gateway->customerManagers($chunk, $today) as $m) {
                $gid = (int) $m['customer_gid'];
                if (isset($managers[$gid])) {
                    continue;
                }
                $name = trim(implode(' ', array_filter([$m['first_name'] ?? null, $m['last_name'] ?? null])));
                $managers[$gid] = ['name' => $name !== '' ? $name : ($m['acronym'] ?? null), 'email' => $m['email'] ?? null];
            }

            foreach ($cards['rows'] as $card) {
                $gid = (int) $card['gid'];
                $manager = $managers[$gid] ?? null;
                $updated += ErpCustomer::query()->where('xl_gid', $gid)->toBase()->update([
                    'street' => $this->cut($card['street'] ?? null, 200),
                    'address_line2' => $this->cut($card['address_line2'] ?? null, 200),
                    'postal_code' => $this->cut($card['postal_code'] ?? null, 20),
                    'voivodeship' => $this->cut($card['voivodeship'] ?? null, 100),
                    'phone' => $this->cut($card['phone'] ?? null, 100),
                    'phone2' => $this->cut($card['phone2'] ?? null, 100),
                    'contacts' => isset($byCustomer[$gid]) ? json_encode($byCustomer[$gid], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                    'account_manager' => $this->cut($manager['name'] ?? null, 150),
                    'account_manager_email' => $this->cut($manager['email'] ?? null, 150),
                    'details_synced_at' => $now,
                ]);
            }
        }
        Cache::forever(self::UNAVAILABLE_KEY, $unavailable);

        return ['customers' => $updated, 'contacts' => $contactCount, 'unavailable' => $unavailable];
    }

    /** @return list<int> nabywcy i odbiorcy z wyliczonych terminów, tylko obecni w erp_customers */
    private function customerGids(): array
    {
        $gids = DB::table('inspection_due')->distinct()->pluck('customer_xl_gid')
            ->merge(DB::table('inspection_due')->whereNotNull('recipient_xl_gid')->distinct()->pluck('recipient_xl_gid'))
            ->map(static fn ($g): int => (int) $g)->unique()->values()->all();
        $known = [];
        foreach (array_chunk($gids, self::CHUNK) as $chunk) {
            foreach (DB::table('erp_customers')->whereIn('xl_gid', $chunk)->pluck('xl_gid') as $g) {
                $known[] = (int) $g;
            }
        }
        sort($known);

        return $known;
    }

    private function cut(?string $value, int $length): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $length) : null;
    }
}
