<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** Sesja bez zapytania, sygnału obecności i logowania przez tyle dni jest „stara” (można ją wylogować). */
    public const STALE_DAYS = 30;

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
        $staleBefore = $now->copy()->subDays(self::STALE_DAYS);

        $onlineIds = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('presence_at', '>=', $activeSince)
            ->distinct()
            ->pluck('tokenable_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        $users = User::query()
            ->with('roles')
            ->withCount([
                'tokens',
                'tokens as recent_tokens_count' => static function (Builder $query) use ($staleBefore): void {
                    self::whereRecent($query, $staleBefore);
                },
            ])
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
                'recent_sessions_count' => (int) $user->recent_tokens_count,
                'stale_sessions_count' => (int) $user->tokens_count - (int) $user->recent_tokens_count,
            ])->values(),
            'meta' => [
                'active_minutes' => self::ACTIVE_MINUTES,
                'stale_days' => self::STALE_DAYS,
                'generated_at' => $now->toIso8601String(),
            ],
        ]);
    }

    /**
     * Wylogowuje stare sesje: usuwa ich tokeny, więc zostawiona przeglądarka albo dodatek w Thunderbirdzie
     * przy następnym wejściu poprosi o logowanie. Usuwa dokładnie to, co tabela użytkowników liczy jako stare.
     * Bieżąca sesja nigdy nie jest stara — Sanctum zapisał jej użycie przed wejściem do kontrolera.
     */
    public function destroyStale(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        $staleBefore = now()->subDays(self::STALE_DAYS);
        $staleTokens = static function () use ($staleBefore): Builder {
            $query = PersonalAccessToken::query()->where('tokenable_type', (new User)->getMorphClass());
            self::whereStale($query, $staleBefore);

            return $query;
        };

        $userIds = $staleTokens()
            ->whereIn('tokenable_id', User::query()->select('id'))
            ->distinct()
            ->pluck('tokenable_id');

        // Osobno dla każdego konta i z warunkiem powtórzonym przy usuwaniu: sesja, która ożyła od odczytu,
        // zostaje, a liczby w dzienniku to faktycznie usunięte tokeny.
        $removed = [];
        foreach ($userIds as $userId) {
            $count = $staleTokens()->where('tokenable_id', $userId)->delete();
            if ($count > 0) {
                $removed[(int) $userId] = $count;
            }
        }

        $total = array_sum($removed);
        $owners = User::query()->whereKey(array_keys($removed))->get(['id', 'name', 'email'])->keyBy('id');

        $activityLogger->log(
            action: 'sessions.stale_revoked',
            user: $request->user(),
            meta: [
                'label' => 'Wylogowanie starych sesji: '.$total,
                'count' => $total,
                'stale_days' => self::STALE_DAYS,
                'users' => array_map(static fn (int $userId, int $count): array => [
                    'id' => $userId,
                    'name' => (string) $owners->get($userId)?->name,
                    'email' => (string) $owners->get($userId)?->email,
                    'count' => $count,
                ], array_keys($removed), array_values($removed)),
            ],
            request: $request,
        );

        return response()->json([
            'deleted' => $total,
            'stale_days' => self::STALE_DAYS,
        ]);
    }

    /**
     * Sesja z ruchem od $since: zapytanie z tym tokenem, sygnał obecności albo samo logowanie.
     *
     * @param  Builder<PersonalAccessToken>  $query
     */
    private static function whereRecent(Builder $query, CarbonInterface $since): void
    {
        $query->where(static function (Builder $query) use ($since): void {
            $query->where($query->qualifyColumn('last_used_at'), '>=', $since)
                ->orWhere($query->qualifyColumn('presence_at'), '>=', $since)
                ->orWhere($query->qualifyColumn('created_at'), '>=', $since);
        });
    }

    /**
     * Dokładne dopełnienie whereRecent: pusty znacznik czasu znaczy „nie było ruchu”. Samo NOT (whereRecent)
     * pominęłoby wiersz z samymi pustymi znacznikami, bo porównanie z NULL nie daje ani prawdy, ani fałszu.
     *
     * @param  Builder<PersonalAccessToken>  $query
     */
    private static function whereStale(Builder $query, CarbonInterface $before): void
    {
        foreach (['last_used_at', 'presence_at', 'created_at'] as $column) {
            $query->where(static function (Builder $query) use ($column, $before): void {
                $query->whereNull($query->qualifyColumn($column))
                    ->orWhere($query->qualifyColumn($column), '<', $before);
            });
        }
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
