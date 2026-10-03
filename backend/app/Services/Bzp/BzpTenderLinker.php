<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\TenderActivityLogger;
use App\Services\Tenders\CompetitorRegistry;
use App\Services\Tenders\TenderResultService;
use App\Services\Tenders\TenderResultStatus;
use App\Support\NoticeNumber;
use App\Support\PolishTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Łączenie przetargów z ogłoszeniami zapisanymi w bazie (bez zapytań do Biuletynu):
 * numer ogłoszenia przetargu → ogłoszenie o zamówieniu (ten sam numer bez wersji) → ogłoszenia o wyniku
 * (numer ogłoszenia poprzedzającego albo ten sam identyfikator postępowania) → części przetargu.
 *
 * Co wypełnia Biuletyn (TenderLot::BZP_FIELDS): nazwę, główny kod CPV i wartość części z ogłoszenia o zamówieniu;
 * zwycięzcę, jego cenę, walutę, liczbę ofert, najniższą i najwyższą cenę z ogłoszenia o wyniku. Wynik części
 * ustawia sam tylko „wygrana” (zwycięzca = nasza firma, OurCompany) i „unieważniona”; gdy wygrała inna firma,
 * wynik zostaje pusty — „przegrana” albo „nie złożyliśmy oferty” wybiera opiekun.
 *
 * Nigdy: pola z manual_fields (wpisane przez człowieka), powód przegranej, notatka, nasza cena; nie czyści
 * wartości, których ogłoszenie nie podaje. Sprzeczność ogłoszenia z wpisem człowieka → komunikat w bzp_conflict
 * odpowiedzi (nie zapisywany). Wynik „wygrana”/„unieważniona” ustawiony wcześniej przez Biuletyn (nie ręcznie)
 * jest cofany do pustego, gdy nowsze ogłoszenie mówi inaczej (wygrała inna firma albo brak rozstrzygnięcia).
 *
 * Które części dostają dane z ogłoszenia (część zamówienia = numer części w ogłoszeniu):
 * - części powiązane już z ogłoszeniem tego postępowania (bzp_notice_id) — po numerze części;
 * - część założona ręcznie (bez bzp_notice_id) tylko wtedy, gdy jej numer na pewno odpowiada ogłoszeniu:
 *   ogłoszenie ma jedną część, albo numer części jest inny niż 1, albo przetarg ma więcej niż jedną część.
 *   Samotna część nr 1 to domyślna część „cały przetarg” (zapis części wirtualnej przed znalezieniem ogłoszenia) —
 *   przy ogłoszeniu z wieloma częściami nie wiadomo, której części dotyczy, więc dane nie są wpisywane, a w
 *   bzp_conflict i bzp_message jest prośba o ustawienie numeru części zgodnie z ogłoszeniem.
 * - Części zakładane są tylko przy pierwszym powiązaniu przetargu z ogłoszeniem (wcześniej bez ogłoszenia
 *   o zamówieniu i o wyniku) i tylko w przetargu bez żadnej części — część usunięta przez człowieka nie wraca.
 *
 * Pusta godzina składania ofert jest uzupełniana z ogłoszenia o zamówieniu, gdy zgadza się dzień terminu (wpis
 * w historii przetargu). Wiersz przetargu jest blokowany na czas zapisu (równoległy zapis wyniku przez człowieka).
 */
final class BzpTenderLinker
{
    private const NOTICE_COLUMNS = [
        'id', 'notice_type', 'notice_number', 'bzp_number', 'ocds_id', 'preceding_bzp_number', 'object_id',
        'published_at', 'submitting_offers_at', 'parsed',
    ];

    private const OUTCOME_LABELS = [
        TenderLot::OUTCOME_WON => 'wygrana',
        TenderLot::OUTCOME_LOST => 'przegrana',
        TenderLot::OUTCOME_CANCELLED => 'unieważniona',
        TenderLot::OUTCOME_NOT_SUBMITTED => 'nie złożyliśmy oferty',
    ];

    public function __construct(
        private readonly CompetitorRegistry $competitors,
        private readonly TenderActivityLogger $activities,
    ) {}

    /**
     * Wszystkie przetargi z numerem ogłoszenia Biuletynu (porcjami — serwer CLI ma 128 MB). Błąd jednego przetargu
     * nie przerywa pozostałych: trafia do dziennika błędów i do licznika `failed` (polecenie kończy się wtedy błędem).
     *
     * @return array{tenders: int, linked: int, changed_lots: int, failed: int}
     */
    public function linkAll(): array
    {
        $stats = ['tenders' => 0, 'linked' => 0, 'changed_lots' => 0, 'failed' => 0];
        Tender::query()
            ->whereNotNull('notice_number')
            ->where('notice_number', 'like', '%BZP%')
            ->select(['id'])
            ->chunkById(100, function (Collection $chunk) use (&$stats): void {
                foreach ($chunk as $row) {
                    $tender = Tender::query()->find($row->id);
                    if ($tender === null) {
                        continue;
                    }
                    $stats['tenders']++;
                    try {
                        $result = $this->link($tender);
                        $stats['linked'] += $result['linked'] ? 1 : 0;
                        $stats['changed_lots'] += $result['changed_lots'];
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        report($e);
                    }
                    unset($tender, $result);
                }
            });

        return $stats;
    }

    /**
     * Powiązanie jednego przetargu; zwraca komunikat dla człowieka (bzp_message).
     */
    public function linkTender(Tender $tender, ?User $user = null): string
    {
        return $this->link($tender, $user)['message'];
    }

    /**
     * @return array{message: string, conflicts: array<int, string>, changed_lots: int, linked: bool}
     */
    public function link(Tender $tender, ?User $user = null): array
    {
        $number = NoticeNumber::parse($tender->notice_number);
        if ($number === null) {
            $message = trim((string) $tender->notice_number) === ''
                ? 'Przetarg nie ma numeru ogłoszenia. Wpisz numer ogłoszenia z Biuletynu Zamówień Publicznych (np. 2026/BZP 00431178/01) i sprawdź ponownie.'
                : 'Numer ogłoszenia „'.$tender->notice_number.'” nie jest numerem z Biuletynu Zamówień Publicznych.';

            return ['message' => $message, 'conflicts' => [], 'changed_lots' => 0, 'linked' => false];
        }
        if ($number['source'] !== NoticeNumber::SOURCE_BZP || $number['bzp_number'] === null) {
            return [
                'message' => 'To numer ogłoszenia z Dziennika Urzędowego Unii Europejskiej. Wyniki z tego dziennika nie są jeszcze pobierane — wpisz wynik ręcznie.',
                'conflicts' => [],
                'changed_lots' => 0,
                'linked' => false,
            ];
        }

        $bzpNumber = $number['bzp_number'];
        $contract = $this->contractNotice($bzpNumber, $number['normalized']);
        $results = $this->resultNotices($bzpNumber, $contract?->ocds_id);

        $numberSeen = $tender->notice_number;

        return DB::transaction(function () use ($tender, $user, $contract, $results, $bzpNumber, $numberSeen): array {
            // równoległy zapis wyniku przez człowieka (TenderResultService) czeka na koniec tej transakcji
            TenderResultService::lockTender($tender);
            if ($tender->notice_number !== $numberSeen) {
                // numer ogłoszenia zmieniono w trakcie — ogłoszenia wyszukane dla starego numeru nie dotyczą przetargu
                return [
                    'message' => 'Numer ogłoszenia przetargu zmienił się w trakcie sprawdzania. Sprawdź ponownie.',
                    'conflicts' => [],
                    'changed_lots' => 0,
                    'linked' => false,
                ];
            }
            $statusBefore = $tender->result_status;
            // pierwsze powiązanie z ogłoszeniem tego numeru (zmiana numeru ogłoszenia zeruje oba powiązania)
            $firstLink = $tender->contract_notice_id === null && $tender->result_notice_id === null;
            $lotData = $this->lotData($contract, $results);
            $noticeIds = array_map('intval', array_values(array_filter([$contract?->id, ...$results->pluck('id')->all()])));
            /** @var Collection<int, TenderLot> $existing */
            $existing = $tender->lots()->lockForUpdate()->get()->keyBy(static fn (TenderLot $lot): int => (int) $lot->lot_no);
            $createMissing = $firstLink && $existing->isEmpty();
            $partsCount = count($lotData);

            $log = [];
            $conflicts = [];
            $unnumbered = [];
            foreach ($lotData as $lotNo => $data) {
                $lot = $existing->get($lotNo);
                if ($lot === null) {
                    if (! $createMissing) {
                        continue;
                    }
                    $lot = new TenderLot(['tender_id' => $tender->id, 'lot_no' => $lotNo, 'currency' => 'PLN']);
                } elseif (! $this->matchesNotice($lot, $noticeIds, $partsCount, $existing->count())) {
                    $unnumbered[$lotNo] = 'Ogłoszenie ma '.$partsCount.' części, a ta część została założona, '
                        .'zanim przetarg powiązano z ogłoszeniem — nie wiadomo, której części ogłoszenia dotyczy, więc dane '
                        .'z ogłoszenia nie zostały wpisane. Ustaw numer części zgodnie z ogłoszeniem, a wynik uzupełni się sam.';

                    continue;
                }
                [$changed, $conflict] = $this->applyLot($lot, $data);
                if ($conflict !== null) {
                    $conflicts[$lotNo] = $conflict;
                }
                if ($changed !== [] || ! $lot->exists) {
                    $created = ! $lot->exists;
                    $lot->bzp_notice_id = $data['notice_id'];
                    $lot->bzp_applied_at = now();
                    $lot->save();
                    $log[] = ['lot_no' => $lotNo, 'created' => $created, 'fields' => $changed, 'offers_changed' => false];
                }
            }

            $latestResult = $results->last();
            $timeBefore = $tender->deadline_time;
            $timeFilled = $this->fillDeadlineTime($tender, $contract);
            $tender->forceFill([
                'contract_notice_id' => $contract?->id ?? $tender->contract_notice_id,
                'result_notice_id' => $latestResult?->id ?? $tender->result_notice_id,
                'bzp_checked_at' => now(),
            ])->save();

            if ($timeFilled !== null) {
                $this->activities->log($tender, 'updated', $user, null, [
                    'source' => 'bzp',
                    'notice_number' => $contract?->notice_number,
                    'before' => ['deadline_time' => $timeBefore],
                    'after' => ['deadline_time' => $timeFilled],
                ]);
            }

            $statusAfter = $log !== [] ? TenderResultStatus::recompute($tender) : $tender->result_status;
            if ($log !== []) {
                $this->activities->log($tender, 'result_updated', $user, null, [
                    'source' => 'bzp',
                    'notice_number' => $latestResult?->notice_number ?? $contract?->notice_number,
                    'lots' => $log,
                    'result_status_before' => $statusBefore,
                    'result_status_after' => $statusAfter,
                ]);
            }

            return [
                'message' => $this->message($bzpNumber, $contract, $latestResult, count($log), $conflicts, $timeFilled, $unnumbered, $partsCount),
                // per część: sprzeczność z wpisem ręcznym albo prośba o numer części (do bzp_conflict odpowiedzi)
                'conflicts' => $conflicts + $unnumbered,
                'changed_lots' => count($log),
                'linked' => $contract !== null || $latestResult !== null,
            ];
        });
    }

    /**
     * Czy część przetargu odpowiada części ogłoszenia o tym samym numerze: powiązana już z ogłoszeniem tego
     * postępowania albo założona ręcznie z numerem, który na pewno pochodzi z ogłoszenia (patrz opis klasy).
     *
     * @param  list<int>  $noticeIds  ogłoszenia tego postępowania (o zamówieniu i o wyniku)
     */
    private function matchesNotice(TenderLot $lot, array $noticeIds, int $partsCount, int $tenderLots): bool
    {
        if ($lot->bzp_notice_id !== null && in_array((int) $lot->bzp_notice_id, $noticeIds, true)) {
            return true;
        }

        return $partsCount === 1 || (int) $lot->lot_no !== 1 || $tenderLots > 1;
    }

    private function contractNotice(string $bzpNumber, string $normalized): ?ProcurementNotice
    {
        $notices = ProcurementNotice::query()
            ->where('notice_type', ProcurementNotice::TYPE_CONTRACT)
            ->where('bzp_number', $bzpNumber)
            ->get(self::NOTICE_COLUMNS);

        // dokładnie ten numer z wersją, inaczej najnowsza wersja
        return $notices->firstWhere('notice_number', $normalized)
            ?? $notices->sortByDesc('notice_number')->first();
    }

    /**
     * @return Collection<int, ProcurementNotice> od najstarszego
     */
    private function resultNotices(string $bzpNumber, ?string $ocdsId): Collection
    {
        return ProcurementNotice::query()
            ->where('notice_type', ProcurementNotice::TYPE_RESULT)
            ->where(static function ($query) use ($bzpNumber, $ocdsId): void {
                $query->where('preceding_bzp_number', $bzpNumber)
                    // numer ogłoszenia o wyniku wpisany zamiast numeru ogłoszenia o zamówieniu
                    ->orWhere('bzp_number', $bzpNumber);
                if ($ocdsId !== null && $ocdsId !== '') {
                    $query->orWhere('ocds_id', $ocdsId);
                }
            })
            ->orderBy('published_at')
            ->orderBy('id')
            ->get(self::NOTICE_COLUMNS);
    }

    /**
     * Dane części: ogłoszenie o zamówieniu (nazwa, kod, wartość), a na nie kolejne ogłoszenia o wyniku
     * (późniejsze nadpisują wcześniejsze w tej samej części).
     *
     * @param  Collection<int, ProcurementNotice>  $results
     * @return array<int, array{info: ?array<string, mixed>, result: ?array<string, mixed>, notice_id: int, result_notice_number: ?string}>
     */
    private function lotData(?ProcurementNotice $contract, Collection $results): array
    {
        $data = [];
        foreach ($this->parsedLots($contract) as $lot) {
            $data[$lot['lot_no']] = ['info' => $lot, 'result' => null, 'notice_id' => (int) $contract?->id, 'result_notice_number' => null];
        }
        foreach ($results as $notice) {
            foreach ($this->parsedLots($notice) as $lot) {
                $no = $lot['lot_no'];
                $hasResult = $lot['result'] !== null || $lot['offers_count'] !== null || $lot['winner_price'] !== null || $lot['contractors'] !== [];
                if (! isset($data[$no])) {
                    $data[$no] = ['info' => $lot, 'result' => null, 'notice_id' => (int) $notice->id, 'result_notice_number' => null];
                }
                if ($hasResult) {
                    $data[$no]['result'] = $lot;
                    $data[$no]['notice_id'] = (int) $notice->id;
                    $data[$no]['result_notice_number'] = (string) $notice->notice_number;
                }
            }
        }
        ksort($data);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parsedLots(?ProcurementNotice $notice): array
    {
        $parsed = $notice?->parsed;

        return is_array($parsed) && isset($parsed['lots']) && is_array($parsed['lots'])
            ? array_values(array_filter($parsed['lots'], static fn (mixed $lot): bool => is_array($lot) && isset($lot['lot_no'])))
            : [];
    }

    /**
     * @param  array{info: ?array<string, mixed>, result: ?array<string, mixed>, notice_id: int, result_notice_number: ?string}  $data
     * @return array{0: list<string>, 1: ?string} zmienione pola i sprzeczność z wpisem człowieka
     */
    private function applyLot(TenderLot $lot, array $data): array
    {
        $manual = is_array($lot->manual_fields) ? $lot->manual_fields : [];
        $changed = [];
        $set = static function (string $field, string $attribute, mixed $value) use ($lot, $manual, &$changed): void {
            if ($value === null || in_array($field, $manual, true)) {
                return;
            }
            $current = $lot->getAttribute($attribute);
            if ($current !== null && (string) $current === (string) $value) {
                return;
            }
            $lot->setAttribute($attribute, $value);
            if (! in_array($field, $changed, true)) {
                $changed[] = $field;
            }
        };

        $info = $data['info'];
        if ($info !== null) {
            $set('name', 'name', isset($info['name']) ? mb_substr((string) $info['name'], 0, 500) : null);
            $set('cpv_main', 'cpv_main', $info['cpv_main'] ?? null);
            $value = $info['estimated_value'] ?? null;
            if (is_array($value) && in_array($value['currency'] ?? null, [null, 'PLN'], true)) {
                $set('estimated_value', 'estimated_value', $value['amount']);
            }
        }

        $result = $data['result'];
        if ($result === null) {
            return [$changed, null];
        }
        $noticeLabel = 'Według ogłoszenia o wyniku '.$data['result_notice_number'];
        $conflicts = [];

        // ceny w jednej walucie — kwota w innej walucie niż pozostałe nie trafia do części
        $prices = ['winner_price' => $result['winner_price'] ?? null, 'lowest_price' => $result['lowest_price'] ?? null, 'highest_price' => $result['highest_price'] ?? null];
        $currency = null;
        foreach ($prices as $price) {
            if (is_array($price) && ($price['currency'] ?? null) !== null) {
                $currency = $price['currency'];
                break;
            }
        }
        $currencyBlocked = $currency !== null && in_array('currency', $manual, true) && $lot->currency !== $currency;
        if ($currency !== null && ! $currencyBlocked) {
            $set('currency', 'currency', $currency);
            foreach ($prices as $field => $price) {
                if (is_array($price) && ($price['currency'] ?? null) === $currency) {
                    $set($field, $field, $price['amount']);
                }
            }
        }
        $set('offers_count', 'offers_count', $result['offers_count'] ?? null);

        $contractors = array_values(array_filter($result['contractors'] ?? [], 'is_array'));
        $ours = null;
        foreach ($contractors as $contractor) {
            if (OurCompany::matches($contractor['name'] ?? null, $contractor['national_id_raw'] ?? null)) {
                $ours = $contractor;
                break;
            }
        }
        $winner = $ours ?? ($contractors[0] ?? null);
        $winnerName = null;
        if ($winner !== null) {
            $competitor = $this->competitors->resolve($winner['name'] ?? null, $winner['national_id_raw'] ?? null);
            $winnerName = $competitor?->name ?? ($winner['name'] ?? $winner['national_id_raw'] ?? null);
            $raw = ($winner['nip'] ?? null) === null ? ($winner['national_id_raw'] ?? null) : null;
            if (in_array('winner', $manual, true)) {
                if ($competitor !== null && $lot->winner_competitor_id !== null && (int) $lot->winner_competitor_id !== (int) $competitor->id) {
                    $conflicts[] = $noticeLabel.' zwycięzcą tej części jest '.$competitor->name.', a wpisano inną firmę.';
                }
            } elseif ($competitor !== null || $raw !== null) {
                if ((int) $lot->winner_competitor_id !== (int) $competitor?->id || $lot->winner_national_id_raw !== $raw) {
                    $lot->winner_competitor_id = $competitor?->id;
                    $lot->winner_national_id_raw = $raw !== null ? mb_substr((string) $raw, 0, 40) : null;
                    $changed[] = 'winner';
                }
            }
        }

        $winnerPrice = $prices['winner_price'];
        if (in_array('winner_price', $manual, true) && is_array($winnerPrice) && $lot->winner_price !== null
            && (string) $lot->winner_price !== (string) $winnerPrice['amount']) {
            $conflicts[] = $noticeLabel.' cena zwycięzcy to '.self::money($winnerPrice['amount'], $winnerPrice['currency']).', a wpisano '.self::money((string) $lot->winner_price, $lot->currency).'.';
        }

        // wynik części: Biuletyn ustawia sam tylko „wygrana” i „unieważniona”
        $state = match (true) {
            $result['result'] === BzpNoticeParser::RESULT_CANCELLED => TenderLot::OUTCOME_CANCELLED,
            $ours !== null => TenderLot::OUTCOME_WON,
            $result['result'] === BzpNoticeParser::RESULT_AWARDED && $winner !== null => 'other',
            default => null,
        };
        $current = $lot->outcome;
        if (in_array('outcome', $manual, true)) {
            $label = self::OUTCOME_LABELS[$current] ?? null;
            if ($state === TenderLot::OUTCOME_WON && $current !== TenderLot::OUTCOME_WON && $label !== null) {
                $conflicts[] = $noticeLabel.' tę część wygrała nasza firma, a wpisano wynik „'.$label.'”.';
            } elseif ($state === TenderLot::OUTCOME_CANCELLED && $current !== TenderLot::OUTCOME_CANCELLED && $label !== null) {
                $conflicts[] = $noticeLabel.' ta część została unieważniona, a wpisano wynik „'.$label.'”.';
            } elseif ($state === 'other' && $current === TenderLot::OUTCOME_WON) {
                $conflicts[] = $noticeLabel.' tę część wygrała firma '.($winnerName ?? 'spoza naszej firmy').', a wpisano wynik „wygrana”.';
            } elseif ($state === 'other' && $current === TenderLot::OUTCOME_CANCELLED) {
                $conflicts[] = $noticeLabel.' w tej części zawarto umowę, a wpisano wynik „unieważniona”.';
            }
        } elseif (in_array($state, [TenderLot::OUTCOME_WON, TenderLot::OUTCOME_CANCELLED], true)) {
            if ($current !== $state) {
                $lot->outcome = $state;
                $lot->loss_reason = null;
                $lot->decided_by = null;
                $lot->decided_at = now();
                $changed[] = 'outcome';
            }
        } elseif (in_array($current, [TenderLot::OUTCOME_WON, TenderLot::OUTCOME_CANCELLED], true)) {
            // „wygrana”/„unieważniona” wpisał wcześniej Biuletyn (wynik nie jest ręczny), a nowsze ogłoszenie albo
            // ponowny odczyt mówi inaczej: wygrała inna firma albo brak rozstrzygnięcia — wynik wraca do opiekuna
            $lot->outcome = null;
            $lot->decided_by = null;
            $lot->decided_at = null;
            $changed[] = 'outcome';
        }

        return [$changed, $conflicts === [] ? null : implode(' ', $conflicts)];
    }

    /**
     * Pusta godzina składania ofert z ogłoszenia o zamówieniu — tylko gdy dzień terminu w przetargu jest ten sam.
     */
    private function fillDeadlineTime(Tender $tender, ?ProcurementNotice $contract): ?string
    {
        if ($contract?->submitting_offers_at === null || $tender->deadline_time !== null || $tender->deadline === null) {
            return null;
        }
        $local = $contract->submitting_offers_at->copy()->setTimezone(PolishTime::TIMEZONE);
        if ($local->format('Y-m-d') !== $tender->deadline->format('Y-m-d')) {
            return null;
        }
        $tender->deadline_time = $local->format('H:i');

        return $local->format('H:i');
    }

    /**
     * @param  array<int, string>  $conflicts
     * @param  array<int, string>  $unnumbered  części założone ręcznie, których numeru nie da się odnieść do ogłoszenia
     */
    private function message(string $bzpNumber, ?ProcurementNotice $contract, ?ProcurementNotice $result, int $changedLots, array $conflicts, ?string $timeFilled, array $unnumbered, int $partsCount): string
    {
        if ($contract === null && $result === null) {
            return 'Ogłoszenia '.$bzpNumber.' nie ma w ogłoszeniach pobranych z Biuletynu Zamówień Publicznych. '
                .'Aplikacja pobiera codziennie ogłoszenia z ostatnich '.(int) config('bzp.days', 7).' dni z kodami CPV odzieży, obuwia, rękawic '
                .'i sprzętu ochronnego — ogłoszenie z innymi kodami albo starsze trzeba uzupełnić ręcznie.';
        }

        $parts = [];
        if ($result !== null) {
            $parts[] = 'Ogłoszenie o wyniku '.$result->notice_number.' z '.PolishTime::format($result->published_at, false).'.';
        } else {
            $parts[] = 'Znaleziono ogłoszenie o zamówieniu '.$contract?->notice_number.' z '.PolishTime::format($contract?->published_at, false)
                .'. Ogłoszenia o wyniku jeszcze nie ma — aplikacja sprawdza Biuletyn codziennie.';
        }
        if ($changedLots > 0) {
            $parts[] = 'Uzupełniono części zamówienia: '.$changedLots.'.';
        } elseif ($unnumbered === []) {
            $parts[] = 'Dane części są aktualne.';
        }
        if ($timeFilled !== null) {
            $parts[] = 'Uzupełniono godzinę składania ofert: '.$timeFilled.'.';
        }
        if ($conflicts !== []) {
            $parts[] = 'Uwaga: w częściach '.implode(', ', array_keys($conflicts)).' ogłoszenie nie zgadza się z wpisem ręcznym — wpis ręczny został bez zmian.';
        }
        if ($unnumbered !== []) {
            $parts[] = 'Ogłoszenie ma '.$partsCount.' części. Ustaw numer części zgodnie z ogłoszeniem, a wynik uzupełni się sam.';
        }

        return implode(' ', $parts);
    }

    private static function money(string $amount, ?string $currency): string
    {
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $integer);
        $unit = $currency === null || $currency === 'PLN' ? 'zł' : $currency;

        return $grouped.','.str_pad(substr($fraction, 0, 2), 2, '0').' '.$unit;
    }
}
