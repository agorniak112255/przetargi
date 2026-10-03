<?php

declare(strict_types=1);

namespace App\Services\Tenders;

use App\Models\Competitor;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\TenderLotOffer;
use App\Models\User;
use App\Services\Bzp\BzpTenderLinker;
use App\Services\TenderActivityLogger;
use App\Support\CompanyName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Wynik przetargu per część zamówienia (GET/PUT /tenders/{tender}/result, DELETE …/result/lots/{lot}).
 *
 * - Przetarg bez zapisanych części dostaje w odpowiedzi część wirtualną {id: null, lot_no: 1}; powstaje ona
 *   przy pierwszym zapisie.
 * - Zapis to upsert po id albo po numerze części; części nieobecne w żądaniu zostają bez zmian (usuwa się je
 *   osobno). Pominięte pole = bez zmian, null = wyczyść.
 * - Pole, którego wartość człowiek zmienił, trafia do manual_fields (nazwy jak TenderLot::BZP_FIELDS plus pola
 *   tylko ręczne) — Biuletyn go potem nie nadpisuje. Wysłanie tej samej wartości nie jest zmianą.
 * - lot_no_confirmed: true = człowiek potwierdza, że numer części to numer części z ogłoszenia (wpis
 *   TenderLot::LOT_NO_CONFIRMED w manual_fields); false albo zmiana numeru bez potwierdzenia — wpis znika.
 * - Zmiana numeru części powiązanej z ogłoszeniem czyści jej dane z Biuletynu (dotyczyły poprzedniej części
 *   ogłoszenia); wpisy człowieka zostają. Następne łączenie wpisze dane części o nowym numerze.
 * - Odczyt pokazuje prośbę o numer części (bzp_conflict) przy samotnej ręcznej części nr 1 i ogłoszeniu
 *   wieloczęściowym także bez „Sprawdź w Biuletynie”.
 * - Oferty innych firm z żądania zastępują listę części w całości (ta sama firma = ten sam wiersz).
 * - Powód przegranej tylko przy wyniku „przegrana”; zmiana wyniku na inny czyści zapisany powód.
 * - Brutto = netto × (1 + VAT/100), liczone jawnie w groszach (zaokrąglenie do grosza), nigdy zapisywane.
 * - Różnica do zwycięzcy (price_gap) porównuje naszą cenę brutto z ceną zwycięzcy z ogłoszenia, przyjętą jako
 *   cena brutto (ogłoszenie o wyniku podaje cenę oferty; nie zawsze mówi wprost, czy z VAT) — tylko w złotych,
 *   bez przeliczania walut; procent od naszej ceny.
 * - Zapis wyniku blokuje wiersz przetargu (lockTender) — równoległe łączenie z Biuletynem czeka.
 */
final class TenderResultService
{
    /** Pola części, które może zmienić człowiek (nazwy jak w API części). */
    public const EDITABLE_FIELDS = [
        'name',
        'cpv_main',
        'estimated_value',
        'our_net',
        'our_vat_rate',
        'outcome',
        'winner',
        'winner_price',
        'currency',
        'offers_count',
        'lowest_price',
        'highest_price',
        'loss_reason',
        'note',
    ];

    /** Kolejność wpisów manual_fields: pola edytowalne, potem potwierdzenie numeru części. */
    private const MANUAL_FIELDS_ORDER = [...self::EDITABLE_FIELDS, TenderLot::LOT_NO_CONFIRMED];

    public const MAX_LOTS = 200;

    public const MAX_OFFERS = 100;

    private const AMOUNT_FIELDS = ['estimated_value', 'our_net', 'winner_price', 'lowest_price', 'highest_price'];

    /** kwota: do 12 cyfr przed przecinkiem i najwyżej 2 po (kolumny decimal(14,2)) — bez cichego zaokrąglania */
    private const AMOUNT_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    private const VAT_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    /** adres ogłoszenia na stronie Biuletynu (objectId z API) */
    private const BZP_NOTICE_URL = 'https://ezamowienia.gov.pl/mo-client-board/bzp/notice-details/id/';

    public function __construct(
        private readonly CompetitorRegistry $competitors,
        private readonly TenderActivityLogger $activities,
    ) {}

    /**
     * Odpowiedź TenderResultResponse.
     *
     * @param  array<int, string>  $bzpConflicts  sprzeczności Biuletynu z wpisem człowieka po numerze części
     *                                            (wypełnia sprawdzenie w Biuletynie; nie są zapisywane w bazie)
     * @return array<string, mixed>
     */
    public function payload(Tender $tender, User $user, array $bzpConflicts = []): array
    {
        $tender->load([
            'contractNotice:id,notice_number,published_at,object_id',
            'resultNotice:id,notice_number,published_at,object_id',
        ]);

        $lots = $tender->lots()
            ->with([
                'winner:id,name,nip',
                'offers.competitor:id,name,nip',
                'decidedBy:id,name',
                'bzpNotice:id,notice_number',
            ])
            ->get();

        // prośba o numer części widoczna także bez „Sprawdź w Biuletynie” (ten sam tekst; jeden komunikat na część)
        $bzpConflicts += $this->unnumberedLotConflict($tender, $lots);
        $rows = $lots->isEmpty()
            ? [$this->virtualLotRow(1)]
            : $lots->map(fn (TenderLot $lot): array => $this->lotRow($lot, $bzpConflicts[(int) $lot->lot_no] ?? null))->all();

        return [
            'tender_id' => (int) $tender->id,
            'result_status' => $tender->result_status,
            'can_edit' => $user->can('tenders.edit_offer'),
            'notice_number' => $tender->notice_number,
            'notice_source' => $tender->notice_source,
            'bzp' => [
                'contract_notice' => $this->noticeRef($tender->contractNotice),
                'result_notice' => $this->noticeRef($tender->resultNotice),
                'checked_at' => $tender->bzp_checked_at?->toIso8601String(),
            ],
            'lots' => array_values($rows),
        ];
    }

    /**
     * Samotna część nr 1 założona ręcznie przy przetargu powiązanym z ogłoszeniem wieloczęściowym — Biuletyn nie
     * wpisuje do niej danych, dopóki człowiek nie ustawi numeru części albo go nie potwierdzi. Reguła jak przy
     * łączeniu (BzpTenderLinker::lotMatchesNotice); ogłoszenia wczytywane tylko w tym jednym przypadku, liczba
     * części z ogłoszenia o zamówieniu i ostatniego ogłoszenia o wyniku powiązanych z przetargiem.
     *
     * @param  Collection<int, TenderLot>  $lots
     * @return array<int, string> po numerze części
     */
    private function unnumberedLotConflict(Tender $tender, Collection $lots): array
    {
        if ($lots->count() !== 1) {
            return [];
        }
        /** @var TenderLot $lot */
        $lot = $lots->first();
        $manual = is_array($lot->manual_fields) ? $lot->manual_fields : [];
        $noticeIds = array_values(array_unique(array_map('intval', array_filter([$tender->contract_notice_id, $tender->result_notice_id]))));
        if ((int) $lot->lot_no !== 1 || $lot->bzp_notice_id !== null || in_array(TenderLot::LOT_NO_CONFIRMED, $manual, true) || $noticeIds === []) {
            return [];
        }

        $numbers = [];
        foreach (ProcurementNotice::query()->whereKey($noticeIds)->get(['id', 'parsed']) as $notice) {
            foreach (BzpTenderLinker::parsedLots($notice) as $part) {
                $numbers[(int) $part['lot_no']] = true;
            }
        }
        if ($numbers === [] || BzpTenderLinker::lotMatchesNotice($lot, $noticeIds, count($numbers), 1)) {
            return [];
        }

        return [1 => BzpTenderLinker::unnumberedConflict(count($numbers))];
    }

    /**
     * Zapis części (upsert po id albo numerze części), przeliczenie wyniku przetargu i wpis w historii.
     *
     * @param  array<string, mixed>  $input  treść żądania {lots: TenderLotUpdate[]}
     * @return array<string, mixed> TenderResultResponse
     *
     * @throws ValidationException
     */
    public function update(Tender $tender, array $input, User $user): array
    {
        $data = $this->validate($this->normalizeInput($input));

        DB::transaction(function () use ($tender, $data, $user): void {
            self::lockTender($tender);
            $statusBefore = $tender->result_status;
            /** @var Collection<int, TenderLot> $existing */
            $existing = $tender->lots()->lockForUpdate()->get()->keyBy('id');
            $byNo = $existing->keyBy(static fn (TenderLot $lot): int => (int) $lot->lot_no);

            // 1. każda pozycja żądania → istniejąca część (po id, potem po numerze) albo nowa
            $targets = [];
            foreach ($data['lots'] as $i => $in) {
                $id = isset($in['id']) ? (int) $in['id'] : null;
                if ($id !== null) {
                    $lot = $existing->get($id);
                    if ($lot === null) {
                        throw ValidationException::withMessages([
                            "lots.$i.id" => ['Ta część zamówienia nie należy do tego przetargu albo została usunięta.'],
                        ]);
                    }
                } else {
                    $lot = $byNo->get((int) $in['lot_no']);
                }
                $targets[$i] = $lot;
            }

            // 2. numery części po zapisie muszą być różne (także względem części spoza żądania)
            $finalNo = [];
            foreach ($existing as $lot) {
                $finalNo['id:'.$lot->id] = (int) $lot->lot_no;
            }
            $requestKeys = [];
            foreach ($data['lots'] as $i => $in) {
                $key = $targets[$i] !== null ? 'id:'.$targets[$i]->id : 'new:'.$i;
                if (isset($requestKeys[$key])) {
                    throw ValidationException::withMessages([
                        "lots.$i.lot_no" => ['Ta sama część zamówienia jest w żądaniu dwa razy.'],
                    ]);
                }
                $requestKeys[$key] = $i;
                $finalNo[$key] = (int) $in['lot_no'];
            }
            $usage = array_count_values($finalNo);
            foreach ($requestKeys as $key => $i) {
                if ($usage[$finalNo[$key]] > 1) {
                    throw ValidationException::withMessages([
                        "lots.$i.lot_no" => ['Część numer '.$finalNo[$key].' już jest w tym przetargu.'],
                    ]);
                }
            }

            // 3. zmiana numeru części: najpierw numery tymczasowe, żeby zamiana 1↔2 nie złamała unikalności
            $previousNo = [];
            foreach ($data['lots'] as $i => $in) {
                $lot = $targets[$i];
                if ($lot !== null && (int) $lot->lot_no !== (int) $in['lot_no']) {
                    $previousNo[$i] = (int) $lot->lot_no;
                    $lot->forceFill(['lot_no' => 65000 - count($previousNo)])->saveQuietly();
                }
            }

            // 4. zapis części
            $log = [];
            foreach ($data['lots'] as $i => $in) {
                $lot = $targets[$i];
                $created = $lot === null;
                if ($lot === null) {
                    $lot = new TenderLot([
                        'tender_id' => $tender->id,
                        'lot_no' => (int) $in['lot_no'],
                        'currency' => 'PLN',
                    ]);
                }
                $lot->lot_no = (int) $in['lot_no'];
                $renumbered = isset($previousNo[$i]);

                // inny numer części powiązanej z ogłoszeniem: dane Biuletynu dotyczyły poprzedniej części ogłoszenia
                $bzpCleared = [];
                $bzpOffersCleared = false;
                if ($renumbered && $lot->bzp_notice_id !== null) {
                    [$bzpCleared, $bzpOffersCleared] = $this->clearBzpData($lot, is_array($lot->manual_fields) ? $lot->manual_fields : []);
                }

                [$fields, $offersChanged, $confirmation] = $this->applyLot($lot, $in, $user, "lots.$i", $renumbered);
                $fields = array_values(array_unique([...$fields, ...$bzpCleared]));
                $offersChanged = $offersChanged || $bzpOffersCleared;
                if ($created || $fields !== [] || $offersChanged || $renumbered || $confirmation !== null) {
                    $entry = ['lot_no' => (int) $lot->lot_no, 'fields' => $fields, 'offers_changed' => $offersChanged];
                    if ($created) {
                        $entry['created'] = true;
                    }
                    if ($renumbered) {
                        $entry['previous_lot_no'] = $previousNo[$i];
                    }
                    if ($confirmation !== null) {
                        $entry['lot_no_confirmed'] = $confirmation;
                    }
                    $log[] = $entry;
                }
            }

            if ($log === []) {
                return;
            }

            $tender->last_activity_at = now();
            $tender->save();
            $statusAfter = TenderResultStatus::recompute($tender);

            $this->activities->log($tender, 'result_updated', $user, null, [
                'lots' => $log,
                'result_status_before' => $statusBefore,
                'result_status_after' => $statusAfter,
            ]);
        });

        return $this->payload($tender->refresh(), $user);
    }

    /**
     * Usuwa część zamówienia (z ofertami innych firm) i przelicza wynik przetargu.
     */
    public function deleteLot(Tender $tender, TenderLot $lot, User $user): void
    {
        if ((int) $lot->tender_id !== (int) $tender->id) {
            abort(404);
        }

        DB::transaction(function () use ($tender, $lot, $user): void {
            self::lockTender($tender);
            $statusBefore = $tender->result_status;
            $lotNo = (int) $lot->lot_no;
            $lot->delete();

            $tender->last_activity_at = now();
            $tender->save();
            $statusAfter = TenderResultStatus::recompute($tender);

            $this->activities->log($tender, 'result_updated', $user, null, [
                'lots' => [['lot_no' => $lotNo, 'deleted' => true, 'fields' => [], 'offers_changed' => false]],
                'result_status_before' => $statusBefore,
                'result_status_after' => $statusAfter,
            ]);
        });
    }

    /**
     * Zmiana numeru ogłoszenia przetargu: dane wpisane w części przez Biuletyn dotyczyły poprzedniego ogłoszenia.
     * W każdej części czyści pola z TenderLot::BZP_FIELDS bez ręcznego wpisu, oferty innych firm z Biuletynu
     * i powiązanie z ogłoszeniem; część założoną przez Biuletyn bez żadnego wpisu ręcznego (pól i ofert) usuwa.
     * Wpisy człowieka i części założone ręcznie zostają; potwierdzenie numeru części (TenderLot::LOT_NO_CONFIRMED)
     * znika — dotyczyło poprzedniego ogłoszenia. Przelicza wynik przetargu i zapisuje zmianę w historii.
     * Wywoływane po zapisie numeru innego postępowania (TenderController::update) — nie przy samej zmianie wersji
     * numeru („…/01”).
     */
    public function detachNotice(Tender $tender, ?User $user, ?string $numberBefore): void
    {
        DB::transaction(function () use ($tender, $user, $numberBefore): void {
            self::lockTender($tender);
            $statusBefore = $tender->result_status;

            $log = [];
            foreach ($tender->lots()->with('offers')->lockForUpdate()->get() as $lot) {
                /** @var TenderLot $lot */
                $lotNo = (int) $lot->lot_no;
                $manual = is_array($lot->manual_fields) ? $lot->manual_fields : [];
                // potwierdzenie numeru części dotyczyło poprzedniego ogłoszenia — znika (nie jest też wpisem, który
                // chroni część założoną przez Biuletyn przed usunięciem)
                $withoutConfirmation = array_values(array_filter($manual, static fn (mixed $field): bool => $field !== TenderLot::LOT_NO_CONFIRMED));
                $manualOffers = $lot->offers->filter(static fn (TenderLotOffer $offer): bool => $offer->source === TenderLotOffer::SOURCE_MANUAL)->count();

                // usuwamy tylko część, którą założył Biuletyn — część założona ręcznie zostaje, nawet pusta
                if ($lot->created_by_bzp && $lot->bzp_notice_id !== null && $withoutConfirmation === [] && $manualOffers === 0) {
                    $lot->delete();
                    $log[] = ['lot_no' => $lotNo, 'deleted' => true, 'fields' => [], 'offers_changed' => false];

                    continue;
                }

                [$cleared, $offersCleared] = $this->clearBzpData($lot, $withoutConfirmation);
                if (count($withoutConfirmation) !== count($manual)) {
                    $lot->manual_fields = $withoutConfirmation !== [] ? $withoutConfirmation : null;
                }
                if ($lot->isDirty()) {
                    $lot->save();
                }
                if ($cleared !== [] || $offersCleared) {
                    $log[] = ['lot_no' => $lotNo, 'fields' => $cleared, 'offers_changed' => $offersCleared];
                }
            }

            if ($log === []) {
                return;
            }
            $statusAfter = TenderResultStatus::recompute($tender);
            $this->activities->log($tender, 'result_updated', $user, null, [
                'source' => 'notice_number_changed',
                'notice_number_before' => $numberBefore,
                'notice_number_after' => $tender->notice_number,
                'lots' => $log,
                'result_status_before' => $statusBefore,
                'result_status_after' => $statusAfter,
            ]);
        });
    }

    /**
     * Czyści w części dane wpisane przez Biuletyn: pola z TenderLot::BZP_FIELDS bez ręcznego wpisu, oferty innych
     * firm z Biuletynu i powiązanie z ogłoszeniem. Nie zapisuje części (zapisuje wołający); oferty usuwa od razu.
     *
     * @param  list<string>  $manual  pola wpisane przez człowieka (zostają)
     * @return array{0: list<string>, 1: bool} wyczyszczone pola i czy usunięto oferty z Biuletynu
     */
    private function clearBzpData(TenderLot $lot, array $manual): array
    {
        $cleared = [];
        foreach (TenderLot::BZP_FIELDS as $field) {
            if (in_array($field, $manual, true)) {
                continue;
            }
            if ($field === 'winner') {
                if ($lot->winner_competitor_id !== null || $lot->winner_national_id_raw !== null) {
                    $lot->winner_competitor_id = null;
                    $lot->winner_national_id_raw = null;
                    $cleared[] = $field;
                }
            } elseif ($field === 'currency') {
                // kolumna bez pustej wartości — waluta wraca do domyślnej
                if ((string) $lot->currency !== 'PLN') {
                    $lot->currency = 'PLN';
                    $cleared[] = $field;
                }
            } elseif ($lot->getAttribute($field) !== null) {
                $lot->setAttribute($field, null);
                $cleared[] = $field;
                if ($field === 'outcome') {
                    // wynik bez ręcznego wpisu ustawił Biuletyn — razem z nim chwila rozstrzygnięcia
                    $lot->decided_by = null;
                    $lot->decided_at = null;
                }
            }
        }
        $bzpOffers = $lot->offers->filter(static fn (TenderLotOffer $offer): bool => $offer->source !== TenderLotOffer::SOURCE_MANUAL);
        foreach ($bzpOffers as $offer) {
            $offer->delete();
        }
        if ($bzpOffers->isNotEmpty()) {
            $lot->unsetRelation('offers');
        }
        $lot->bzp_notice_id = null;
        $lot->bzp_applied_at = null;

        return [$cleared, $bzpOffers->isNotEmpty()];
    }

    /**
     * Blokuje wiersz przetargu do końca bieżącej transakcji i wczytuje jego aktualne wartości — zapis wyniku przez
     * człowieka i łączenie z Biuletynem (BzpTenderLinker) nie nadpisują się nawzajem. SQLite blokady ignoruje.
     */
    public static function lockTender(Tender $tender): void
    {
        $fresh = Tender::query()->whereKey($tender->getKey())->lockForUpdate()->firstOrFail();
        $tender->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * Wpisuje do części pola z żądania; zwraca nazwy zmienionych pól, to, czy zmieniły się oferty innych firm,
     * i zmianę potwierdzenia numeru części (true/false; null = bez zmiany).
     *
     * Potwierdzenie numeru części (lot_no_confirmed): true → TenderLot::LOT_NO_CONFIRMED w manual_fields, false →
     * usunięte; zmiana numeru części bez lot_no_confirmed: true też je usuwa (potwierdzony był poprzedni numer).
     *
     * @param  array<string, mixed>  $in
     * @param  bool  $renumbered  numer części zmieniony tym żądaniem
     * @return array{0: list<string>, 1: bool, 2: ?bool}
     */
    private function applyLot(TenderLot $lot, array $in, User $user, string $path, bool $renumbered = false): array
    {
        $changed = [];

        foreach (['name', 'cpv_main', 'note'] as $field) {
            if (array_key_exists($field, $in)) {
                $value = $this->text($in[$field]);
                if ($value !== $this->text($lot->getAttribute($field))) {
                    $lot->setAttribute($field, $value);
                    $changed[] = $field;
                }
            }
        }

        foreach (self::AMOUNT_FIELDS as $field) {
            if (array_key_exists($field, $in)) {
                $value = $in[$field];
                if (self::cents($value) !== self::cents($lot->getAttribute($field))) {
                    $lot->setAttribute($field, $value);
                    $changed[] = $field;
                }
            }
        }

        if (array_key_exists('our_vat_rate', $in)
            && self::cents($in['our_vat_rate']) !== self::cents($lot->our_vat_rate)) {
            $lot->our_vat_rate = $in['our_vat_rate'];
            $changed[] = 'our_vat_rate';
        }

        if (array_key_exists('offers_count', $in)) {
            $value = $in['offers_count'] !== null ? (int) $in['offers_count'] : null;
            if ($value !== ($lot->offers_count !== null ? (int) $lot->offers_count : null)) {
                $lot->offers_count = $value;
                $changed[] = 'offers_count';
            }
        }

        if (array_key_exists('currency', $in) && $in['currency'] !== null) {
            $value = strtoupper((string) $in['currency']);
            if ($value !== (string) $lot->currency) {
                $lot->currency = $value;
                $changed[] = 'currency';
            }
        }

        if (array_key_exists('outcome', $in) && ($in['outcome'] ?? null) !== $lot->outcome) {
            $lot->outcome = $in['outcome'];
            $changed[] = 'outcome';
        }

        if ($this->applyWinner($lot, $in, $path)) {
            $changed[] = 'winner';
        }

        // powód przegranej tylko przy przegranej
        $reasonGiven = array_key_exists('loss_reason', $in) ? $in['loss_reason'] : null;
        if ($lot->outcome !== TenderLot::OUTCOME_LOST) {
            if ($reasonGiven !== null) {
                throw ValidationException::withMessages([
                    "$path.loss_reason" => ['Powód przegranej można wpisać tylko przy wyniku „przegrana”.'],
                ]);
            }
            if ($lot->loss_reason !== null) {
                $lot->loss_reason = null;
                $changed[] = 'loss_reason';
            }
        } elseif (array_key_exists('loss_reason', $in) && $reasonGiven !== $lot->loss_reason) {
            $lot->loss_reason = $reasonGiven;
            $changed[] = 'loss_reason';
        }

        $changed = array_values(array_unique($changed));
        $manual = is_array($lot->manual_fields) ? $lot->manual_fields : [];
        $wasConfirmed = in_array(TenderLot::LOT_NO_CONFIRMED, $manual, true);
        // reguła „boolean” przepuszcza też 1/0 i „1”/„0”
        $confirmInput = array_key_exists('lot_no_confirmed', $in) ? filter_var($in['lot_no_confirmed'], FILTER_VALIDATE_BOOLEAN) : null;
        $confirmed = match (true) {
            $confirmInput === true => true,
            $confirmInput === false, $renumbered => false,
            default => $wasConfirmed,
        };
        if ($changed !== [] || $confirmed !== $wasConfirmed) {
            $merged = array_values(array_unique([...$manual, ...$changed]));
            $merged = $confirmed
                ? [...$merged, TenderLot::LOT_NO_CONFIRMED]
                : array_values(array_filter($merged, static fn (mixed $field): bool => $field !== TenderLot::LOT_NO_CONFIRMED));
            // kolejność jak w EDITABLE_FIELDS (potwierdzenie numeru na końcu) — stabilna odpowiedź i porównania w testach
            $lot->manual_fields = array_values(array_filter(
                self::MANUAL_FIELDS_ORDER,
                static fn (string $field): bool => in_array($field, $merged, true),
            )) ?: null;
        }
        // kto i kiedy rozstrzygnął — tylko przy zmianie wyniku przez człowieka (nie przy notatce czy cenie)
        if (in_array('outcome', $changed, true)) {
            $lot->decided_by = $lot->outcome !== null ? $user->id : null;
            $lot->decided_at = $lot->outcome !== null ? now() : null;
        }

        $lot->save();

        $offersChanged = array_key_exists('offers', $in)
            ? $this->syncOffers($lot, is_array($in['offers']) ? $in['offers'] : [], $user, $path)
            : false;

        return [$changed, $offersChanged, $confirmed !== $wasConfirmed ? $confirmed : null];
    }

    /**
     * Zwycięzca: firma ze słownika (competitor_id) albo nowa (nazwa + NIP). NIP bez poprawnej sumy kontrolnej
     * nie trafia do firmy — zostaje dokładnie w winner_national_id_raw.
     *
     * @param  array<string, mixed>  $in
     */
    private function applyWinner(TenderLot $lot, array $in, string $path): bool
    {
        $oldId = $lot->winner_competitor_id !== null ? (int) $lot->winner_competitor_id : null;
        $oldRaw = $lot->winner_national_id_raw;

        if (! array_key_exists('winner', $in)) {
            if (array_key_exists('winner_national_id_raw', $in)) {
                $raw = $this->text($in['winner_national_id_raw']);
                if ($raw !== $oldRaw) {
                    $lot->winner_national_id_raw = $raw;

                    return true;
                }
            }

            return false;
        }

        $winner = $in['winner'];
        if ($winner === null) {
            $newId = null;
            $newRaw = null;
        } elseif (isset($winner['competitor_id'])) {
            $newId = (int) $winner['competitor_id'];
            // ta sama firma — surowy NIP ze źródła zostaje
            $newRaw = $newId === $oldId ? $oldRaw : null;
        } else {
            $competitor = $this->resolveCompetitor($winner, "$path.winner");
            $newId = (int) $competitor->id;
            $nip = $this->text($winner['nip'] ?? null);
            if ($nip === null) {
                $newRaw = $newId === $oldId ? $oldRaw : null;
            } else {
                $newRaw = CompanyName::nip($nip) === null ? mb_substr($nip, 0, 40) : null;
            }
        }

        if ($newId === $oldId && $newRaw === $oldRaw) {
            return false;
        }
        $lot->winner_competitor_id = $newId;
        $lot->winner_national_id_raw = $newRaw;

        return true;
    }

    /**
     * Oferty innych firm: lista z żądania zastępuje zapisane w całości. Ta sama firma z tą samą ceną zostaje
     * bez zmian (zachowuje źródło), inna cena = wpis ręczny.
     *
     * @param  list<array<string, mixed>>  $offers
     */
    private function syncOffers(TenderLot $lot, array $offers, User $user, string $path): bool
    {
        $wanted = [];
        foreach ($offers as $j => $offer) {
            $competitor = $this->resolveCompetitor($offer, "$path.offers.$j");
            $id = (int) $competitor->id;
            if (isset($wanted[$id])) {
                throw ValidationException::withMessages([
                    "$path.offers.$j" => ['Firma „'.$competitor->name.'” jest na liście ofert dwa razy.'],
                ]);
            }
            $wanted[$id] = [
                'price' => (string) $offer['price'],
                'currency' => strtoupper((string) ($offer['currency'] ?? $lot->currency ?? 'PLN')),
            ];
        }

        $changed = false;
        $current = $lot->offers()->get()->keyBy('competitor_id');
        foreach ($current as $competitorId => $offer) {
            if (! isset($wanted[(int) $competitorId])) {
                $offer->delete();
                $changed = true;
            }
        }
        foreach ($wanted as $competitorId => $row) {
            /** @var TenderLotOffer|null $offer */
            $offer = $current->get($competitorId);
            if ($offer === null) {
                TenderLotOffer::query()->create([
                    'tender_lot_id' => $lot->id,
                    'competitor_id' => $competitorId,
                    'price' => $row['price'],
                    'currency' => $row['currency'],
                    'source' => TenderLotOffer::SOURCE_MANUAL,
                    'created_by' => $user->id,
                ]);
                $changed = true;

                continue;
            }
            if (self::cents($offer->price) !== self::cents($row['price']) || $offer->currency !== $row['currency']) {
                $offer->forceFill([
                    'price' => $row['price'],
                    'currency' => $row['currency'],
                    'source' => TenderLotOffer::SOURCE_MANUAL,
                    'created_by' => $user->id,
                ])->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $input  {competitor_id} albo {name, nip?}
     */
    private function resolveCompetitor(array $input, string $path): Competitor
    {
        if (isset($input['competitor_id'])) {
            return Competitor::query()->findOrFail((int) $input['competitor_id']);
        }

        $competitor = $this->competitors->resolve(
            $this->text($input['name'] ?? null),
            $this->text($input['nip'] ?? null),
        );
        if ($competitor === null) {
            throw ValidationException::withMessages([
                $path => ['Wybierz firmę z listy albo wpisz jej nazwę lub poprawny NIP.'],
            ]);
        }

        return $competitor;
    }

    /**
     * @return array<string, mixed>
     */
    private function lotRow(TenderLot $lot, ?string $bzpConflict): array
    {
        $manual = is_array($lot->manual_fields) ? array_values($lot->manual_fields) : [];
        $ourGross = self::gross($lot->our_net, $lot->our_vat_rate);

        return [
            'id' => (int) $lot->id,
            'lot_no' => (int) $lot->lot_no,
            'name' => $lot->name,
            'cpv_main' => $lot->cpv_main,
            'estimated_value' => $lot->estimated_value,
            'our_net' => $lot->our_net,
            'our_vat_rate' => $lot->our_vat_rate,
            'our_gross' => $ourGross,
            'outcome' => $lot->outcome,
            'winner' => $lot->winner !== null ? $this->competitorRow($lot->winner) : null,
            'winner_national_id_raw' => $lot->winner_national_id_raw,
            'winner_price' => $lot->winner_price,
            'currency' => (string) ($lot->currency ?? 'PLN'),
            'offers_count' => $lot->offers_count !== null ? (int) $lot->offers_count : null,
            'lowest_price' => $lot->lowest_price,
            'highest_price' => $lot->highest_price,
            'loss_reason' => $lot->loss_reason,
            'note' => $lot->note,
            'price_gap' => self::priceGap($ourGross, $lot->winner_price, (string) ($lot->currency ?? 'PLN')),
            'manual_fields' => $manual,
            'bzp_fields' => $this->bzpFields($lot, $manual),
            'bzp_notice_number' => $lot->bzpNotice?->notice_number,
            'bzp_conflict' => $bzpConflict,
            'decided_by' => $lot->decidedBy !== null ? ['id' => (int) $lot->decidedBy->id, 'name' => $lot->decidedBy->name] : null,
            'decided_at' => $lot->decided_at?->toIso8601String(),
            'offers' => $lot->offers->map(fn (TenderLotOffer $offer): array => [
                'id' => (int) $offer->id,
                'competitor' => $this->competitorRow($offer->competitor),
                'price' => $offer->price,
                'currency' => (string) $offer->currency,
                'source' => (string) $offer->source,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function virtualLotRow(int $lotNo): array
    {
        return [
            'id' => null,
            'lot_no' => $lotNo,
            'name' => null,
            'cpv_main' => null,
            'estimated_value' => null,
            'our_net' => null,
            'our_vat_rate' => null,
            'our_gross' => null,
            'outcome' => null,
            'winner' => null,
            'winner_national_id_raw' => null,
            'winner_price' => null,
            'currency' => 'PLN',
            'offers_count' => null,
            'lowest_price' => null,
            'highest_price' => null,
            'loss_reason' => null,
            'note' => null,
            'price_gap' => null,
            'manual_fields' => [],
            'bzp_fields' => [],
            'bzp_notice_number' => null,
            'bzp_conflict' => null,
            'decided_by' => null,
            'decided_at' => null,
            'offers' => [],
        ];
    }

    /**
     * Pola wypełnione z Biuletynu: część powiązana z ogłoszeniem, pole z BZP_FIELDS ma wartość i nie zostało
     * wpisane przez człowieka. Biuletyn ustawia sam tylko wynik „wygrana” i „unieważniona”.
     *
     * @param  list<string>  $manual
     * @return list<string>
     */
    private function bzpFields(TenderLot $lot, array $manual): array
    {
        if ($lot->bzp_notice_id === null) {
            return [];
        }

        $fields = [];
        foreach (TenderLot::BZP_FIELDS as $field) {
            if (in_array($field, $manual, true) || $field === 'currency') {
                continue;
            }
            $filled = match ($field) {
                'winner' => $lot->winner_competitor_id !== null || $lot->winner_national_id_raw !== null,
                'outcome' => in_array($lot->outcome, [TenderLot::OUTCOME_WON, TenderLot::OUTCOME_CANCELLED], true),
                default => $lot->getAttribute($field) !== null && $lot->getAttribute($field) !== '',
            };
            if ($filled) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array{id: int, name: string, nip: ?string}
     */
    private function competitorRow(Competitor $competitor): array
    {
        return [
            'id' => (int) $competitor->id,
            'name' => (string) $competitor->name,
            'nip' => $competitor->nip,
        ];
    }

    /**
     * @return array{id: int, notice_number: string, published_at: ?string, url: ?string}|null
     */
    private function noticeRef(?ProcurementNotice $notice): ?array
    {
        if ($notice === null) {
            return null;
        }
        $objectId = (string) ($notice->object_id ?? '');

        return [
            'id' => (int) $notice->id,
            'notice_number' => (string) $notice->notice_number,
            'published_at' => $notice->published_at?->toIso8601String(),
            'url' => $objectId !== '' ? self::BZP_NOTICE_URL.rawurlencode($objectId) : null,
        ];
    }

    /**
     * Brutto z netto i stawki VAT, zaokrąglone do grosza (połówki w górę); null bez netto albo bez stawki.
     */
    public static function gross(mixed $net, mixed $vatRate): ?string
    {
        $netCents = self::cents($net);
        $vatHundredths = self::cents($vatRate);
        if ($netCents === null || $vatHundredths === null) {
            return null;
        }
        // netto w groszach × (100% + VAT) — VAT w setnych procenta (23.00% = 2300), więc 100% = 10000
        $grossCents = intdiv($netCents * (10000 + $vatHundredths) + 5000, 10000);

        return self::fromCents($grossCents);
    }

    /**
     * Nasza cena brutto minus cena zwycięzcy (dodatnia = byliśmy drożsi) i ten sam procent od naszej ceny.
     *
     * @return array{amount: string, percent: float}|null
     */
    public static function priceGap(?string $ourGross, mixed $winnerPrice, string $currency): ?array
    {
        $our = self::cents($ourGross);
        $winner = self::cents($winnerPrice);
        if ($our === null || $winner === null || $our === 0 || strtoupper($currency) !== 'PLN') {
            return null;
        }
        $diff = $our - $winner;

        return [
            'amount' => self::fromCents($diff),
            'percent' => round($diff * 100 / $our, 1),
        ];
    }

    /**
     * Kwota („1234.5”, 1234.5, „1234,50”) w groszach / setnych; null dla pustej albo nieczytelnej.
     */
    public static function cents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    private static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Kwoty z przecinkiem i spacjami („74 310,00”) → „74310.00”; liczby → tekst. Bez zaokrąglania.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        if (! isset($input['lots']) || ! is_array($input['lots'])) {
            return $input;
        }
        foreach ($input['lots'] as $i => $lot) {
            if (! is_array($lot)) {
                continue;
            }
            foreach ([...self::AMOUNT_FIELDS, 'our_vat_rate'] as $field) {
                if (array_key_exists($field, $lot)) {
                    $lot[$field] = $this->normalizeAmount($lot[$field]);
                }
            }
            if (isset($lot['offers']) && is_array($lot['offers'])) {
                foreach ($lot['offers'] as $j => $offer) {
                    if (is_array($offer) && array_key_exists('price', $offer)) {
                        $offer['price'] = $this->normalizeAmount($offer['price']);
                        $lot['offers'][$j] = $offer;
                    }
                }
            }
            foreach (['currency'] as $field) {
                if (isset($lot[$field]) && is_string($lot[$field])) {
                    $lot[$field] = strtoupper(trim($lot[$field]));
                }
            }
            $input['lots'][$i] = $lot;
        }

        return $input;
    }

    private function normalizeAmount(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', '', $value);
        if ($text === '') {
            return null;
        }

        return str_replace(',', '.', $text);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{lots: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $amount = ['nullable', 'string', 'regex:'.self::AMOUNT_PATTERN];
        $currency = ['nullable', 'string', 'regex:/^[A-Z]{3}$/'];

        $validator = Validator::make($input, [
            'lots' => ['required', 'array', 'min:1', 'max:'.self::MAX_LOTS],
            'lots.*' => ['array'],
            'lots.*.id' => ['nullable', 'integer'],
            'lots.*.lot_no' => ['required', 'integer', 'min:1', 'max:1000'],
            'lots.*.lot_no_confirmed' => ['sometimes', 'boolean'],
            'lots.*.name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lots.*.cpv_main' => ['sometimes', 'nullable', 'string', 'regex:/^\d{8}(-\d)?$/'],
            'lots.*.estimated_value' => ['sometimes', ...$amount],
            'lots.*.our_net' => ['sometimes', ...$amount],
            'lots.*.our_vat_rate' => ['sometimes', 'nullable', 'string', 'regex:'.self::VAT_PATTERN, 'numeric', 'min:0', 'max:100'],
            'lots.*.outcome' => ['sometimes', 'nullable', Rule::in(TenderLot::OUTCOMES)],
            'lots.*.winner' => ['sometimes', 'nullable', 'array'],
            'lots.*.winner.competitor_id' => ['nullable', 'integer', 'exists:competitors,id'],
            'lots.*.winner.name' => ['nullable', 'string', 'max:500'],
            'lots.*.winner.nip' => ['nullable', 'string', 'max:40'],
            'lots.*.winner_national_id_raw' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lots.*.winner_price' => ['sometimes', ...$amount],
            'lots.*.currency' => ['sometimes', ...$currency],
            'lots.*.offers_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'lots.*.lowest_price' => ['sometimes', ...$amount],
            'lots.*.highest_price' => ['sometimes', ...$amount],
            'lots.*.loss_reason' => ['sometimes', 'nullable', Rule::in(TenderLot::LOSS_REASONS)],
            'lots.*.note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lots.*.offers' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_OFFERS],
            'lots.*.offers.*' => ['array'],
            'lots.*.offers.*.competitor_id' => ['nullable', 'integer', 'exists:competitors,id'],
            'lots.*.offers.*.name' => ['nullable', 'string', 'max:500'],
            'lots.*.offers.*.nip' => ['nullable', 'string', 'max:40'],
            'lots.*.offers.*.price' => ['required', 'string', 'regex:'.self::AMOUNT_PATTERN],
            'lots.*.offers.*.currency' => $currency,
        ], [
            'required' => 'Pole „:attribute” jest wymagane.',
            'array' => 'Pole „:attribute” ma zły format.',
            'string' => 'Pole „:attribute” ma zły format.',
            'integer' => 'Pole „:attribute” musi być liczbą całkowitą.',
            'numeric' => 'Pole „:attribute” musi być liczbą.',
            'boolean' => 'Pole „:attribute” musi mieć wartość tak albo nie.',
            'in' => 'Pole „:attribute” ma niedozwoloną wartość.',
            'exists' => 'Nie znaleziono firmy wybranej w polu „:attribute”.',
            'min.numeric' => 'Pole „:attribute” nie może być mniejsze niż :min.',
            'min.array' => 'Brak części zamówienia do zapisania.',
            'max.numeric' => 'Pole „:attribute” nie może być większe niż :max.',
            'min.integer' => 'Pole „:attribute” nie może być mniejsze niż :min.',
            'max.integer' => 'Pole „:attribute” nie może być większe niż :max.',
            'max.string' => 'Pole „:attribute” może mieć najwyżej :max znaków.',
            'max.array' => 'Pole „:attribute” może mieć najwyżej :max pozycji.',
            'regex' => 'Pole „:attribute” ma zły format — wpisz kwotę z najwyżej dwoma miejscami po przecinku.',
            'lots.*.cpv_main.regex' => 'Kod CPV ma postać 18100000-0.',
            'lots.*.currency.regex' => 'Waluta to trzy litery, np. PLN.',
            'lots.*.offers.*.currency.regex' => 'Waluta to trzy litery, np. PLN.',
            'lots.*.our_vat_rate.regex' => 'Stawka VAT to liczba od 0 do 100, najwyżej dwa miejsca po przecinku.',
        ], [
            'lots' => 'części zamówienia',
            'lots.*.id' => 'część zamówienia',
            'lots.*.lot_no' => 'numer części',
            'lots.*.lot_no_confirmed' => 'potwierdzenie numeru części',
            'lots.*.name' => 'nazwa części',
            'lots.*.cpv_main' => 'kod CPV',
            'lots.*.estimated_value' => 'wartość części',
            'lots.*.our_net' => 'nasza cena netto',
            'lots.*.our_vat_rate' => 'stawka VAT',
            'lots.*.outcome' => 'wynik',
            'lots.*.winner' => 'wygrała firma',
            'lots.*.winner.competitor_id' => 'wygrała firma',
            'lots.*.winner.name' => 'nazwa zwycięzcy',
            'lots.*.winner.nip' => 'NIP zwycięzcy',
            'lots.*.winner_national_id_raw' => 'NIP zwycięzcy',
            'lots.*.winner_price' => 'cena zwycięzcy',
            'lots.*.currency' => 'waluta',
            'lots.*.offers_count' => 'liczba ofert',
            'lots.*.lowest_price' => 'najniższa cena',
            'lots.*.highest_price' => 'najwyższa cena',
            'lots.*.loss_reason' => 'powód przegranej',
            'lots.*.note' => 'notatka',
            'lots.*.offers' => 'oferty innych firm',
            'lots.*.offers.*.competitor_id' => 'firma',
            'lots.*.offers.*.name' => 'nazwa firmy',
            'lots.*.offers.*.nip' => 'NIP firmy',
            'lots.*.offers.*.price' => 'cena oferty',
            'lots.*.offers.*.currency' => 'waluta oferty',
        ]);

        /** @var array{lots: list<array<string, mixed>>} $data */
        $data = $validator->validate();
        // validate() zwraca tylko pola z reguł — klucze pominięte w żądaniu zostają pominięte (= bez zmian)
        $data['lots'] = array_values($data['lots']);

        return $data;
    }
}
