<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Aktywne sesje: sesja = token Sanctum. Obecność (presence_at, presence_path) ustawia sygnał z frontendu,
 * last_used_at aktualizuje Sanctum przy każdym zapytaniu.
 */
class SessionController extends Controller
{
    /** Sygnał obecności młodszy niż tyle minut = status „aktywny”. */
    public const ACTIVE_MINUTES = 3;

    /** Sesje bez obecności ani zapytania w tym oknie nie są pokazywane. */
    public const WINDOW_MINUTES = 15;

    public function index(): JsonResponse
    {
        $now = now();
        $activeSince = $now->copy()->subMinutes(self::ACTIVE_MINUTES);
        $windowSince = $now->copy()->subMinutes(self::WINDOW_MINUTES);
        $userMorph = (new User)->getMorphClass();

        $tokens = PersonalAccessToken::query()
            ->select(['id', 'tokenable_id', 'ip_address', 'user_agent', 'presence_path', 'presence_at', 'last_used_at', 'created_at'])
            ->withCasts(['presence_at' => 'datetime'])
            ->where('tokenable_type', $userMorph)
            ->whereIn('tokenable_id', User::query()->select('id'))
            ->where(function ($query) use ($windowSince): void {
                $query->where('presence_at', '>=', $windowSince)
                    ->orWhere('last_used_at', '>=', $windowSince);
            })
            ->get();

        $users = User::query()
            ->with('roles')
            ->whereIn('id', $tokens->pluck('tokenable_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $rows = $tokens
            ->filter(static fn (PersonalAccessToken $token): bool => $users->has($token->tokenable_id))
            ->map(static function (PersonalAccessToken $token) use ($users, $activeSince): array {
                /** @var User $user */
                $user = $users->get($token->tokenable_id);
                $presenceAt = $token->presence_at;
                $lastUsedAt = $token->last_used_at;
                $isActive = $presenceAt !== null && $presenceAt->greaterThanOrEqualTo($activeSince);
                $lastActivity = self::latest($presenceAt, $lastUsedAt);

                return [
                    'sort_active' => $isActive ? 1 : 0,
                    'sort_time' => $lastActivity?->getTimestamp() ?? 0,
                    'row' => [
                        'token_id' => (int) $token->id,
                        'user' => self::userSummary($user),
                        'ip_address' => $token->ip_address,
                        'user_agent' => $token->user_agent,
                        'path' => $token->presence_path,
                        'presence_at' => $presenceAt?->toIso8601String(),
                        'last_used_at' => $lastUsedAt?->toIso8601String(),
                        'created_at' => $token->created_at?->toIso8601String(),
                        'status' => $isActive ? 'active' : 'idle',
                    ],
                ];
            })
            ->sort(static fn (array $a, array $b): int => [$b['sort_active'], $b['sort_time'], $b['row']['token_id']]
                <=> [$a['sort_active'], $a['sort_time'], $a['row']['token_id']])
            ->map(static fn (array $item): array => $item['row'])
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'active_minutes' => self::ACTIVE_MINUTES,
                'window_minutes' => self::WINDOW_MINUTES,
                'generated_at' => $now->toIso8601String(),
            ],
        ]);
    }

    public function users(): JsonResponse
    {
        $now = now();
        $activeSince = $now->copy()->subMinutes(self::ACTIVE_MINUTES);

        $onlineIds = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('presence_at', '>=', $activeSince)
            ->distinct()
            ->pluck('tokenable_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        $users = User::query()
            ->with('roles')
            ->withCount('tokens')
            ->orderByRaw('last_seen_at IS NULL')
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(static fn (User $user): array => [
                ...self::userSummary($user),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
                'online' => $onlineIds->has((int) $user->id),
                'sessions_count' => (int) $user->tokens_count,
            ])->values(),
            'meta' => [
                'active_minutes' => self::ACTIVE_MINUTES,
                'generated_at' => $now->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string}
     */
    private static function userSummary(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => (string) ($user->getRoleNames()->first() ?? $user->role),
        ];
    }

    private static function latest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
