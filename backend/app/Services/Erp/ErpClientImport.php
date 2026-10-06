<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\Client;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Zakładka Klienci z Comarch ERP XL: kontrahenci, którzy w roku kupili (FS + PA + FSE minus korekty, netto PLN; faktura
 * do WZ z pozycji jej WZ, WZ bez faktury od dnia wydania — ErpXlGateway::customerSalesTotals) za co najmniej próg, z pełną kartą, osobami kontaktowymi i opiekunem. Klienci już powiązani z XL są odświeżani także poniżej
 * progu (zakupy w roku mogą spaść do zera) — nikogo nie kasujemy. Klient dopisany ręcznie z tym samym NIP-em zostaje
 * powiązany: dostaje numer XL, zakupy, kontakty i opiekuna z XL, a z karty tylko pola, które miał puste (nazwa, wpisane
 * dane i opiekun w panelu bez zmian).
 * XL tylko czytany.
 */
final class ErpClientImport
{
    /** Długości kolumn tabeli clients — wartości XL są krótsze, obcięcie to tylko zabezpieczenie zapisu. */
    private const LENGTHS = [
        'name' => 255, 'acronym' => 40, 'nip' => 40, 'nip_prefix' => 5, 'regon' => 30, 'street' => 200, 'address_line2' => 200,
        'postal_code' => 20, 'city' => 100, 'county' => 100, 'commune' => 100, 'voivodeship' => 100, 'country' => 100,
        'phone' => 100, 'phone2' => 100, 'fax' => 100, 'website' => 255,
    ];

    /** Pola karty kopiowane z XL (email osobno — łączony z adresami kontrahenta). */
    private const CARD_FIELDS = [
        'name', 'acronym', 'nip', 'nip_prefix', 'regon', 'street', 'address_line2', 'postal_code', 'city', 'county', 'commune',
        'voivodeship', 'country', 'phone', 'phone2', 'fax', 'website',
    ];

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @return array{qualifying: int, created: int, updated: int, linked_by_nip: int, without_card: int, unavailable: list<string>}
     */
    public function run(int $year, float $minNet, bool $dryRun = false): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        $now = CarbonImmutable::now()->startOfSecond();
        $totals = [];
        foreach ($this->gateway->customerSalesTotals(
            ClarionDate::fromDate(CarbonImmutable::create($year, 1, 1)),
            ClarionDate::fromDate(CarbonImmutable::create($year, 12, 31)),
        ) as $row) {
            $totals[$row['customer_gid']] = $row;
        }
        $qualifying = array_keys(array_filter($totals, static fn (array $t): bool => $t['net'] >= $minNet));

        /** @var array<int, Client> $linked */
        $linked = Client::query()->whereNotNull('xl_gid')->get()->keyBy('xl_gid')->all();
        $gids = array_values(array_unique([...$qualifying, ...array_keys($linked)]));
        sort($gids);
        $qualifyingSet = array_flip($qualifying);

        $cards = $this->gateway->customerCards($gids);
        $contacts = $this->gateway->customerContacts($gids);
        $managers = [];
        foreach ($this->gateway->customerManagers($gids, ClarionDate::fromDate($now)) as $row) {
            $managers[$row['customer_gid']] ??= $row;
        }
        $wanted = array_flip($gids);
        $addressEmails = [];
        foreach ($this->gateway->customerAddressEmails() as $row) {
            if (isset($wanted[$row['gid']])) {
                $addressEmails[$row['gid']][] = $row['email'];
            }
        }
        $people = [];
        foreach ($contacts['rows'] as $row) {
            $person = array_filter([
                'name' => $row['name'], 'position' => $row['position'], 'email' => $row['email'],
                'phone' => $row['phone'], 'mobile' => $row['mobile'],
            ], static fn (?string $v): bool => $v !== null);
            if ($person !== []) {
                $people[$row['customer_gid']][] = $person;
            }
        }

        $unavailable = array_values(array_unique([...$cards['unavailable'], ...array_map(static fn (string $f): string => 'contacts.'.$f, $contacts['unavailable'])]));
        $skipFields = array_flip($cards['unavailable']);
        $manualByNip = $this->manualClientsByNip();
        $stats = ['qualifying' => count($qualifying), 'created' => 0, 'updated' => 0, 'linked_by_nip' => 0, 'without_card' => 0, 'unavailable' => $unavailable];
        $seen = [];

        $save = function () use ($cards, $linked, $qualifyingSet, $manualByNip, $totals, $people, $managers, $addressEmails, $skipFields, $year, $now, $dryRun, &$stats, &$seen): void {
            foreach ($cards['rows'] as $card) {
                $gid = $card['gid'];
                $seen[$gid] = true;
                $client = $linked[$gid] ?? null;
                $linkedByNip = false;
                if ($client === null) {
                    if (! isset($qualifyingSet[$gid])) {
                        continue;
                    }
                    $nip = self::nipDigits($card['nip']);
                    $client = $nip !== null ? ($manualByNip[$nip] ?? null) : null;
                    if ($client !== null) {
                        unset($manualByNip[$nip]);
                        $linkedByNip = true;
                    } else {
                        $client = new Client(['source' => Client::SOURCE_ERP_XL]);
                    }
                }

                $fromXl = $client->source === Client::SOURCE_ERP_XL;
                $values = [];
                foreach (self::CARD_FIELDS as $field) {
                    if (isset($skipFields[$field])) {
                        continue;
                    }
                    $value = self::cut($card[$field], self::LENGTHS[$field]);
                    // klient ręczny: z karty XL tylko to, czego nie miał (nie nadpisujemy wpisanych danych)
                    if ($fromXl || self::isBlank($client->getAttribute($field))) {
                        $values[$field] = $value;
                    }
                }
                // nazwa jest w podstawowym GRANT-cie, ale karta bez nazwy też się zdarza — nazwa klienta jest wymagana
                if ($fromXl && ($values['name'] ?? null) === null) {
                    $values['name'] = $card['acronym'] !== '' ? $card['acronym'] : 'Kontrahent XL '.$gid;
                }
                if (! isset($skipFields['email'])) {
                    $emails = ErpCustomerSync::normalizeEmails([$card['email'] ?? '', ...($addressEmails[$gid] ?? [])]);
                    if ($fromXl || self::isBlank($client->emails)) {
                        $values['emails'] = $emails === [] ? null : $emails;
                    }
                }
                $manager = $managers[$gid] ?? null;
                $managerName = $manager === null ? null : (trim(($manager['first_name'] ?? '').' '.($manager['last_name'] ?? '')) ?: $manager['acronym']);
                $total = $totals[$gid] ?? null;

                $client->fill([
                    ...$values,
                    'xl_gid' => $gid,
                    'xl_archived' => $card['archived'],
                    'contacts' => $people[$gid] ?? null,
                    'account_manager' => self::cut($managerName, 150),
                    'account_manager_email' => self::cut($manager['email'] ?? null, 150),
                    // numer pracownika XL — przypisanie klienta handlowcowi (users.erp_employee_gid, ClientAssignment)
                    'xl_manager_gid' => $manager['employee_gid'] ?? null,
                    'sales_year' => $year,
                    'sales_net' => $total['net'] ?? 0,
                    'sale_documents' => $total['documents'] ?? 0,
                    'last_sale_at' => ($total['last_date'] ?? 0) > 0 ? ClarionDate::toDate($total['last_date'])?->toDateString() : null,
                    'xl_synced_at' => $now,
                ]);

                $isNew = ! $client->exists;
                if (! $dryRun) {
                    $client->save();
                }
                if ($linkedByNip) {
                    $stats['linked_by_nip']++;
                } elseif ($isNew) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            }
        };

        $dryRun ? $save() : DB::transaction($save);
        $stats['without_card'] = count(array_diff_key(array_flip($gids), $seen));

        return $stats;
    }

    /**
     * Klienci ręczni bez numeru XL po NIP-ie (same cyfry, co najmniej 10); NIP powtórzony u kilku klientów — pomijany,
     * żeby nie zgadywać, którego powiązać.
     *
     * @return array<string, Client>
     */
    private function manualClientsByNip(): array
    {
        $byNip = [];
        $duplicates = [];
        foreach (Client::query()->whereNull('xl_gid')->whereNotNull('nip')->get() as $client) {
            $nip = self::nipDigits($client->nip);
            if ($nip === null) {
                continue;
            }
            if (isset($byNip[$nip])) {
                $duplicates[$nip] = true;
            }
            $byNip[$nip] = $client;
        }

        return array_diff_key($byNip, $duplicates);
    }

    private static function nipDigits(?string $nip): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $nip) ?? '';

        return strlen($digits) >= 10 ? $digits : null;
    }

    private static function cut(?string $value, int $length): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
