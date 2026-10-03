<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Moje konto › Powiadomienia (GET/PUT /me/notification-preferences): zdarzenie × dzwonek / e-mail i momenty
 * przypomnienia o terminie składania ofert. Zapisujemy tylko różnice od wartości domyślnych z config/notifications.php.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return response()->json($this->preferences->payload($user));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $data = $request->validate([
            'events' => ['sometimes', 'array'],
            'events.*' => ['array'],
            'events.*.bell' => ['sometimes', 'boolean'],
            'events.*.mail' => ['sometimes', 'boolean'],
            'deadline_offsets' => ['sometimes', 'array'],
            'deadline_offsets.*' => ['string', 'distinct', Rule::in(array_keys($this->preferences->offsetOptions()))],
        ]);

        $events = $data['events'] ?? [];
        $unknown = array_diff(array_keys($events), array_keys($this->preferences->events()));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'events' => 'Nieznane zdarzenie: '.implode(', ', $unknown).'.',
            ]);
        }

        $this->preferences->update($user, $data);

        return response()->json($this->preferences->payload($user->refresh()));
    }
}
