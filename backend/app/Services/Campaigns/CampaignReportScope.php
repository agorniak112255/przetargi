<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kto czyje kampanie widzi w raporcie „Wynik kampanii”:
 * - all  — uprawnienie reports.campaigns.all (wszyscy autorzy kampanii);
 * - team — kierownik co najmniej jednego zespołu (user_team_members.is_leader): członkowie jego zespołów i on sam;
 * - own  — sam autor: ma campaigns.use albo co najmniej jedną kampanię po starcie wysyłki;
 * - null — brak dostępu (403).
 * campaigns.view / campaigns.manage NIE poszerzają zakresu tego raportu.
 *
 * Wołane przy każdym /me (User::toAuthArray) — uprawnienia są już wczytane, więc najwyżej dwa lekkie zapytania:
 * członkowie zespołów kierownika i (tylko bez campaigns.use) istnienie wysłanej kampanii.
 *
 * KONTRAKT (zamrożony 04.10.2026).
 */
final class CampaignReportScope
{
    public const ALL = 'all';

    public const TEAM = 'team';

    public const OWN = 'own';

    /**
     * @return array{mode: 'all'|'team'|'own', user_ids: list<int>|null}|null user_ids null = wszyscy (mode all);
     *                                                                        null = brak dostępu
     */
    public function for(User $user): ?array
    {
        if ($user->can('reports.campaigns.all')) {
            return ['mode' => self::ALL, 'user_ids' => null];
        }

        $selfId = (int) $user->getKey();

        // Członkowie wszystkich zespołów, w których osoba jest kierownikiem (jedno zapytanie, podzapytanie w WHERE).
        $memberIds = DB::table('user_team_members')
            ->whereIn('team_id', DB::table('user_team_members')
                ->select('team_id')
                ->where('user_id', $selfId)
                ->where('is_leader', true))
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($memberIds !== []) {
            $ids = array_values(array_unique([...$memberIds, $selfId]));
            sort($ids);

            return ['mode' => self::TEAM, 'user_ids' => $ids];
        }

        if ($user->can('campaigns.use') || Campaign::query()
            ->where('user_id', $selfId)
            ->whereNotNull('sending_started_at')
            ->exists()) {
            return ['mode' => self::OWN, 'user_ids' => [$selfId]];
        }

        return null;
    }

    /** Czy osoba `$userId` mieści się w zakresie (filtr osoby w raporcie). */
    public function allows(User $viewer, int $userId): bool
    {
        $scope = $this->for($viewer);
        if ($scope === null) {
            return false;
        }

        if ($scope['user_ids'] === null) {
            return User::query()->whereKey($userId)->exists();
        }

        return in_array($userId, $scope['user_ids'], true);
    }
}
