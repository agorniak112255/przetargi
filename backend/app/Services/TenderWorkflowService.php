<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderStatusHistory;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Validation\ValidationException;

final class TenderWorkflowService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['wycena'],
        'wycena' => ['akceptacja_km', 'draft'],
        'akceptacja_km' => ['akceptacja_dyrektor', 'wycena', 'odrzucony'],
        'akceptacja_dyrektor' => ['zatwierdzona', 'wycena', 'odrzucony'],
        'zatwierdzona' => ['exported', 'archiwum'],
        'exported' => ['archiwum'],
        'odrzucony' => ['wycena'],
        'archiwum' => [],
    ];

    public function canEditOffer(Tender $tender): bool
    {
        return in_array($tender->status, ['draft', 'wycena'], true);
    }

    /**
     * @return list<string>
     */
    public function nextStatusesFor(Tender $tender, User $user): array
    {
        $candidates = self::TRANSITIONS[$tender->status] ?? [];

        return array_values(array_filter(
            $candidates,
            static fn (string $status): bool => $user->can(PermissionCatalog::transitionPermission($status))
        ));
    }

    public function transition(Tender $tender, string $toStatus, User $user, ?string $note = null): Tender
    {
        $from = $tender->status;
        $allowed = self::TRANSITIONS[$from] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Niedozwolone przejście: {$from} → {$toStatus}."],
            ]);
        }

        if (! $user->can(PermissionCatalog::transitionPermission($toStatus))) {
            throw ValidationException::withMessages([
                'status' => ['Brak uprawnień do tej zmiany statusu.'],
            ]);
        }

        $noteRequired = $toStatus === 'odrzucony'
            || (
                in_array($from, ['akceptacja_km', 'akceptacja_dyrektor'], true)
                && $toStatus === 'wycena'
            );
        if ($noteRequired && ($note === null || mb_strlen(trim($note)) < 5)) {
            throw ValidationException::withMessages([
                'note' => ['Wymagana notatka (min. 5 znaków) przy odrzuceniu lub cofnięciu z akceptacji.'],
            ]);
        }

        $tender->status = $toStatus;
        $tender->last_activity_at = now();
        $tender->save();

        TenderStatusHistory::query()->create([
            'tender_id' => $tender->id,
            'from_status' => $from,
            'to_status' => $toStatus,
            'user_id' => $user->id,
            'note' => $note,
        ]);

        return $tender->fresh();
    }
}
