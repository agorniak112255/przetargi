<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NetworkAccessGrant;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aktywne dostępy spoza sieci potwierdzone kodem e-mailem (Administracja → Role, pod adresami sieci lokalnej).
 * Odebranie ustawia koniec ważności na teraz — wiersz zostaje, kto odebrał zapisuje dziennik aktywności.
 */
class NetworkAccessGrantController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(): JsonResponse
    {
        $grants = NetworkAccessGrant::query()
            ->with('user:id,name,email')
            ->where('expires_at', '>', now())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->whereHas('user')
            ->get()
            ->map(static fn (NetworkAccessGrant $grant): array => [
                'id' => $grant->id,
                'user' => $grant->user === null ? null : [
                    'id' => $grant->user->id,
                    'name' => $grant->user->name,
                    'email' => $grant->user->email,
                ],
                'ip' => $grant->ip,
                // od ostatniego potwierdzenia kodem (kolejny kod z tego adresu przedłuża ten sam wpis)
                'created_at' => $grant->updated_at?->toIso8601String(),
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['grants' => $grants]);
    }

    public function destroy(Request $request, NetworkAccessGrant $grant): JsonResponse
    {
        if ($grant->expires_at !== null && $grant->expires_at->isFuture()) {
            $grant->forceFill(['expires_at' => now()])->save();
            $grant->loadMissing('user:id,name,email');
            $this->activityLogger->log(
                action: 'network_grant_revoked',
                user: $request->user(),
                subject: $grant,
                meta: [
                    'label' => 'Odebrany dostęp spoza sieci (kod e-mailem)',
                    'grant_user' => $grant->user?->email,
                    'grant_ip' => $grant->ip,
                ],
                request: $request,
            );
        }

        return response()->json(['ok' => true]);
    }
}
