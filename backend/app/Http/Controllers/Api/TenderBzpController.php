<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\Bzp\BzpTenderLinker;
use App\Services\Tenders\TenderResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * „Sprawdź w Biuletynie” (POST /tenders/{tender}/result/bzp-check) — dopasowanie przetargu do ogłoszeń już
 * zapisanych w bazie (bzp:fetch pobiera je codziennie), bez zapytań do Biuletynu. Odpowiedź jak GET wyniku
 * (TenderResultResponse) z komunikatem bzp_message i sprzecznościami ogłoszenia z wpisem ręcznym w bzp_conflict
 * części. Uprawnienia: trasa w grupie przetargu (tender.access) + tenders.edit_offer.
 */
class TenderBzpController extends Controller
{
    public function check(Request $request, Tender $tender, BzpTenderLinker $linker, TenderResultService $results): JsonResponse
    {
        $user = $request->user();
        $link = $linker->link($tender, $user);

        return response()->json([
            ...$results->payload($tender->refresh(), $user, $link['conflicts']),
            'bzp_message' => $link['message'],
        ]);
    }
}
