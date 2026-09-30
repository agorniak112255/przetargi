<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErpCustomer;
use App\Models\User;
use App\Services\Erp\ErpCustomerSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Operatorzy ERP XL do powiązania z użytkownikiem (users.erp_operator_ident): ci, którzy są głównym operatorem choć
 * jednego klienta (erp:customers), z nazwiskiem z XL i liczbą takich klientów, oraz już przypisani użytkownikom
 * (także bez klientów — customers: 0).
 */
class ErpOperatorController extends Controller
{
    public function index(): JsonResponse
    {
        $counts = [];
        $rows = ErpCustomer::query()
            ->whereNull('removed_at')
            ->whereNotNull('main_operator')
            ->groupBy('main_operator')
            ->selectRaw('main_operator, COUNT(*) AS customers')
            ->get();
        foreach ($rows as $row) {
            $ident = $this->ident((string) $row->getAttribute('main_operator'));
            if ($ident !== '') {
                $counts[$ident] = ($counts[$ident] ?? 0) + (int) $row->getAttribute('customers');
            }
        }

        $names = Cache::get(ErpCustomerSync::OPERATORS_CACHE_KEY, []);
        $names = is_array($names) ? $names : [];

        $users = [];
        $assigned = User::query()->whereNotNull('erp_operator_ident')->orderBy('id')->get(['id', 'name', 'erp_operator_ident']);
        foreach ($assigned as $user) {
            $ident = $this->ident((string) $user->getAttribute('erp_operator_ident'));
            if ($ident !== '' && ! isset($users[$ident])) {
                $users[$ident] = ['id' => $user->id, 'name' => $user->name];
            }
            // przypisany operator bez klientów (nowy handlowiec, klienci jeszcze nie zsynchronizowani) też na liście —
            // inaczej panel nie pokazałby bieżącego powiązania
            if ($ident !== '') {
                $counts[$ident] ??= 0;
            }
        }

        $data = [];
        foreach ($counts as $ident => $customers) {
            $ident = (string) $ident;
            $name = $names[$ident] ?? null;
            $data[] = [
                'ident' => $ident,
                'name' => is_string($name) && $name !== '' ? $name : null,
                'customers' => $customers,
                'user' => $users[$ident] ?? null,
            ];
        }
        usort($data, static fn (array $a, array $b): int => [$b['customers'], $a['ident']] <=> [$a['customers'], $b['ident']]);

        return response()->json(['data' => $data]);
    }

    private function ident(string $value): string
    {
        return mb_strtoupper(trim($value));
    }
}
