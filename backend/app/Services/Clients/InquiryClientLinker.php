<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\User;
use App\Services\Erp\ErpCustomerSync;
use App\Support\CompanyName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Powiązanie zapytania klienta z klientem z zakładki Klienci — tylko pewne, bez zgadywania (decyzja 03.10.2026):
 *  - manual: wybrał handlowiec (client_id z formularza albo „Powiąż z klientem”); automat nigdy go nie rusza — także
 *    gdy handlowiec świadomie zostawił zapytanie bez klienta,
 *  - email: adres nadawcy (source_from_email) dokładnie równy adresowi z karty klienta (clients.emails) albo osoby
 *    kontaktowej (clients.contacts[].email) i należący do dokładnie jednego klienta; adresy kont aplikacji (nasi
 *    ludzie przekazujący maila) się nie liczą,
 *  - nip: w treści maila (source_body) dokładnie jeden poprawny NIP z etykietą „NIP”, inny niż nasz (bzp.our_company.nip),
 *    równy NIP-owi dokładnie jednego klienta.
 * E-mail i NIP wskazujące różnych klientów → brak powiązania. Domena adresu nigdy nie wystarcza.
 * Powiązania automatyczne są przeliczane co noc (linkAll, inquiries:order-hints) — brak dopasowania zdejmuje
 * automatyczne powiązanie. Zapytanie z client_id, ale bez źródła (zapis sprzed tej reguły) traktujemy jak ręczne.
 */
final class InquiryClientLinker
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_EMAIL = 'email';

    public const SOURCE_NIP = 'nip';

    /** Reguła słowami — do pokazania przy powiązaniu (karta klienta, zapytanie). */
    public const RULES = [
        self::SOURCE_MANUAL => 'wybrane przez handlowca',
        self::SOURCE_EMAIL => 'ten sam adres e-mail co w ERP XL',
        self::SOURCE_NIP => 'NIP z maila',
    ];

    /** Etykieta „NIP”, do 12 znaków bez cyfr (dwukropek, „PL”, „UE:”), potem 10 cyfr z pojedynczymi separatorami. */
    private const NIP_PATTERN = '/\bNIP\b[^\d\n]{0,12}?(\d(?:[ .\-]?\d){9})(?!\d)/iu';

    /** @var array{emails: array<string, list<int>>, nips: array<string, list<int>>, own_emails: array<string, true>}|null */
    private ?array $index = null;

    /**
     * Pewny klient zapytania według adresu nadawcy i NIP-u z treści (bez względu na zapisane powiązanie).
     *
     * @return array{client_id: int, source: 'email'|'nip'}|null
     */
    public function match(ClientInquiry $inquiry): ?array
    {
        $index = $this->index();

        $email = mb_strtolower(trim((string) $inquiry->source_from_email));
        $emailCandidates = $email !== '' && ! isset($index['own_emails'][$email]) ? ($index['emails'][$email] ?? []) : [];
        $emailClient = count($emailCandidates) === 1 ? $emailCandidates[0] : null;

        $nipClient = null;
        $nips = self::nipsInText($inquiry->source_body);
        if (count($nips) === 1) {
            $owners = $index['nips'][$nips[0]] ?? [];
            $nipClient = count($owners) === 1 ? $owners[0] : null;
        }

        if ($emailClient !== null) {
            if ($nipClient !== null && $nipClient !== $emailClient) {
                return null;
            }

            return ['client_id' => $emailClient, 'source' => self::SOURCE_EMAIL];
        }
        if ($nipClient !== null) {
            // adres nadawcy należy do kilku klientów — NIP rozstrzyga tylko, gdy wskazuje jednego z nich
            if ($emailCandidates !== [] && ! in_array($nipClient, $emailCandidates, true)) {
                return null;
            }

            return ['client_id' => $nipClient, 'source' => self::SOURCE_NIP];
        }

        return null;
    }

    /**
     * Wybór handlowca: klient albo świadomie „bez klienta” (null). Oba zapisują źródło manual, więc nocne
     * przeliczenie tego zapytania już nie zmieni.
     */
    public function link(ClientInquiry $inquiry, ?int $clientId): void
    {
        $inquiry->forceFill([
            'client_id' => $clientId,
            'client_link_source' => self::SOURCE_MANUAL,
            'client_linked_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Przelicza powiązania automatyczne wszystkich zapytań (ręcznych nie rusza). Zapis bez zmiany updated_at
     * zapytania i z warunkiem, że w międzyczasie nikt nie wybrał klienta ręcznie.
     *
     * @return array{checked: int, manual: int, linked_email: int, linked_nip: int, changed: int, removed: int}
     */
    public function linkAll(): array
    {
        $this->index = null;
        $stats = ['checked' => 0, 'manual' => 0, 'linked_email' => 0, 'linked_nip' => 0, 'changed' => 0, 'removed' => 0];
        $now = CarbonImmutable::now();

        $query = ClientInquiry::query()->select(['id', 'client_id', 'client_link_source', 'source_from_email', 'source_body']);
        foreach ($query->lazyById(200) as $inquiry) {
            /** @var ClientInquiry $inquiry */
            $stats['checked']++;
            $source = $inquiry->client_link_source;
            if (self::isManual($source, $inquiry->client_id)) {
                $stats['manual']++;

                continue;
            }

            $match = $this->match($inquiry);
            if ($match !== null) {
                $stats[$match['source'] === self::SOURCE_EMAIL ? 'linked_email' : 'linked_nip']++;
                if ((int) $inquiry->client_id === $match['client_id'] && $source === $match['source']) {
                    continue;
                }
                $stats['changed'] += $this->updateAutomatic((int) $inquiry->id, [
                    'client_id' => $match['client_id'],
                    'client_link_source' => $match['source'],
                    'client_linked_at' => $now,
                ]);
            } elseif ($source !== null) {
                // tu źródło to email albo nip (ręczne pominięte wyżej) — dopasowanie zniknęło, zdejmujemy powiązanie
                $stats['removed'] += $this->updateAutomatic((int) $inquiry->id, [
                    'client_id' => null,
                    'client_link_source' => null,
                    'client_linked_at' => null,
                ]);
            }
        }

        return $stats;
    }

    /**
     * Powiązanie do pokazania: klient i źródło; null, gdy zapytanie nie ma klienta (także po ręcznym „bez klienta”).
     *
     * @return array{client: array{id: int, name: string}, source: 'manual'|'email'|'nip'}|null
     */
    public static function present(ClientInquiry $inquiry): ?array
    {
        if ($inquiry->client_id === null) {
            return null;
        }
        $client = $inquiry->relationLoaded('client') ? $inquiry->client : Client::query()->select(['id', 'name'])->find($inquiry->client_id);
        if (! $client instanceof Client) {
            return null;
        }
        $source = in_array($inquiry->client_link_source, [self::SOURCE_EMAIL, self::SOURCE_NIP], true)
            ? $inquiry->client_link_source
            : self::SOURCE_MANUAL;

        return ['client' => ['id' => (int) $client->id, 'name' => (string) $client->name], 'source' => $source];
    }

    /**
     * Poprawne NIP-y z etykietą „NIP” w tekście, bez naszego — każdy raz.
     *
     * @return list<string>
     */
    public static function nipsInText(?string $text): array
    {
        if ($text === null || $text === '' || preg_match_all(self::NIP_PATTERN, $text, $matches) < 1) {
            return [];
        }
        $ours = CompanyName::nip((string) config('bzp.our_company.nip'));
        $out = [];
        foreach ($matches[1] as $candidate) {
            $digits = (string) preg_replace('/\D+/', '', $candidate);
            if (CompanyName::validNip($digits) && $digits !== $ours && ! in_array($digits, $out, true)) {
                $out[] = $digits;
            }
        }

        return $out;
    }

    /** Ręczne: źródło manual albo client_id bez źródła (zapis sprzed reguły, np. client_id z formularza). */
    private static function isManual(?string $source, mixed $clientId): bool
    {
        return $source === self::SOURCE_MANUAL || ($source === null && $clientId !== null);
    }

    /**
     * Zapis powiązania automatycznego bez dotykania updated_at — tylko gdy wiersz nadal nie jest ręczny.
     *
     * @param  array<string, mixed>  $values
     */
    private function updateAutomatic(int $id, array $values): int
    {
        return ClientInquiry::query()->toBase()
            ->where('id', $id)
            ->where(static function (Builder $q): void {
                $q->whereIn('client_link_source', [self::SOURCE_EMAIL, self::SOURCE_NIP])
                    ->orWhere(static function (Builder $q): void {
                        $q->whereNull('client_link_source')->whereNull('client_id');
                    });
            })
            ->update($values);
    }

    /**
     * Adresy e-mail i NIP-y klientów (każdy adres → klienci, którzy go mają) oraz adresy kont aplikacji.
     *
     * @return array{emails: array<string, list<int>>, nips: array<string, list<int>>, own_emails: array<string, true>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $emails = [];
        $nips = [];
        foreach (Client::query()->select(['id', 'nip', 'emails', 'contacts'])->lazyById(500) as $client) {
            /** @var Client $client */
            $id = (int) $client->id;
            $fields = is_array($client->emails) ? array_values(array_filter($client->emails, 'is_string')) : [];
            foreach (is_array($client->contacts) ? $client->contacts : [] as $contact) {
                if (is_array($contact) && is_string($contact['email'] ?? null)) {
                    $fields[] = $contact['email'];
                }
            }
            foreach (ErpCustomerSync::normalizeEmails($fields) as $email) {
                $emails[$email][] = $id;
            }
            $nip = CompanyName::nip($client->nip);
            if ($nip !== null) {
                $nips[$nip][] = $id;
            }
        }
        $own = [];
        foreach (User::query()->pluck('email') as $email) {
            $own[mb_strtolower(trim((string) $email))] = true;
        }

        return $this->index = [
            'emails' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $emails),
            'nips' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $nips),
            'own_emails' => $own,
        ];
    }
}
