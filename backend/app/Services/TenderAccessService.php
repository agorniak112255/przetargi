<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tender;
use App\Models\User;

final class TenderAccessService
{
    public function canView(User $user, Tender $tender): bool
    {
        if ($user->can('tenders.view_all')) {
            return true;
        }

        if (! $user->can('tenders.view_own')) {
            return false;
        }

        return $this->isOwnerOrInvitee($user, $tender);
    }

    public function isOwnerOrInvitee(User $user, Tender $tender): bool
    {
        if ((int) $tender->owner_id === (int) $user->id) {
            return true;
        }

        return $tender->invitations()
            ->where('user_id', $user->id)
            ->exists();
    }
}
