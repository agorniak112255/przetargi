<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderStatusHistory;
use App\Models\User;
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

    /** @var array<string, list<string>> */
    private const ROLE_CAN_SET = [
        'wycena' => ['handlowiec', 'przetargi', 'kierownik', 'admin'],
        'akceptacja_km' => ['handlowiec', 'kierownik', 'admin'],
        'akceptacja_dyrektor' => ['kierownik', 'admin'],
        'zatwierdzona' => ['dyrektor', 'admin'],
        'exported' => ['handlowiec', 'kierownik', 'admin', 'dyrektor'],
        'archiwum' => ['kierownik', 'admin', 'dyrektor'],
        'odrzucony' => ['kierownik', 'dyrektor', 'admin'],
        'draft' => ['handlowiec', 'przetargi', 'kierownik', 'admin'],
    ];

    public function canEditOffer(Tender $tender): bool
    {
        return in_array($tender->status, ['draft', 'wycena'], true);
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

        $roles = self::ROLE_CAN_SET[$toStatus] ?? [];
        if (! in_array($user->role, $roles, true)) {
            throw ValidationException::withMessages([
                'status' => ['Brak uprawnień do tej zmiany statusu.'],
            ]);
        }

        if ($toStatus === 'akceptacja_km' && $from === 'wycena') {
            $pending = $tender->items()
                ->whereNotNull('main_product_id')
                ->get()
                ->pluck('main_product_id');

            // optional soft check - no hard block
            unset($pending);
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

        return $tender->fresh(['statusHistories.user']);
    }
}
