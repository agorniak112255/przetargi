<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfferComposeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * „Otwórz w Thunderbirdzie” z okna oferty dla klienta. Przeglądarka zostawia treść oferty na serwerze, dodatek
 * do Thunderbirda odbiera ją przy pytaniu o kolejkę (ClientInquiryController::queued z with_offers=1), podejmuje
 * i otwiera nowego maila. Okno oferty pyta o stan prośby, żeby powiedzieć handlowcowi, czy Thunderbird ją odebrał.
 */
class OfferComposeController extends Controller
{
    /**
     * Dodatek pyta najrzadziej co 30 s, a znacznik odświeżamy najwyżej raz na minutę — 3 minuty ciszy znaczą,
     * że Thunderbird jest zamknięty albo dodatek jest starszy niż 1.24.0.
     */
    public const ADDON_ACTIVE_MINUTES = 3;

    /** Treść oferty to kilkadziesiąt kB HTML; limit chroni bazę przed przypadkowym wklejeniem czegoś ogromnego. */
    private const MAX_HTML_BYTES = 600_000;

    /** Czy dodatek do Thunderbirda umiejący otwierać oferty odezwał się niedawno — od tego zależy przycisk w oknie. */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $seen = $user->thunderbird_offers_seen_at;

        return response()->json([
            'addon_ready' => $seen !== null && $seen->gte(now()->subMinutes(self::ADDON_ACTIVE_MINUTES)),
            'addon_seen_at' => $seen?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:'.self::MAX_HTML_BYTES],
            'body_text' => ['nullable', 'string', 'max:100000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ], [
            'body_html.max' => 'Oferta jest za duża, żeby przekazać ją do Thunderbirda — skopiuj ją i wklej ręcznie.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $row = OfferComposeRequest::query()->create([
            'user_id' => $user->id,
            'product_id' => $data['product_id'] ?? null,
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'body_text' => $data['body_text'] ?? null,
            'requested_at' => now(),
        ]);

        return response()->json($this->present($row), 201);
    }

    /** Stan prośby dla okna oferty: czy dodatek już ją podjął. */
    public function show(Request $request, OfferComposeRequest $offerCompose): JsonResponse
    {
        $this->assertOwner($request, $offerCompose);

        return response()->json($this->present($offerCompose));
    }

    /**
     * Dodatek podejmuje prośbę przed otwarciem okna. Jeden zapis warunkowy: przy dwóch Thunderbirdach na tym samym
     * koncie ofertę otworzy tylko pierwszy, drugi dostaje 409.
     */
    public function claim(Request $request, OfferComposeRequest $offerCompose): JsonResponse
    {
        $this->assertOwner($request, $offerCompose);

        $claimed = OfferComposeRequest::query()
            ->whereKey($offerCompose->id)
            ->whereNull('claimed_at')
            ->update(['claimed_at' => now()]);
        if ($claimed === 0) {
            return response()->json(['message' => 'Tę ofertę otworzył już inny Thunderbird.'], 409);
        }

        return response()->json($this->present($offerCompose->refresh()));
    }

    /**
     * @return array{id: int, subject: string, requested_at: ?string, claimed_at: ?string}
     */
    private function present(OfferComposeRequest $row): array
    {
        return [
            'id' => $row->id,
            'subject' => $row->subject,
            'requested_at' => $row->requested_at?->toIso8601String(),
            'claimed_at' => $row->claimed_at?->toIso8601String(),
        ];
    }

    private function assertOwner(Request $request, OfferComposeRequest $row): void
    {
        if ((int) $row->user_id !== (int) $request->user()->id) {
            abort(403, 'Brak dostępu do tej oferty.');
        }
    }
}
