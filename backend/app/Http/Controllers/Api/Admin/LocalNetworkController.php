<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalNetwork;
use App\Models\User;
use App\Services\Auth\NetworkAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Adresy sieci lokalnej (Administracja → Użytkownicy). Zapis podmienia całą listę.
 */
class LocalNetworkController extends Controller
{
    public function __construct(
        private readonly NetworkAccessPolicy $policy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->present($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'networks' => ['present', 'array', 'max:100'],
            'networks.*.address' => ['required', 'string', 'max:64'],
            'networks.*.label' => ['nullable', 'string', 'max:120'],
        ], [
            'networks.max' => 'Najwyżej 100 adresów.',
            'networks.*.address.required' => 'Wpisz adres IP albo usuń pusty wiersz.',
            'networks.*.address.max' => 'Adres jest za długi.',
            'networks.*.label.max' => 'Opis może mieć najwyżej 120 znaków.',
        ]);

        $rows = [];
        foreach ($data['networks'] as $i => $row) {
            $address = NetworkAccessPolicy::normalizeAddress((string) $row['address']);
            if ($address === null) {
                throw ValidationException::withMessages(["networks.$i.address" => [
                    '„'.trim((string) $row['address']).'” to nie adres IP ani zakres (np. 91.189.223.20 albo 91.189.223.0/24).',
                ]]);
            }
            if (array_key_exists($address, $rows)) {
                throw ValidationException::withMessages(["networks.$i.address" => ['Adres '.$address.' jest na liście dwa razy.']]);
            }
            $label = trim((string) ($row['label'] ?? ''));
            $rows[$address] = $label === '' ? null : $label;
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->policy->applyGuarded($actor, $request->ip(), 'networks', function () use ($rows): void {
            LocalNetwork::query()->whereNotIn('address', array_keys($rows) ?: [''])->delete();
            foreach ($rows as $address => $label) {
                LocalNetwork::query()->updateOrCreate(['address' => $address], ['label' => $label]);
            }
        });

        return response()->json($this->present($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request): array
    {
        $ip = NetworkAccessPolicy::clientIp($request->ip());

        return [
            'networks' => LocalNetwork::query()->orderBy('id')->get(['id', 'address', 'label'])->all(),
            'your_ip' => $ip,
            'your_ip_is_local' => $this->policy->isLocal($ip),
            'enforced' => $this->policy->enforced(),
        ];
    }
}
