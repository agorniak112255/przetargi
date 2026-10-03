<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competitor;
use App\Support\CompanyName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Podpowiedzi firm konkurencji (GET /competitors?q=) do formularza wyniku przetargu — najwyżej 20.
 *
 * Szukanie po kluczu nazwy (CompanyName::key: bez wielkości liter, polskich znaków i form prawnych, więc
 * „BHP-Pro sp. z o.o.” znajdzie „Bhp Pro”) albo po początku NIP (same cyfry). Najpierw firmy, których nazwa
 * zaczyna się od wpisanego tekstu. Bez tekstu — ostatnio dopisane lub zmienione firmy.
 */
class CompetitorController extends Controller
{
    private const LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $query = Competitor::query()->select(['id', 'name', 'nip']);

        if ($q === '') {
            return response()->json(['data' => $query->latest('updated_at')->latest('id')->limit(self::LIMIT)->get()]);
        }

        // klucz nazwy nie ma znaków specjalnych LIKE, ale dla pewności je usuwamy
        $key = str_replace(['%', '_', '\\'], '', CompanyName::key($q));
        $digits = (string) preg_replace('/\D+/', '', $q);
        // NIP tylko, gdy wpisano same cyfry (z kreskami / spacjami / przedrostkiem PL), np. „118-16”
        $looksLikeNip = strlen($digits) >= 3 && preg_match('/^(PL)?[\d\s\-]+$/i', $q) === 1;

        if ($key === '' && ! $looksLikeNip) {
            return response()->json(['data' => []]);
        }

        $query->where(static function ($where) use ($key, $digits, $looksLikeNip): void {
            if ($key !== '') {
                $where->orWhere('name_key', 'like', '%'.$key.'%');
            }
            if ($looksLikeNip) {
                $where->orWhere('nip', 'like', $digits.'%');
            }
        });

        if ($key !== '') {
            $query->orderByRaw('CASE WHEN name_key LIKE ? THEN 0 ELSE 1 END', [$key.'%']);
        }

        return response()->json(['data' => $query->orderBy('name')->orderBy('id')->limit(self::LIMIT)->get()]);
    }
}
