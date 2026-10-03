<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\Client;
use App\Support\CompanyName;

/**
 * Zamawiający z ogłoszenia → klient z zakładki Klienci (zakładanie przetargu z ogłoszenia i podpowiedź na liście):
 *  1. NIP (same cyfry, CompanyName::nip — zapis z myślnikami i „PL” też) równy NIP-owi dokładnie jednego klienta;
 *     kilku klientów z tym NIP-em (np. oddziały jednej gminy) — zawężenie po kluczu nazwy (CompanyName::key) wśród
 *     nich; dalej kilku albo żaden z tą nazwą → niejednoznaczne (wybiera człowiek spośród klientów z tym NIP-em),
 *  2. NIP-u nie ma u żadnego klienta albo ogłoszenie go nie podaje — klucz nazwy równy kluczowi dokładnie jednego
 *     klienta, z pominięciem klientów z innym poprawnym NIP-em niż w ogłoszeniu (ta sama nazwa, inny NIP = inna
 *     jednostka, np. ten sam szpital w innym mieście); kilku takich klientów → niejednoznaczne,
 *  3. inaczej brak dopasowania — wywołujący zakłada nowego klienta z danymi z ogłoszenia.
 * Przy niejednoznaczności nikt nie zgaduje: wynik ma `match` = null i listę kandydatów (najwyżej CANDIDATE_LIMIT).
 * Indeks klientów budowany raz na obiekt (porcjami, same id, nazwa, NIP i miasto).
 */
final class NoticeClientMatcher
{
    public const BY_NIP = 'nip';

    /** kilku klientów z tym NIP-em, z tą samą nazwą dokładnie jeden */
    public const BY_NIP_AND_NAME = 'nip_name';

    public const BY_NAME = 'name';

    public const CANDIDATE_LIMIT = 10;

    /** @var array{nips: array<string, list<int>>, keys: array<string, list<int>>, client_nips: array<int, string>, client_keys: array<int, string>, names: array<int, string>, cities: array<int, ?string>}|null */
    private ?array $index = null;

    /**
     * @return array{
     *     match: array{id: int, name: string, matched_by: 'nip'|'nip_name'|'name'}|null,
     *     candidates: list<array{id: int, name: string, nip: ?string, city: ?string}>
     * } candidates niepuste tylko przy niejednoznaczności (wtedy match = null)
     */
    public function resolve(?string $nip, ?string $name): array
    {
        $index = $this->index();
        $nip = CompanyName::nip($nip);
        $key = CompanyName::key($name);

        $byNip = $nip !== null ? ($index['nips'][$nip] ?? []) : [];
        if (count($byNip) === 1) {
            return $this->matched($byNip[0], self::BY_NIP);
        }
        if (count($byNip) > 1) {
            $sameName = $key !== ''
                ? array_values(array_filter($byNip, static fn (int $id): bool => ($index['client_keys'][$id] ?? '') === $key))
                : [];
            if (count($sameName) === 1) {
                return $this->matched($sameName[0], self::BY_NIP_AND_NAME);
            }

            return $this->ambiguous(count($sameName) > 1 ? $sameName : $byNip);
        }

        if ($key === '') {
            return ['match' => null, 'candidates' => []];
        }
        $byName = array_values(array_filter(
            $index['keys'][$key] ?? [],
            static fn (int $id): bool => $nip === null || ! isset($index['client_nips'][$id]) || $index['client_nips'][$id] === $nip,
        ));
        if (count($byName) === 1) {
            return $this->matched($byName[0], self::BY_NAME);
        }
        if (count($byName) > 1) {
            return $this->ambiguous($byName);
        }

        return ['match' => null, 'candidates' => []];
    }

    /**
     * @return array{match: array{id: int, name: string, matched_by: 'nip'|'nip_name'|'name'}, candidates: list<array{id: int, name: string, nip: ?string, city: ?string}>}
     */
    private function matched(int $id, string $by): array
    {
        /** @var 'nip'|'nip_name'|'name' $by */
        return ['match' => ['id' => $id, 'name' => $this->index()['names'][$id], 'matched_by' => $by], 'candidates' => []];
    }

    /**
     * @param  list<int>  $ids
     * @return array{match: null, candidates: list<array{id: int, name: string, nip: ?string, city: ?string}>}
     */
    private function ambiguous(array $ids): array
    {
        $index = $this->index();
        $candidates = [];
        foreach (array_slice($ids, 0, self::CANDIDATE_LIMIT) as $id) {
            $candidates[] = [
                'id' => $id,
                'name' => $index['names'][$id],
                'nip' => $index['client_nips'][$id] ?? null,
                'city' => $index['cities'][$id] ?? null,
            ];
        }

        return ['match' => null, 'candidates' => $candidates];
    }

    /**
     * @return array{nips: array<string, list<int>>, keys: array<string, list<int>>, client_nips: array<int, string>, client_keys: array<int, string>, names: array<int, string>, cities: array<int, ?string>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $nips = [];
        $keys = [];
        $clientNips = [];
        $clientKeys = [];
        $names = [];
        $cities = [];
        foreach (Client::query()->select(['id', 'name', 'nip', 'city'])->lazyById(500) as $client) {
            /** @var Client $client */
            $id = (int) $client->id;
            $names[$id] = (string) $client->name;
            $city = trim((string) $client->city);
            $cities[$id] = $city !== '' ? $city : null;
            $nip = CompanyName::nip($client->nip);
            if ($nip !== null) {
                $nips[$nip][] = $id;
                $clientNips[$id] = $nip;
            }
            $key = CompanyName::key($client->name);
            if ($key !== '') {
                $keys[$key][] = $id;
                $clientKeys[$id] = $key;
            }
        }

        return $this->index = [
            'nips' => $nips,
            'keys' => $keys,
            'client_nips' => $clientNips,
            'client_keys' => $clientKeys,
            'names' => $names,
            'cities' => $cities,
        ];
    }
}
