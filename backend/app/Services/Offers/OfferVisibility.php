<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\Role;
use App\Models\User;

/**
 * Czyje oferty widzi konto (decyzja właściciela 06.10.2026): swoje zawsze; offers.view_all — wszystkie;
 * offers.view_selected — także osób wybranych w ustawieniach każdej z jego ról, która ma to uprawnienie
 * (roles.offer_visible_user_ids). Uprawnienie nadane wprost na konto (bez roli) nie ma listy osób — daje tylko swoje.
 * Cudze oferty są tylko do podglądu; zmienia i wysyła autor (OfferController).
 */
final class OfferVisibility
{
    public const VIEW_ALL = 'offers.view_all';

    public const VIEW_SELECTED = 'offers.view_selected';

    /** @return list<int>|null numery autorów, których oferty widzi konto; null = wszystkich */
    public static function authorIds(User $user): ?array
    {
        if ($user->can(self::VIEW_ALL)) {
            return null;
        }
        $ids = [(int) $user->id];
        if ($user->can(self::VIEW_SELECTED)) {
            foreach ($user->roles as $role) {
                /** @var Role $role */
                if (! $role->hasPermissionTo(self::VIEW_SELECTED)) {
                    continue;
                }
                foreach (self::roleUserIds($role) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public static function canView(User $user, int $authorId): bool
    {
        $ids = self::authorIds($user);

        return $ids === null || in_array($authorId, $ids, true);
    }

    /** @return list<int> osoby wybrane w ustawieniach roli */
    public static function roleUserIds(Role $role): array
    {
        $raw = $role->getAttribute('offer_visible_user_ids');
        $ids = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($ids) ? array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric')))) : [];
    }
}
