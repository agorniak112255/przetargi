<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\Client;
use App\Support\CompanyName;

/**
 * Zamawiający z ogłoszenia → klient z zakładki Klienci (zakładanie przetargu z ogłoszenia i podpowiedź na liście):
 *  1. NIP (same cyfry, CompanyName::nip — zapis z myślnikami i „PL” też) równy NIP-owi dokładnie jednego klienta,
 *  2. inaczej klucz nazwy (CompanyName::key) równy kluczowi dokładnie jednego klienta — z pominięciem klientów
 *     z innym poprawnym NIP-em niż w ogłoszeniu (ta sama nazwa, inny NIP = inna jednostka, np. ten sam szpital
 *     w innym mieście),
 *  3. inaczej brak dopasowania — wywołujący zakłada nowego klienta z danymi z ogłoszenia.
 * Indeks klientów budowany raz na obiekt (porcjami, same id, nazwa i NIP).
 */
final class NoticeClientMatcher
{
    public const BY_NIP = 'nip';

    public const BY_NAME = 'name';

    /** @var array{nips: array<string, list<int>>, keys: array<string, list<int>>, client_nips: array<int, string>, names: array<int, string>}|null */
    private ?array $index = null;

    /**
     * @return array{id: int, name: string, matched_by: 'nip'|'name'}|null
     */
    public function match(?string $nip, ?string $name): ?array
    {
        $index = $this->index();
        $nip = CompanyName::nip($nip);

        if ($nip !== null && count($index['nips'][$nip] ?? []) === 1) {
            $id = $index['nips'][$nip][0];

            return ['id' => $id, 'name' => $index['names'][$id], 'matched_by' => self::BY_NIP];
        }

        $key = CompanyName::key($name);
        if ($key === '') {
            return null;
        }
        $candidates = array_values(array_filter(
            $index['keys'][$key] ?? [],
            static fn (int $id): bool => $nip === null || ! isset($index['client_nips'][$id]) || $index['client_nips'][$id] === $nip,
        ));
        if (count($candidates) !== 1) {
            return null;
        }

        return ['id' => $candidates[0], 'name' => $index['names'][$candidates[0]], 'matched_by' => self::BY_NAME];
    }

    /**
     * @return array{nips: array<string, list<int>>, keys: array<string, list<int>>, client_nips: array<int, string>, names: array<int, string>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $nips = [];
        $keys = [];
        $clientNips = [];
        $names = [];
        foreach (Client::query()->select(['id', 'name', 'nip'])->lazyById(500) as $client) {
            /** @var Client $client */
            $id = (int) $client->id;
            $names[$id] = (string) $client->name;
            $nip = CompanyName::nip($client->nip);
            if ($nip !== null) {
                $nips[$nip][] = $id;
                $clientNips[$id] = $nip;
            }
            $key = CompanyName::key($client->name);
            if ($key !== '') {
                $keys[$key][] = $id;
            }
        }

        return $this->index = ['nips' => $nips, 'keys' => $keys, 'client_nips' => $clientNips, 'names' => $names];
    }
}
