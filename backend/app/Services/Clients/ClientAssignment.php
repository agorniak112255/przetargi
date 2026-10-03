<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;

/**
 * Do którego handlowca należy klient (cele handlowców, karta klienta, stan systemu) — według dzisiejszego opiekuna:
 *  1) opiekun z karty w ERP XL (clients.xl_manager_gid), gdy administrator przypisał tego pracownika XL do konta
 *     (users.erp_employee_gid) — źródło „xl”,
 *  2) inaczej opiekun w aplikacji (clients.owner_id) — źródło „app”,
 *  3) inaczej bez opiekuna (user_id i source null).
 */
final class ClientAssignment
{
    public const SOURCE_XL = 'xl';

    public const SOURCE_APP = 'app';

    /**
     * @param  list<int>|null  $clientIds  null = wszyscy klienci
     * @return array<int, array{user_id: int|null, source: 'xl'|'app'|null}> po id klienta
     */
    public function forClients(?array $clientIds = null): array
    {
        /** @var array<int, int> $userByEmployee */
        $userByEmployee = User::query()
            ->whereNotNull('erp_employee_gid')
            ->pluck('id', 'erp_employee_gid')
            ->mapWithKeys(static fn (mixed $id, mixed $gid): array => [(int) $gid => (int) $id])
            ->all();

        $out = [];
        $chunks = $clientIds === null ? [null] : array_chunk(array_values(array_unique(array_map('intval', $clientIds))), 500);
        foreach ($chunks as $chunk) {
            $query = Client::query()->select(['id', 'xl_manager_gid', 'owner_id']);
            if ($chunk !== null) {
                $query->whereIn('id', $chunk);
            }
            foreach ($query->toBase()->get() as $row) {
                $out[(int) $row->id] = self::resolve(
                    $row->xl_manager_gid === null ? null : (int) $row->xl_manager_gid,
                    $row->owner_id === null ? null : (int) $row->owner_id,
                    $userByEmployee,
                );
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $userByEmployee  pracownik XL → użytkownik
     * @return array{user_id: int|null, source: 'xl'|'app'|null}
     */
    private static function resolve(?int $managerGid, ?int $ownerId, array $userByEmployee): array
    {
        if ($managerGid !== null && isset($userByEmployee[$managerGid])) {
            return ['user_id' => $userByEmployee[$managerGid], 'source' => self::SOURCE_XL];
        }
        if ($ownerId !== null) {
            return ['user_id' => $ownerId, 'source' => self::SOURCE_APP];
        }

        return ['user_id' => null, 'source' => null];
    }
}
