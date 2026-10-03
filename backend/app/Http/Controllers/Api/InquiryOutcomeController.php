<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientInquiry;
use App\Models\InquiryOrderHint;
use App\Services\ClientInquiryService;
use App\Services\Clients\InquiryClientLinker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Jak się skończyło zapytanie: PUT /inquiries/{inquiry}/outcome i powiązanie z klientem PUT /inquiries/{inquiry}/client
 * — tylko autor zapytania (trasy w grupie inquiries.use).
 *
 * Wynik wpisuje wyłącznie handlowiec, dopiero po wysłaniu odpowiedzi. Podpowiedź z ERP XL (InquiryOrderHint) nigdy
 * nie wpisuje wyniku sama — „Potwierdź ten dokument” (hint_id) tylko kopiuje do wyniku numer, datę i wartość
 * dokumentu wskazanego przez człowieka.
 */
class InquiryOutcomeController extends Controller
{
    /** Wynik, przy którym klient coś kupił — tylko wtedy wolno wskazać dokument z ERP XL. */
    private const ORDERED = ['ordered', 'partial'];

    /** Powód ma sens tylko przy „zamówił część” i „nie zamówił”. */
    private const WITH_REASON = ['partial', 'not_ordered'];

    public function __construct(private readonly ClientInquiryService $inquiries) {}

    public function update(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);
        $data = $request->validate([
            'outcome' => ['present', 'nullable', 'string', Rule::in(ClientInquiry::OUTCOMES)],
            'reason' => ['nullable', 'string', Rule::in(ClientInquiry::OUTCOME_REASONS)],
            'hint_id' => ['nullable', 'integer'],
        ], [
            'outcome.present' => 'Podaj wynik zapytania albo null, żeby go wyczyścić.',
            'outcome.in' => 'Nieznany wynik. Dozwolone: ordered, partial, not_ordered, unknown.',
            'reason.in' => 'Nieznany powód. Dozwolone: price, lead_time, bought_elsewhere, no_response.',
            'hint_id.integer' => 'Numer podpowiedzi musi być liczbą.',
        ]);

        $outcome = $data['outcome'] ?? null;
        $reason = $data['reason'] ?? null;
        $hasHintKey = array_key_exists('hint_id', $data);
        $hintId = $hasHintKey && $data['hint_id'] !== null ? (int) $data['hint_id'] : null;

        if ($reason !== null && ! in_array($outcome, self::WITH_REASON, true)) {
            throw ValidationException::withMessages(['reason' => 'Powód podaje się tylko przy wyniku „zamówił część” albo „nie zamówił”.']);
        }
        if ($hintId !== null && ! in_array($outcome, self::ORDERED, true)) {
            throw ValidationException::withMessages(['hint_id' => 'Dokument z ERP XL można wskazać tylko przy wyniku „zamówił” albo „zamówił część”.']);
        }

        $saved = DB::transaction(function () use ($inquiry, $request, $outcome, $reason, $hasHintKey, $hintId): ClientInquiry {
            /** @var ClientInquiry $row */
            $row = ClientInquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if ($row->replied_at === null) {
                throw ValidationException::withMessages(['outcome' => 'Wynik zapisuje się po wysłaniu odpowiedzi do klienta.']);
            }

            $changes = [
                'outcome' => $outcome,
                'outcome_reason' => $reason,
                'outcome_by' => $outcome === null ? null : (int) $request->user()->id,
                'outcome_at' => $outcome === null ? null : CarbonImmutable::now(),
            ];
            if ($hintId !== null) {
                $hint = InquiryOrderHint::query()->whereKey($hintId)->where('client_inquiry_id', $row->id)->first();
                if (! $hint instanceof InquiryOrderHint) {
                    throw ValidationException::withMessages(['hint_id' => 'Ta podpowiedź nie należy do tego zapytania.']);
                }
                $changes += [
                    'outcome_document_number' => (string) $hint->document_number,
                    'outcome_document_date' => $hint->issued_at?->toDateString(),
                    'outcome_net_value' => (string) $hint->document_net,
                ];
            } elseif (($hasHintKey && $hintId === null) || ! in_array($outcome, self::ORDERED, true)) {
                // wskazanie dokumentu zdjęte albo wynik bez zakupu — dokument z wyniku znika;
                // bez klucza hint_id i dalej „zamówił” dokument zostaje (zmiana samego powodu albo wyniku)
                $changes += ['outcome_document_number' => null, 'outcome_document_date' => null, 'outcome_net_value' => null];
            }
            $row->forceFill($changes)->save();

            return $row;
        });

        return response()->json($this->inquiries->outcomeView($saved, true));
    }

    public function client(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        $this->assertOwner($request, $inquiry);
        $data = $request->validate([
            'client_id' => ['present', 'nullable', 'integer', 'exists:clients,id'],
        ], [
            'client_id.present' => 'Podaj klienta albo null — zapytanie bez klienta.',
            'client_id.integer' => 'Numer klienta musi być liczbą.',
            'client_id.exists' => 'Nie ma takiego klienta.',
        ]);
        $clientId = $data['client_id'] !== null ? (int) $data['client_id'] : null;
        // klienta wybiera się z listy Klientów — bez prawa jej oglądania można tylko zostawić zapytanie bez klienta
        if ($clientId !== null && ! $request->user()->can('clients.view')) {
            abort(403, 'Brak uprawnienia do listy klientów.');
        }

        $previous = $inquiry->client_id !== null ? (int) $inquiry->client_id : null;
        DB::transaction(static function () use ($inquiry, $clientId, $previous): void {
            app(InquiryClientLinker::class)->link($inquiry, $clientId);
            // podpowiedzi liczono z dokumentów poprzedniego klienta — nowe policzy nocne sprawdzenie
            if ($previous !== $clientId) {
                InquiryOrderHint::query()->where('client_inquiry_id', $inquiry->id)->delete();
            }
        });
        $inquiry->unsetRelation('client');

        return response()->json(['client_link' => InquiryClientLinker::present($inquiry)]);
    }

    private function assertOwner(Request $request, ClientInquiry $inquiry): void
    {
        if ((int) $inquiry->user_id !== (int) $request->user()->id) {
            abort(403, 'Wynik zapytania i klienta zapisuje tylko osoba, która je prowadzi.');
        }
    }
}
