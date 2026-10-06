<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\InspectionDue;
use App\Models\InspectionPosition;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Przebudowa wyliczonych terminów przeglądów (inspection_due: klient XL × pozycja) z kopii pozycji faktur
 * (inspection_sale_lines). Woła ją erp:inspections po nocnym odczycie i API pozycji po każdej zmianie pozycji.
 *
 * Reguły (kontrakt modułu, 06.10.2026):
 * - zdarzenie = dokument (FS/FSE albo WZ — faktura do WZ nie ma własnych pozycji) z pozycją; data = data sprzedaży, a gdy XL jej nie podał — data wystawienia; korekta
 *   doliczana do dokumentu, który koryguje (ten sam klient i pozycja; także korekta korekty); korekta bez znalezionego
 *   oryginału (np. oryginał sprzed początku historii) — pomijana; dokument skorygowany do zera albo poniżej nie jest
 *   zdarzeniem (anulowana faktura nie przesuwa początku wizyty);
 * - wizyta (dla towaru: zakup) = zdarzenia klienta po dacie, łączone, dopóki następne ≤ początek wizyty + VISIT_DAYS,
 *   ale najwyżej pół interwału (przegląd co miesiąc: 15 dni — inaczej dwa kolejne przeglądy sklejały się w jedną wizytę);
 * - wizyta odnowiona: później była kolejna wizyta po co najmniej RENEWAL_SHARE interwału albo — dla towaru z usługą
 *   odnawiającą — klient kupił tę usługę (ilość po korektach > 0) po początku wizyty;
 * - termin otwartej (nieodnowionej) wizyty = początek + interwał (miesiące bez przeskoku końca miesiąca); wiersz tylko,
 *   gdy jest choć jedna otwarta wizyta;
 * - pomijani klienci archiwalni i usunięci w erp_customers; klient nieznany w erp_customers zostaje;
 * - same_nip_newer: inna karta XL z tym samym NIP-em (same cyfry, co najmniej 10) ma wizytę tej pozycji po ostatniej
 *   wizycie klienta.
 *
 * Pamięć: pozycja po pozycji, wiersze faktur kursorem posortowane po kliencie i dacie, w pamięci tylko jeden klient
 * naraz i gotowe wiersze jednej pozycji.
 */
final class InspectionDueBuilder
{
    /** Wizyta: zdarzenia w odstępie do tylu dni od jej początku (kilka faktur za jeden przegląd); krótszy interwał — pół interwału. */
    public const VISIT_DAYS = 31;

    /** Kolejna wizyta po takiej części interwału odnawia poprzednią. */
    public const RENEWAL_SHARE = 0.75;

    /** Średnia długość miesiąca w dniach (próg odnowienia w dniach). */
    private const MONTH_DAYS = 30.44;

    /** Najwyżej tyle dokumentów ostatniej wizyty w last_documents. */
    private const LAST_DOCUMENTS = 10;

    /** Korekty FS (2041), FSE (2045) i WZ (2009) — ilość i wartość ze znakiem. */
    private const CORRECTION_TYPES = [2041, 2045, 2009];

    /** Minimalna liczba cyfr NIP-u do porównania kart (krótszy to nie NIP). */
    private const NIP_MIN_DIGITS = 10;

    private const CHUNK = 500;

    /** @var array<int, array<int, string>> usługa odnawiająca → klient → data ostatniej sprzedaży (w jednej przebudowie) */
    private array $renewalCache = [];

    /**
     * @param  list<int>|null  $positionIds  null = wszystkie pozycje
     * @return int liczba zapisanych wierszy inspection_due
     */
    public function rebuild(?array $positionIds = null): int
    {
        DB::disableQueryLog();
        $this->renewalCache = [];

        $query = InspectionPosition::query()->orderBy('id');
        if ($positionIds !== null) {
            $positionIds = array_values(array_unique(array_map('intval', $positionIds)));
            if ($positionIds === []) {
                return 0;
            }
            $query->whereIn('id', $positionIds);
        }
        $positions = $query->get(['id', 'xl_gid', 'xl_type', 'interval_months', 'renewed_by_xl_gid', 'active']);

        // pozycje usunięte (wiersze zwykle znikają kaskadą — tu na wypadek zapisu bez kluczy obcych)
        $missing = $positionIds === null
            ? InspectionDue::query()->whereNotIn('inspection_position_id', InspectionPosition::query()->select('id'))
            : InspectionDue::query()->whereIn('inspection_position_id', array_values(array_diff($positionIds, $positions->pluck('id')->map(static fn ($id): int => (int) $id)->all())));
        $missing->delete();

        $written = 0;
        foreach ($positions as $position) {
            /** @var InspectionPosition $position */
            if (! $position->active || $position->interval_months <= 0) {
                InspectionDue::query()->where('inspection_position_id', $position->id)->delete();

                continue;
            }
            $written += $this->rebuildPosition($position);
        }

        return $written;
    }

    private function rebuildPosition(InspectionPosition $position): int
    {
        $interval = (int) $position->interval_months;
        $renewalDays = (int) round(self::RENEWAL_SHARE * $interval * self::MONTH_DAYS);
        $visitDays = min(self::VISIT_DAYS, (int) floor(0.5 * $interval * self::MONTH_DAYS));
        $renewedBy = $position->xl_type === InspectionPosition::TYPE_GOODS && $position->renewed_by_xl_gid !== null
            ? $this->renewalSales((int) $position->renewed_by_xl_gid)
            : [];
        $now = CarbonImmutable::now()->startOfSecond();

        /** @var array<int, array<string, mixed>> $rows klient → wiersz */
        $rows = [];
        /** @var array<int, string> $latestStart klient → początek ostatniej wizyty (także klientów bez wiersza) */
        $latestStart = [];
        foreach ($this->customerEvents((int) $position->xl_gid) as $customer => $events) {
            $visits = $this->visits($events, $visitDays);
            if ($visits === []) {
                continue;
            }
            $last = $visits[count($visits) - 1];
            $latestStart[$customer] = $last['start'];

            $open = [];
            foreach ($visits as $visit) {
                $renewed = CarbonImmutable::parse($visit['start'])->addDays($renewalDays)->toDateString() <= $last['start']
                    || (isset($renewedBy[$customer]) && $renewedBy[$customer] > $visit['start']);
                if (! $renewed) {
                    $open[] = $visit;
                }
            }
            if ($open === []) {
                continue;
            }

            $due = null;
            $openQuantity = 0.0;
            foreach ($open as $visit) {
                $visitDue = CarbonImmutable::parse($visit['start'])->addMonthsNoOverflow($interval)->toDateString();
                $due = $due === null || $visitDue < $due ? $visitDue : $due;
                $openQuantity += $visit['quantity'];
            }
            $documents = array_map(static fn (array $e): array => [
                'number' => $e['number'],
                'issued_on' => $e['issued_on'],
                'quantity' => round($e['quantity'], 3),
            ], array_slice($last['events'], -self::LAST_DOCUMENTS));
            $latestEvent = $last['events'][count($last['events']) - 1];

            $rows[$customer] = [
                'customer_xl_gid' => $customer,
                'inspection_position_id' => (int) $position->id,
                'due_on' => $due,
                'open_count' => count($open),
                'open_quantity' => round($openQuantity, 3),
                'last_on' => $last['start'],
                'last_quantity' => round($last['quantity'], 3),
                'last_net' => round($last['net'], 2),
                'last_documents' => json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'first_on' => $visits[0]['start'],
                'recipient_xl_gid' => $latestEvent['recipient'],
                'location' => $latestEvent['location'],
                'operator_ident' => $latestEvent['operator'],
                'same_nip_newer' => false,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->applyCustomers($rows, $latestStart);
        unset($latestStart);

        return $this->write((int) $position->id, $rows);
    }

    /**
     * Pomija klientów archiwalnych i usuniętych w erp_customers i ustawia same_nip_newer.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $latestStart
     */
    private function applyCustomers(array &$rows, array $latestStart): void
    {
        if ($rows === []) {
            return;
        }
        /** @var array<string, array<int, string>> $byNip NIP (cyfry) → klient → początek ostatniej wizyty */
        $byNip = [];
        /** @var array<int, string> $nipOf */
        $nipOf = [];
        foreach (array_chunk(array_keys($latestStart), self::CHUNK) as $chunk) {
            $customers = DB::table('erp_customers')->whereIn('xl_gid', $chunk)->get(['xl_gid', 'nip', 'archived', 'removed_at']);
            foreach ($customers as $c) {
                $gid = (int) $c->xl_gid;
                if (((bool) $c->archived || $c->removed_at !== null) && isset($rows[$gid])) {
                    unset($rows[$gid]);
                }
                $nip = preg_replace('/\D+/', '', (string) $c->nip) ?? '';
                if (strlen($nip) >= self::NIP_MIN_DIGITS) {
                    $nipOf[$gid] = $nip;
                    $byNip[$nip][$gid] = $latestStart[$gid];
                }
            }
        }
        foreach ($rows as $gid => &$row) {
            $nip = $nipOf[$gid] ?? null;
            if ($nip === null) {
                continue;
            }
            foreach ($byNip[$nip] as $other => $start) {
                if ($other !== $gid && $start > $row['last_on']) {
                    $row['same_nip_newer'] = true;

                    break;
                }
            }
        }
        unset($row);
    }

    /**
     * Zastępuje wiersze pozycji: upsert po kliencie (numer wiersza zostaje), kasowanie klientów bez wiersza.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function write(int $positionId, array $rows): int
    {
        DB::transaction(function () use ($positionId, $rows): void {
            $existing = InspectionDue::query()->where('inspection_position_id', $positionId)->pluck('customer_xl_gid', 'id')
                ->map(static fn ($gid): int => (int) $gid)->all();
            $stale = array_keys(array_filter($existing, static fn (int $gid): bool => ! isset($rows[$gid])));
            foreach (array_chunk($stale, self::CHUNK) as $chunk) {
                InspectionDue::query()->whereIn('id', $chunk)->delete();
            }
            foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
                DB::table('inspection_due')->upsert($chunk, ['customer_xl_gid', 'inspection_position_id'], [
                    'due_on', 'open_count', 'open_quantity', 'last_on', 'last_quantity', 'last_net', 'last_documents', 'first_on',
                    'recipient_xl_gid', 'location', 'operator_ident', 'same_nip_newer', 'computed_at', 'updated_at',
                ]);
            }
        });

        return count($rows);
    }

    /**
     * Wizyty klienta z jego zdarzeń (posortowanych po dacie).
     *
     * @param  list<array{key: string, date: string, document_id: int, quantity: float, net: float, number: string, issued_on: string, line: int, recipient: int|null, location: string|null, operator: string|null}>  $events
     * @return list<array{start: string, quantity: float, net: float, events: list<array<string, mixed>>}>
     */
    private function visits(array $events, int $visitDays): array
    {
        $visits = [];
        $current = null;
        $limit = '';
        foreach ($events as $event) {
            if ($current !== null && $event['date'] <= $limit) {
                $current['quantity'] += $event['quantity'];
                $current['net'] += $event['net'];
                $current['events'][] = $event;

                continue;
            }
            if ($current !== null) {
                $visits[] = $current;
            }
            $current = ['start' => $event['date'], 'quantity' => $event['quantity'], 'net' => $event['net'], 'events' => [$event]];
            $limit = CarbonImmutable::parse($event['date'])->addDays($visitDays)->toDateString();
        }
        if ($current !== null) {
            $visits[] = $current;
        }

        return array_values(array_filter($visits, static fn (array $v): bool => round($v['quantity'], 3) > 0));
    }

    /**
     * Klient → data ostatniej sprzedaży usługi odnawiającej (ilość po korektach > 0).
     *
     * @return array<int, string>
     */
    private function renewalSales(int $serviceGid): array
    {
        if (! isset($this->renewalCache[$serviceGid])) {
            $latest = [];
            foreach ($this->customerEvents($serviceGid) as $customer => $events) {
                if ($events !== []) {
                    $latest[$customer] = $events[count($events) - 1]['date'];
                }
            }
            $this->renewalCache[$serviceGid] = $latest;
        }

        return $this->renewalCache[$serviceGid];
    }

    /**
     * Zdarzenia pozycji XL na klienta: strumień wierszy faktur (kursor, po kliencie), w pamięci jeden klient naraz.
     *
     * @return Generator<int, list<array{key: string, date: string, document_id: int, quantity: float, net: float, number: string, issued_on: string, line: int, recipient: int|null, location: string|null, operator: string|null}>>
     */
    private function customerEvents(int $xlGid): Generator
    {
        $cursor = DB::table('inspection_sale_lines')
            ->where('xl_item_gid', $xlGid)
            ->orderBy('customer_xl_gid')
            ->orderBy('issued_on')
            ->orderBy('id')
            ->select([
                'id', 'document_type', 'document_id', 'line', 'document_number', 'invoice_number', 'issued_on', 'sold_on', 'customer_xl_gid',
                'recipient_xl_gid', 'quantity', 'net_value', 'location', 'operator_ident', 'corrects_document_type',
                'corrects_document_id',
            ])
            ->cursor();

        $customer = null;
        $lines = [];
        foreach ($cursor as $line) {
            $gid = (int) $line->customer_xl_gid;
            if ($customer !== null && $gid !== $customer) {
                yield $customer => $this->events($lines);
                $lines = [];
            }
            $customer = $gid;
            $lines[] = $line;
        }
        if ($customer !== null) {
            yield $customer => $this->events($lines);
        }
    }

    /**
     * Zdarzenia (dokumenty z korektami) jednego klienta z jego wierszy faktur posortowanych po dacie wystawienia;
     * tylko z ilością po korektach > 0, po dacie zdarzenia.
     *
     * @param  list<object>  $lines
     * @return list<array{key: string, date: string, document_id: int, quantity: float, net: float, number: string, issued_on: string, line: int, recipient: int|null, location: string|null, operator: string|null}>
     */
    private function events(array $lines): array
    {
        $events = [];
        /** @var array<string, string|null> $root dokument → zdarzenie, do którego się dolicza (null = brak oryginału) */
        $root = [];
        $corrections = [];
        foreach ($lines as $l) {
            $key = $l->document_type.':'.$l->document_id;
            if (in_array((int) $l->document_type, self::CORRECTION_TYPES, true)) {
                $corrections[] = $l;

                continue;
            }
            $issued = substr((string) $l->issued_on, 0, 10);
            if (! isset($events[$key])) {
                $events[$key] = [
                    'key' => $key,
                    'date' => $l->sold_on !== null && $l->sold_on !== '' ? substr((string) $l->sold_on, 0, 10) : $issued,
                    'document_id' => (int) $l->document_id,
                    'quantity' => 0.0,
                    'net' => 0.0,
                    // WZ z fakturą w spinaczu: „WZ-… (faktura FS-…)” — handlowiec szuka po numerze faktury
                    'number' => $l->invoice_number !== null && $l->invoice_number !== ''
                        ? $l->document_number.' (faktura '.$l->invoice_number.')'
                        : (string) $l->document_number,
                    'issued_on' => $issued,
                    'line' => -1,
                    'recipient' => null,
                    'location' => null,
                    'operator' => null,
                ];
                $root[$key] = $key;
            }
            $events[$key]['quantity'] += (float) $l->quantity;
            $events[$key]['net'] += (float) $l->net_value;
            // odbiorca, oddział i operator z ostatniego wiersza dokumentu
            if ((int) $l->line > $events[$key]['line']) {
                $events[$key]['line'] = (int) $l->line;
                $events[$key]['recipient'] = $l->recipient_xl_gid !== null ? (int) $l->recipient_xl_gid : null;
                $events[$key]['location'] = $l->location !== null ? (string) $l->location : null;
                $events[$key]['operator'] = $l->operator_ident !== null ? (string) $l->operator_ident : null;
            }
        }
        // korekty w kolejności wystawienia — korekta korekty trafia do tego samego oryginału
        foreach ($corrections as $c) {
            $key = $c->document_type.':'.$c->document_id;
            if (! array_key_exists($key, $root)) {
                $target = $c->corrects_document_type !== null && $c->corrects_document_id !== null
                    ? $c->corrects_document_type.':'.$c->corrects_document_id
                    : null;
                $root[$key] = $target !== null ? ($root[$target] ?? null) : null;
            }
            $target = $root[$key];
            if ($target === null) {
                continue;
            }
            $events[$target]['quantity'] += (float) $c->quantity;
            $events[$target]['net'] += (float) $c->net_value;
        }

        $events = array_values(array_filter($events, static fn (array $e): bool => round($e['quantity'], 3) > 0));
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['document_id']] <=> [$b['date'], $b['document_id']]);

        return $events;
    }
}
