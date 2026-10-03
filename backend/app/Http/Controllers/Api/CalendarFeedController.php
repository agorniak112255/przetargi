<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Osobisty adres kalendarza (ICS): GET|POST|DELETE /me/calendar-feed. Klucz pokazany raz przy wygenerowaniu, w bazie
 * tylko skrót SHA-256; nowy adres unieważnia stary. Strumień A.
 */
class CalendarFeedController extends Controller
{
    /** Długość tajnego klucza w adresie — trasa przyjmuje dokładnie [A-Za-z0-9]{40}. */
    public const TOKEN_LENGTH = 40;

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request->user()));
    }

    /**
     * Nowy adres: stary klucz przestaje działać od razu (w bazie jest tylko skrót nowego). Adres wraca tylko w tej
     * odpowiedzi — potem nie da się go odczytać, można jedynie wygenerować kolejny.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:mine,all'],
        ], [
            'scope.required' => 'Wybierz, które przetargi mają trafić do kalendarza.',
            'scope.in' => 'Nieznany zakres. Dozwolone: mine (moje), all (wszystkie).',
        ]);
        $user = $request->user();
        if ($data['scope'] === 'all' && ! $user->can('tenders.view_all')) {
            throw ValidationException::withMessages([
                'scope' => ['Wszystkie przetargi może dodać do kalendarza tylko osoba, która może oglądać wszystkie przetargi.'],
            ]);
        }

        // Str::random daje tylko litery A–Z, a–z i cyfry — jak wzorzec trasy
        $token = Str::random(self::TOKEN_LENGTH);
        $user->forceFill([
            'calendar_token_hash' => hash('sha256', $token),
            'calendar_scope' => $data['scope'],
            'calendar_created_at' => now(),
            'calendar_used_at' => null,
        ])->save();

        return response()->json([
            ...$this->state($user),
            'url' => rtrim((string) config('app.url'), '/').'/api/calendar/'.$token.'.ics',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'calendar_token_hash' => null,
            'calendar_scope' => null,
            'calendar_created_at' => null,
            'calendar_used_at' => null,
        ])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{active: bool, scope: string|null, created_at: string|null, used_at: string|null}
     */
    private function state(User $user): array
    {
        $active = $user->calendar_token_hash !== null;

        return [
            'active' => $active,
            'scope' => $active ? $user->calendar_scope : null,
            'created_at' => $active ? $user->calendar_created_at?->toIso8601String() : null,
            'used_at' => $active ? $user->calendar_used_at?->toIso8601String() : null,
        ];
    }
}
