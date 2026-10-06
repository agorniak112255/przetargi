<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerEmailLookup;
use App\Models\CustomerEmailSuggestion;
use App\Models\User;
use App\Services\Inspections\CustomerEmailFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adresy e-mail klienta znalezione w sieci (Przeglądy, prośba właściciela 06.10.2026): szukanie na żądanie
 * („Szukaj adresu w sieci” w szczegółach klienta) i decyzja człowieka — „Użyj” (adres trafia do ofert jak adres
 * z karty XL) albo „Odrzuć” (nie wraca). Szukanie nocne: inspections:find-emails.
 */
class InspectionEmailController extends Controller
{
    public function search(CustomerEmailFinder $finder, int $xlGid): JsonResponse
    {
        $customer = DB::table('erp_customers')->where('xl_gid', $xlGid)->first(['xl_gid', 'acronym', 'name', 'nip', 'city', 'emails']);
        if ($customer === null) {
            abort(404, 'Klienta nie ma w kartotece ERP XL odczytanej przez aplikację.');
        }
        // jedno szukanie naraz na klienta (dwa kliknięcia nie czytają stron podwójnie)
        $lock = Cache::lock('customer-email-search:'.$xlGid, 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['customer' => ['Szukanie adresu tego klienta już trwa — poczekaj na wynik.']]);
        }
        try {
            @set_time_limit(240);
            $result = $finder->find($customer);
        } finally {
            $lock->release();
        }

        return response()->json([
            'found' => $result['found'],
            'error' => $result['error'] !== null ? 'Wyszukiwarka nie odpowiedziała — spróbuj później.' : null,
            // sprawdzone strony: serwis, czy dało się przeczytać, czy był NIP klienta, ile nowych adresów
            'pages' => array_map(static fn (array $p): array => [
                'host' => $p['host'], 'url' => $p['url'], 'read' => $p['read'], 'nip' => $p['nip'], 'emails' => $p['emails'],
            ], $result['pages']),
            'suggestions' => self::suggestions($xlGid),
            'lookup' => self::lookup($xlGid),
        ]);
    }

    public function decide(Request $request, CustomerEmailSuggestion $suggestion): JsonResponse
    {
        $v = $request->validate([
            'status' => ['required', 'string', Rule::in([
                CustomerEmailSuggestion::STATUS_ACCEPTED, CustomerEmailSuggestion::STATUS_REJECTED, CustomerEmailSuggestion::STATUS_PENDING,
            ])],
        ], [
            'status.required' => 'Wybierz: użyj albo odrzuć.',
            'status.in' => 'Wybierz: użyj albo odrzuć.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $pending = $v['status'] === CustomerEmailSuggestion::STATUS_PENDING;
        $suggestion->forceFill([
            'status' => $v['status'],
            'decided_by' => $pending ? null : $user->id,
            'decided_at' => $pending ? null : Carbon::now(),
        ])->save();

        return response()->json(['suggestions' => self::suggestions((int) $suggestion->customer_xl_gid)]);
    }

    /** @return list<array<string, mixed>> propozycje klienta: oczekujące, potem zatwierdzone, potem odrzucone */
    public static function suggestions(int $xlGid): array
    {
        $order = [CustomerEmailSuggestion::STATUS_PENDING => 0, CustomerEmailSuggestion::STATUS_ACCEPTED => 1, CustomerEmailSuggestion::STATUS_REJECTED => 2];

        return CustomerEmailSuggestion::query()
            ->where('customer_xl_gid', $xlGid)
            ->with('decider:id,name')
            ->orderBy('id')
            ->get()
            ->sortBy(static fn (CustomerEmailSuggestion $s): array => [$order[$s->status] ?? 3, $s->evidence === CustomerEmailSuggestion::EVIDENCE_NIP ? 0 : 1, (int) $s->id])
            ->map(static fn (CustomerEmailSuggestion $s): array => [
                'id' => (int) $s->id,
                'email' => (string) $s->email,
                'source' => (string) $s->source,
                'source_url' => $s->source_url,
                'source_host' => $s->source_host,
                'evidence' => (string) $s->evidence,
                'status' => (string) $s->status,
                'decided_by_name' => $s->decider?->name,
                'decided_at' => $s->decided_at?->toIso8601String(),
                'found_at' => $s->found_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array{checked_at: string|null, found: int, error: bool}|null ostatnie szukanie w sieci */
    public static function lookup(int $xlGid): ?array
    {
        $l = CustomerEmailLookup::query()->where('customer_xl_gid', $xlGid)->first();

        return $l === null ? null : [
            'checked_at' => $l->checked_at?->toIso8601String(),
            'found' => (int) $l->found,
            'error' => $l->error !== null,
        ];
    }
}
