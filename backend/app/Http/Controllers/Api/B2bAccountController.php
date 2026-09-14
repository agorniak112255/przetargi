<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class B2bAccountController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            B2bAccount::query()
                ->with(['creator:id,name', 'updater:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn (B2bAccount $account): array => $this->view($account))
                ->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $account = B2bAccount::query()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->view($account->load(['creator:id,name', 'updater:id,name'])), 201);
    }

    public function update(Request $request, B2bAccount $b2bAccount): JsonResponse
    {
        $data = $this->validated($request, creating: false);

        // Puste hasło w edycji = zostaw zapisane (formularz nie zna starego hasła).
        if (! isset($data['password']) || $data['password'] === '') {
            unset($data['password']);
        }

        $b2bAccount->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->view($b2bAccount->fresh()->load(['creator:id,name', 'updater:id,name'])));
    }

    public function destroy(B2bAccount $b2bAccount): JsonResponse
    {
        $b2bAccount->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST, żeby każde odsłonięcie hasła trafiło do dziennika aktywności.
     */
    public function revealPassword(B2bAccount $b2bAccount): JsonResponse
    {
        try {
            $password = $b2bAccount->password;
        } catch (DecryptException) {
            return response()->json([
                'message' => 'Nie można odszyfrować hasła (zmieniony klucz aplikacji). Zapisz hasło ponownie.',
            ], 422);
        }

        return response()->json(['password' => $password ?? '']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:1000'],
            'sites' => ['required', 'array', 'min:1', 'max:20'],
            // Puste wiersze (ConvertEmptyStringsToNull → null) pomijamy, nie odrzucamy formularza.
            'sites.*' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['sites'] = array_values(array_unique(array_filter(
            array_map(static fn (?string $site): string => trim((string) $site), $data['sites']),
            static fn (string $site): bool => $site !== '',
        )));

        if ($data['sites'] === []) {
            throw ValidationException::withMessages(['sites' => 'Podaj co najmniej jedną witrynę.']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function view(B2bAccount $account): array
    {
        $raw = $account->getRawOriginal('password');

        return [
            'id' => $account->id,
            'username' => $account->username,
            'has_password' => is_string($raw) && $raw !== '',
            'sites' => $account->sites ?? [],
            'note' => $account->note,
            'created_by' => $account->creator?->only(['id', 'name']),
            'updated_by' => $account->updater?->only(['id', 'name']),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
