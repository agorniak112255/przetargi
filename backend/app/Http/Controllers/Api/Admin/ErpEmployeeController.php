<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Pracownicy ERP XL (opiekunowie klientów, clients.xl_manager_gid) do przypisania kontom w Administracji →
 * Użytkownicy (users.erp_employee_gid) — od tego zależy, czyja jest sprzedaż klienta w celach handlowców.
 * GET /admin/erp-employees (admin.users.manage).
 *
 * Lista z klientów zakładki Klienci (erp:clients zapisuje numer, nazwisko i e-mail opiekuna z XL) plus pracownicy
 * już przypisani kontom, choćby bez klientów. suggested_user = konto z tym samym adresem e-mail co pracownik w XL —
 * tylko propozycja dla administratora, nic nie przypisuje się samo.
 */
class ErpEmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var array<int, array{clients: int, names: array<string, int>, emails: array<string, int>}> $employees */
        $employees = [];
        // płasko i bez GROUP BY (MariaDB z ONLY_FULL_GROUP_BY) — kilkaset klientów
        $rows = Client::query()
            ->whereNotNull('xl_manager_gid')
            ->select(['xl_manager_gid', 'account_manager', 'account_manager_email'])
            ->toBase()
            ->cursor();
        foreach ($rows as $row) {
            $gid = (int) $row->xl_manager_gid;
            $employees[$gid] ??= ['clients' => 0, 'names' => [], 'emails' => []];
            $employees[$gid]['clients']++;
            $name = trim((string) $row->account_manager);
            if ($name !== '') {
                $employees[$gid]['names'][$name] = ($employees[$gid]['names'][$name] ?? 0) + 1;
            }
            $email = mb_strtolower(trim((string) $row->account_manager_email));
            if ($email !== '') {
                $employees[$gid]['emails'][$email] = ($employees[$gid]['emails'][$email] ?? 0) + 1;
            }
        }

        $users = User::query()->orderBy('id')->get(['id', 'name', 'email', 'erp_employee_gid']);
        /** @var array<int, array{id: int, name: string}> $byEmployee */
        $byEmployee = [];
        /** @var array<string, list<User>> $byEmail */
        $byEmail = [];
        foreach ($users as $user) {
            if ($user->erp_employee_gid !== null) {
                $byEmployee[(int) $user->erp_employee_gid] = ['id' => (int) $user->id, 'name' => (string) $user->name];
                // przypisany pracownik bez klientów też na liście — inaczej panel nie pokazałby bieżącego powiązania
                $employees[(int) $user->erp_employee_gid] ??= ['clients' => 0, 'names' => [], 'emails' => []];
            }
            $byEmail[mb_strtolower(trim((string) $user->email))][] = $user;
        }

        $data = [];
        foreach ($employees as $gid => $e) {
            $name = self::mostCommon($e['names']);
            $email = self::mostCommon($e['emails']);
            $assigned = $byEmployee[$gid] ?? null;
            $data[] = [
                'gid' => $gid,
                'name' => $name,
                'email' => $email,
                'clients' => $e['clients'],
                'user' => $assigned,
                'suggested_user' => $assigned === null ? self::suggest($email, $byEmail) : null,
            ];
        }
        usort($data, static fn (array $a, array $b): int => [$b['clients'], (string) $a['name'], $a['gid']] <=> [$a['clients'], (string) $b['name'], $b['gid']]);

        return response()->json(['data' => $data]);
    }

    /**
     * Konto z tym samym e-mailem co pracownik w XL — tylko gdy jest dokładnie jedno i nie ma jeszcze innego pracownika.
     *
     * @param  array<string, list<User>>  $byEmail
     * @return array{id: int, name: string}|null
     */
    private static function suggest(?string $email, array $byEmail): ?array
    {
        $candidates = $email === null ? [] : ($byEmail[$email] ?? []);
        if (count($candidates) !== 1 || $candidates[0]->erp_employee_gid !== null) {
            return null;
        }

        return ['id' => (int) $candidates[0]->id, 'name' => (string) $candidates[0]->name];
    }

    /**
     * Najczęstsza wartość (XL zapisuje opiekuna na każdym kliencie osobno — po zmianie nazwiska mogą się różnić).
     *
     * @param  array<string, int>  $counts
     */
    private static function mostCommon(array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
