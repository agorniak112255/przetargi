<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpCustomer;
use App\Models\InspectionDismissal;
use App\Models\InspectionDue;
use App\Models\Offer;
use App\Models\User;
use App\Support\PolishTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * „Przygotuj oferty” w module Przeglądy: po jednej ofercie przeglądu (Offer kind = inspection, lista „Oferty”, numer
 * OF-) na zaznaczonego klienta XL. Wiersze z wyliczonych terminów (inspection_due) w oknie „dziś + days” — zaległe też,
 * bez limitu starych zaległych z listy — bez pominiętych klientów i pozycji. Oferta zaczepna: bez cen, autor = wołający;
 * dalsza edycja, podgląd i wysyłka w OfferController. Nic nie wysyła.
 */
class InspectionOfferController extends Controller
{
    public const SKIP_NO_LINES = 'Brak pozycji z terminem w oknie';

    public const SKIP_DISMISSED = 'Klient pominięty';

    public const SKIP_POSITIONS_DISMISSED = 'Wszystkie pozycje klienta z terminem w oknie są pominięte';

    /** Szkic oferty przeglądu innej osoby do tego klienta z tylu dni = ostrzeżenie (dwóch handlowców, ponowione żądanie). */
    private const DRAFT_DAYS = 14;

    /** Wysłana oferta przeglądu do klienta z tylu dni = ostrzeżenie „oferta już wysłana” przy przygotowaniu nowej. */
    private const PREVIOUS_DAYS = 90;

    private const SUBJECT = 'Przypomnienie o terminie przeglądu';

    /** Wstęp domyślny: bez cen i bez dat (daty są w tabeli); handlowiec może go zmienić przed wysyłką. */
    private const INTRO = "Dzień dobry,\n\n"
        .'według naszych dokumentów sprzedaży zbliża się lub minął termin przeglądu pozycji wymienionych w zestawieniu. '
        .'Chętnie wykonamy te przeglądy — prosimy o odpowiedź na tę wiadomość albo telefon, a ustalimy dogodny termin.';

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'customer_xl_gids' => ['required', 'array', 'min:1', 'max:50'],
            'customer_xl_gids.*' => ['integer', 'min:1', 'distinct'],
            'days' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'position_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'position_ids.*' => ['integer', 'min:1'],
        ], [
            'customer_xl_gids.required' => 'Zaznacz co najmniej jednego klienta.',
            'customer_xl_gids.min' => 'Zaznacz co najmniej jednego klienta.',
            'customer_xl_gids.max' => 'Naraz można przygotować najwyżej :max ofert — zaznacz mniej klientów.',
            'customer_xl_gids.*.integer' => 'Niepoprawny numer klienta XL.',
            'customer_xl_gids.*.min' => 'Niepoprawny numer klienta XL.',
            'customer_xl_gids.*.distinct' => 'Ten sam klient jest zaznaczony dwa razy.',
            'days.integer' => 'Okno terminu musi być liczbą dni od 0 do 730.',
            'days.min' => 'Okno terminu musi być liczbą dni od 0 do 730.',
            'days.max' => 'Okno terminu musi być liczbą dni od 0 do 730.',
            'position_ids.max' => 'Zaznaczono za dużo pozycji.',
            'position_ids.*.integer' => 'Niepoprawny numer pozycji przeglądu.',
            'position_ids.*.min' => 'Niepoprawny numer pozycji przeglądu.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $gids = array_map('intval', $v['customer_xl_gids']);
        $positionIds = isset($v['position_ids']) ? array_map('intval', $v['position_ids']) : null;
        $today = PolishTime::today();
        // termin ≤ dziś + days; „< następny dzień” działa i dla kolumny DATE, i dla daty z godziną (SQLite w testach)
        $before = $today->addDays((int) ($v['days'] ?? 30))->addDay()->toDateString();

        $customers = ErpCustomer::query()->whereIn('xl_gid', $gids)
            ->get(['id', 'xl_gid', 'acronym', 'name', 'emails'])
            ->keyBy(static fn (ErpCustomer $c): int => (int) $c->xl_gid);
        $dismissals = InspectionDismissal::query()
            ->whereIn('customer_xl_gid', $gids)
            // aktywne: na zawsze albo do daty nie wcześniejszej niż dziś
            ->where(fn (Builder $q) => $q->whereNull('until_on')->orWhere('until_on', '>=', $today->toDateString()))
            ->get(['customer_xl_gid', 'inspection_position_id'])
            ->groupBy(static fn (InspectionDismissal $d): int => (int) $d->customer_xl_gid);

        $offers = [];
        $skipped = [];
        DB::transaction(function () use ($gids, $positionIds, $before, $customers, $dismissals, $user, &$offers, &$skipped): void {
            foreach ($gids as $gid) {
                /** @var ErpCustomer|null $customer */
                $customer = $customers->get($gid);
                $name = self::customerName($gid, $customer);
                $customerDismissals = $dismissals->get($gid, collect());
                if ($customerDismissals->contains(static fn (InspectionDismissal $d): bool => $d->inspection_position_id === null)) {
                    $skipped[] = ['customer_xl_gid' => $gid, 'customer_name' => $name, 'reason' => self::SKIP_DISMISSED];

                    continue;
                }
                $dismissedPositions = $customerDismissals->pluck('inspection_position_id')->map(static fn ($id): int => (int) $id)->all();

                $window = InspectionDue::query()
                    ->where('customer_xl_gid', $gid)
                    ->where('due_on', '<', $before)
                    ->when($positionIds !== null, static fn (Builder $q) => $q->whereIn('inspection_position_id', $positionIds))
                    ->whereHas('position', static fn (Builder $q) => $q->where('active', true));
                $due = (clone $window)
                    ->when($dismissedPositions !== [], static fn (Builder $q) => $q->whereNotIn('inspection_position_id', $dismissedPositions))
                    ->with('position:id,xl_gid,name,unit')
                    ->orderBy('due_on')->orderBy('id')
                    ->get();
                if ($due->isEmpty()) {
                    // pozycje w oknie są, ale wszystkie pominięte — inny powód niż „brak terminów”
                    $reason = $dismissedPositions !== [] && $window->exists() ? self::SKIP_POSITIONS_DISMISSED : self::SKIP_NO_LINES;
                    $skipped[] = ['customer_xl_gid' => $gid, 'customer_name' => $name, 'reason' => $reason];

                    continue;
                }

                $offer = Offer::query()->create([
                    'user_id' => $user->id,
                    'kind' => Offer::KIND_INSPECTION,
                    'customer_xl_gid' => $gid,
                    'subject' => $this->subject($customer),
                    'intro' => self::INTRO,
                    'layout' => 'grid3',
                    'delivery' => 'body',
                ]);
                foreach ($due->values() as $i => $row) {
                    $offer->inspectionLines()->create([
                        'position' => $i + 1,
                        'inspection_position_id' => $row->inspection_position_id,
                        'xl_gid' => $row->position?->xl_gid,
                        'name' => mb_substr((string) $row->position?->name, 0, 500),
                        'unit' => $row->position?->unit,
                        'quantity' => $row->open_quantity,
                        'last_on' => $row->last_on?->toDateString(),
                        'due_on' => $row->due_on?->toDateString(),
                    ]);
                }
                $offer->refresh();

                $offers[] = [
                    'id' => (int) $offer->id,
                    'code' => $offer->code,
                    'customer_xl_gid' => $gid,
                    'customer_name' => $name,
                    'lines_count' => $due->count(),
                    'emails' => self::customerEmails($customer),
                    'previous' => $this->previous($gid, (int) $offer->id, (int) $user->id),
                    // inna karta XL z tym samym NIP-em ma późniejszą sprzedaż pozycji — przegląd mógł już być zrobiony
                    'same_nip_newer_lines' => $due->filter(static fn (InspectionDue $d): bool => (bool) $d->same_nip_newer)->count(),
                ];
            }
        });

        return response()->json(['offers' => $offers, 'skipped' => $skipped], 201);
    }

    /**
     * Najnowsza wysłana oferta przeglądu do klienta (dowolny autor) w ostatnich 90 dniach, a gdy jej nie ma — niewysłany
     * szkic innej osoby z ostatnich 14 dni (sent_at = null) — żeby handlowiec nie wysłał drugiego przypomnienia zaraz
     * po koledze.
     *
     * @return array{code: string|null, sent_at: string|null, created_at: string|null, user_name: string|null}|null
     */
    private function previous(int $gid, int $exceptId, int $userId): ?array
    {
        $base = Offer::query()
            ->where('kind', Offer::KIND_INSPECTION)
            ->where('customer_xl_gid', $gid)
            ->whereKeyNot($exceptId)
            ->with('user:id,name');
        $offer = (clone $base)
            ->whereNotNull('last_sent_at')
            ->where('last_sent_at', '>=', Carbon::now()->subDays(self::PREVIOUS_DAYS))
            ->orderByDesc('last_sent_at')->orderByDesc('id')
            ->first()
            ?? (clone $base)
                ->whereNull('last_sent_at')
                ->where('user_id', '<>', $userId)
                ->where('created_at', '>=', Carbon::now()->subDays(self::DRAFT_DAYS))
                ->orderByDesc('id')
                ->first();

        return $offer === null ? null : [
            'code' => $offer->code,
            'sent_at' => $offer->last_sent_at?->toIso8601String(),
            'created_at' => $offer->created_at?->toIso8601String(),
            'user_name' => $offer->user?->name,
        ];
    }

    /** Temat w jednej linii, do 200 znaków (limit pola tematu w edycji oferty). */
    private function subject(?ErpCustomer $customer): string
    {
        $who = trim((string) preg_replace('/\s+/u', ' ', trim((string) $customer?->name) !== '' ? (string) $customer?->name : (string) $customer?->acronym));

        return mb_substr($who !== '' ? self::SUBJECT.' — '.$who : self::SUBJECT, 0, 200);
    }

    /** Nazwa klienta (też na liście ofert): pełna nazwa, akronim albo numer XL (klienta nie ma w kartotece). */
    public static function customerName(int $gid, ?ErpCustomer $customer): string
    {
        $name = trim((string) $customer?->name);
        if ($name !== '') {
            return $name;
        }
        $acronym = trim((string) $customer?->acronym);

        return $acronym !== '' ? $acronym : 'Klient XL '.$gid;
    }

    /** @return list<string> adresy z karty klienta XL (do wstawienia przy wysyłce) */
    public static function customerEmails(?ErpCustomer $customer): array
    {
        $emails = $customer !== null && is_array($customer->emails) ? $customer->emails : [];

        return array_values(array_filter($emails, static fn ($e): bool => is_string($e) && $e !== ''));
    }
}
