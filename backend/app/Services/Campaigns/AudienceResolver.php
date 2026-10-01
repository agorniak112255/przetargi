<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\ErpCustomer;
use App\Models\ErpItem;
use App\Models\MailingList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Odbiorcy kampanii: grupy (tylko własne i wspólne grupy autora) ∪ klienci z ERP XL wg audience.xl, po normalizacji,
 * duplikatach, adresach ogólnych (faktury@), wypisanych i limicie częstotliwości. Nie final — testy podmieniają zależności.
 */
class AudienceResolver
{
    /** Grupy asortymentu XL po pierwszej literze kodu towaru (jak Zapasy). */
    private const GROUPS = ['A', 'B', 'S', 'T', 'H'];

    private const CHUNK = 500;

    /**
     * Kształt „Audience GET” z kontraktu API (+ warnings: dlaczego część odbiorców odpadła).
     *
     * @return array<string, mixed>
     */
    public function preview(Campaign $campaign): array
    {
        $r = $this->resolve($campaign);

        return [
            'lists' => ['contacts' => $r['list_rows']],
            'xl' => ['customers' => $r['xl_customers'], 'emails' => $r['xl_emails']],
            'total_raw' => $r['list_rows'] + $r['xl_emails'],
            'duplicates' => $r['duplicates'],
            'invalid' => $r['invalid'],
            'excluded_generic' => $r['excluded_generic'],
            'suppressed' => $r['suppressed'],
            'capped' => $r['capped'],
            // adresy, które już są odbiorcami tej kampanii (dopisywanie do wysłanej) — nie dostaną maila drugi raz
            'already' => $r['already'],
            'final' => count($r['final']),
            'without_mailbox' => $campaign->user?->mailAccount === null,
            'sample' => array_map(static fn (array $f): array => [
                'email' => $f['email'],
                'name' => $f['name'],
                'source' => $f['source'],
            ], array_slice($r['final'], 0, 20)),
            'warnings' => $r['warnings'],
        ];
    }

    /**
     * Pełna lista odbiorców (final) i pominiętych z powodem (bez duplikatów) — okno potwierdzenia przed wysyłką.
     *
     * @return array{list_rows: int, xl_customers: int, xl_emails: int, duplicates: int, invalid: int,
     *     excluded_generic: int, suppressed: int, capped: int, already: int, warnings: list<string>,
     *     final: list<array{email: string, name: string|null, source: string, origin: string, contact_id: int|null, erp_customer_id: int|null}>,
     *     waiting: list<array{email: string, name: string|null, source: string, origin: string, contact_id: int|null, erp_customer_id: int|null}>,
     *     skipped: list<array{email: string, name: string|null, source: string, origin: string, reason: string}>}
     */
    public function recipientList(Campaign $campaign): array
    {
        return $this->resolve($campaign);
    }

    /**
     * Suma kontrolna listy adresów (kolejność bez znaczenia) — ta sama w oknie potwierdzenia i przy starcie.
     *
     * @param  list<string>  $emails
     */
    public static function checksum(array $emails): string
    {
        sort($emails, SORT_STRING);

        return sha1(implode("\n", $emails));
    }

    /**
     * Tworzy campaign_recipients (pending) przez insertOrIgnore; zwraca liczbę odbiorców kampanii. Z $expectedChecksum
     * (lista z okna potwierdzenia) — gdy odbiorcy są teraz inni, wyjątek przed zapisem (start w transakcji się wycofa).
     * Odbiorcy warunkowi (waiting — adres czeka w innej wysyłanej kampanii) też są zapisywani; suma kontrolna ich nie
     * obejmuje, bo w oknie potwierdzenia są wśród pominiętych.
     */
    public function materialize(Campaign $campaign, ?string $expectedChecksum = null): int
    {
        $r = $this->resolve($campaign);
        $final = $r['final'];
        if ($expectedChecksum !== null && ! hash_equals(self::checksum(array_column($final, 'email')), strtolower($expectedChecksum))) {
            throw ValidationException::withMessages([
                'recipients_checksum' => ['Lista odbiorców zmieniła się od podglądu — sprawdź ją jeszcze raz.'],
            ]);
        }
        $now = Carbon::now();
        foreach (array_chunk([...$final, ...$r['waiting']], self::CHUNK) as $chunk) {
            DB::table('campaign_recipients')->insertOrIgnore(array_map(static fn (array $f): array => [
                'campaign_id' => $campaign->id,
                'contact_id' => $f['contact_id'],
                'erp_customer_id' => $f['erp_customer_id'],
                'email' => $f['email'],
                'name' => $f['name'],
                'source' => $f['source'],
                'token' => Str::random(40),
                'status' => CampaignRecipient::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return CampaignRecipient::query()->where('campaign_id', $campaign->id)->count();
    }

    /**
     * @return array{list_rows: int, xl_customers: int, xl_emails: int, duplicates: int, invalid: int,
     *     excluded_generic: int, suppressed: int, capped: int, already: int, warnings: list<string>,
     *     final: list<array{email: string, name: string|null, source: string, origin: string, contact_id: int|null, erp_customer_id: int|null}>,
     *     waiting: list<array{email: string, name: string|null, source: string, origin: string, contact_id: int|null, erp_customer_id: int|null}>,
     *     skipped: list<array{email: string, name: string|null, source: string, origin: string, reason: string}>}
     */
    private function resolve(Campaign $campaign): array
    {
        $settings = $campaign->audienceSettings();
        $author = $campaign->user;
        $warnings = [];

        // kolejność = pierwszeństwo przy tym samym adresie: grupy przed XL, w XL klient z większą liczbą dokumentów
        $candidates = [];
        $listRows = 0;
        foreach ($this->listContacts($settings['list_ids'], $settings['list_exclusions'], $author) as $row) {
            $listRows++;
            $candidates[] = [
                'email' => (string) $row->email,
                'name' => $this->name($row->name ?? null, $row->company ?? null),
                'source' => CampaignRecipient::SOURCE_LIST,
                'origin' => (string) $row->list_name,
                'contact_id' => (int) $row->id,
                'erp_customer_id' => null,
            ];
        }

        $xlCustomers = 0;
        $xlEmails = 0;
        $query = $this->xlQuery($campaign, $settings['xl'], $author, $warnings);
        if ($query !== null && $settings['xl']['customer_ids'] !== null) {
            // wybór z okna „Pokaż / wybierz” — tylko zaznaczeni, i tylko ci, którzy nadal są w kategorii
            $query->whereIntegerInRaw('id', $settings['xl']['customer_ids']);
        }
        if ($query !== null) {
            foreach ($query->orderByDesc('sale_documents_24m')->orderBy('id')->get(['id', 'acronym', 'name', 'emails']) as $customer) {
                $emails = is_array($customer->emails) ? $customer->emails : [];
                if ($emails === []) {
                    continue;
                }
                $xlCustomers++;
                foreach ($emails as $email) {
                    $xlEmails++;
                    $candidates[] = [
                        'email' => (string) $email,
                        'name' => $this->name($customer->name, $customer->acronym),
                        'source' => CampaignRecipient::SOURCE_XL,
                        'origin' => (string) $customer->acronym,
                        'contact_id' => null,
                        'erp_customer_id' => (int) $customer->id,
                    ];
                }
            }
        }

        // normalizacja → duplikaty → niepoprawne → adresy ogólne (tylko XL)
        $seen = [];
        $valid = [];
        $skipped = [];
        $duplicates = $invalid = $generic = 0;
        $prefixes = array_map(static fn ($p): string => mb_strtolower((string) $p), (array) config('campaigns.excluded_local_prefixes', []));
        foreach ($candidates as $c) {
            $email = mb_strtolower(trim($c['email']));
            if (isset($seen[$email])) {
                $duplicates++;

                continue;
            }
            $seen[$email] = true;
            if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid++;
                $skipped[] = $this->skippedRow($c, $email, 'invalid');

                continue;
            }
            if ($c['source'] === CampaignRecipient::SOURCE_XL && $this->isGeneric($email, $prefixes)) {
                $generic++;
                $skipped[] = $this->skippedRow($c, $email, 'generic');

                continue;
            }
            $valid[$email] = [...$c, 'email' => $email];
        }

        // już odbiorcy tej kampanii (dopisywanie po starcie wysyłki) — wypadają bez wpisu w pominiętych
        $alreadySet = $campaign->exists ? $this->existing(array_keys($valid), static fn (array $chunk) => CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)->whereIn('email', $chunk)->pluck('email')) : [];
        $already = 0;
        foreach (array_keys($alreadySet) as $email) {
            if (isset($valid[$email])) {
                unset($valid[$email]);
                $already++;
            }
        }

        // wypisani (wszystkie kampanie wszystkich nadawców) → limit częstotliwości
        $suppressedSet = $this->existing(array_keys($valid), static fn (array $chunk) => EmailSuppression::query()
            ->whereIn('email', $chunk)->pluck('email'));
        $capDays = (int) config('campaigns.frequency_cap_days', 14);
        $since = Carbon::now()->subDays($capDays);
        $cappedSet = $this->existing(array_keys($valid), static fn (array $chunk) => CampaignRecipient::query()
            ->whereIn('email', $chunk)
            ->where('campaign_id', '!=', $campaign->id)
            ->where('status', CampaignRecipient::STATUS_SENT)->where('sent_at', '>=', $since)
            ->distinct()
            ->pluck('email'));
        // jeszcze nie wysłany, ale czeka w innej kampanii w trakcie wysyłki (np. drugi handlowiec tego samego dnia)
        $waitingSet = $capDays > 0 ? $this->existing(array_keys($valid), static fn (array $chunk) => CampaignRecipient::query()
            ->whereIn('email', $chunk)
            ->where('campaign_id', '!=', $campaign->id)
            ->whereIn('status', [CampaignRecipient::STATUS_PENDING, CampaignRecipient::STATUS_SENDING])
            ->whereIn('campaign_id', Campaign::query()->select('id')->where('status', Campaign::STATUS_SENDING))
            ->distinct()
            ->pluck('email')) : [];

        $final = [];
        $waiting = [];
        $suppressed = $capped = 0;
        foreach ($valid as $email => $c) {
            if (isset($suppressedSet[$email])) {
                $suppressed++;
                $skipped[] = $this->skippedRow($c, (string) $email, 'suppressed');
            } elseif (isset($cappedSet[$email])) {
                $capped++;
                $skipped[] = $this->skippedRow($c, (string) $email, 'capped');
            } elseif (isset($waitingSet[$email])) {
                // w podglądzie pominięty (zwykle dostanie tamtą kampanię), ale zapisany jako odbiorca warunkowy:
                // gdy tamta go nie dostarczy (błąd, anulowanie), wyśle mu ta — CampaignSender::waitsForOtherCampaign
                $capped++;
                $skipped[] = $this->skippedRow($c, (string) $email, 'capped');
                $waiting[] = $c;
            } else {
                $final[] = $c;
            }
        }

        return [
            'list_rows' => $listRows,
            'xl_customers' => $xlCustomers,
            'xl_emails' => $xlEmails,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'excluded_generic' => $generic,
            'suppressed' => $suppressed,
            'capped' => $capped,
            'already' => $already,
            'warnings' => $warnings,
            'final' => $final,
            'waiting' => $waiting,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array{name: string|null, source: string, origin: string}  $c
     * @return array{email: string, name: string|null, source: string, origin: string, reason: string}
     */
    private function skippedRow(array $c, string $email, string $reason): array
    {
        return ['email' => $email, 'name' => $c['name'], 'source' => $c['source'], 'origin' => $c['origin'], 'reason' => $reason];
    }

    /**
     * Kontakty z grup autora i grup wspólnych — cudze prywatne grupy pomijane, nawet gdy ich id jest w audience.
     * Odznaczeni w grupie (list_exclusions) odpadają tylko z tej grupy — z innej wybranej grupy kontakt i tak wchodzi.
     *
     * @param  list<int>  $listIds
     * @param  list<array{list_id: int, contact_ids: list<int>}>  $exclusions
     * @return iterable<object{id: int, email: string, name: ?string, company: ?string, mailing_list_id: int, list_name: string}>
     */
    private function listContacts(array $listIds, array $exclusions, ?User $author): iterable
    {
        if ($listIds === [] || $author === null) {
            return [];
        }
        $allowed = MailingList::query()
            ->whereIn('id', $listIds)
            ->where(static fn (Builder $q) => $q->where('user_id', $author->id)->orWhere('is_shared', true))
            ->pluck('id')
            ->all();
        if ($allowed === []) {
            return [];
        }

        $excluded = [];
        foreach ($exclusions as $entry) {
            $excluded[$entry['list_id']] = $entry['contact_ids'];
        }
        $allowed = array_map('intval', $allowed);

        return DB::table('mailing_list_contact as mlc')
            ->join('contacts as c', 'c.id', '=', 'mlc.contact_id')
            ->join('mailing_lists as ml', 'ml.id', '=', 'mlc.mailing_list_id')
            ->where(static function (QueryBuilder $q) use ($allowed, $excluded): void {
                $whole = array_values(array_filter($allowed, static fn (int $id): bool => ($excluded[$id] ?? []) === []));
                if ($whole !== []) {
                    $q->orWhereIn('mlc.mailing_list_id', $whole);
                }
                foreach ($allowed as $listId) {
                    if (($excluded[$listId] ?? []) !== []) {
                        $q->orWhere(static fn (QueryBuilder $w) => $w->where('mlc.mailing_list_id', $listId)
                            ->whereIntegerNotInRaw('mlc.contact_id', $excluded[$listId]));
                    }
                }
            })
            ->orderBy('mlc.mailing_list_id')
            ->orderBy('mlc.id')
            ->get(['c.id', 'c.email', 'c.name', 'c.company', 'mlc.mailing_list_id', 'ml.name as list_name']);
    }

    /**
     * Cała kategoria klientów XL (bez zawężenia do zaznaczonych) — lista w oknie „Pokaż / wybierz”.
     *
     * @param  array{mode: string|null, months: int, only_mine: bool}  $xl
     * @param  list<string>  $warnings
     * @return Builder<ErpCustomer>|null
     */
    public function xlCategoryQuery(Campaign $campaign, array $xl, array &$warnings): ?Builder
    {
        return $this->xlQuery($campaign, $xl, $campaign->user, $warnings);
    }

    /**
     * Klienci XL wg audience.xl; null = XL nie wybrany (albo nie da się ustalić „moich” klientów).
     *
     * @param  array{mode: string|null, months: int, only_mine: bool}  $xl
     * @param  list<string>  $warnings
     * @return Builder<ErpCustomer>|null
     */
    private function xlQuery(Campaign $campaign, array $xl, ?User $author, array &$warnings): ?Builder
    {
        $mode = $xl['mode'];
        if ($mode === null) {
            return null;
        }
        $ident = $author !== null ? strtoupper(trim((string) $author->getAttribute('erp_operator_ident'))) : '';
        if (($mode === 'mine' || $xl['only_mine']) && $ident === '') {
            $warnings[] = 'Autor nie ma przypisanego operatora ERP XL — „moi klienci” są niedostępni (ustawia administrator).';

            return null;
        }
        $cutoff = CarbonImmutable::today()->subMonthsNoOverflow($xl['months'])->toDateString();
        $query = ErpCustomer::query()->whereNull('removed_at')->where('archived', false)->whereNotNull('emails');

        if ($mode === 'mine') {
            return $query->where('main_operator', $ident)->where('last_sale_at', '>=', $cutoff);
        }

        $itemIds = $campaign->items()->whereNotNull('erp_item_id')->pluck('erp_item_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($itemIds === []) {
            $warnings[] = 'Kampania nie ma towarów z ERP XL — nie da się wybrać klientów, którzy je kupowali.';

            return null;
        }
        if ($mode === 'items') {
            $query->whereHas('items', static fn (Builder $q) => $q->whereIn('erp_item_id', $itemIds)->where('last_sale_at', '>=', $cutoff));
        } else {
            $letters = ErpItem::query()->whereIn('id', $itemIds)->pluck('code')
                ->map(static fn ($code): string => strtoupper(substr(trim((string) $code), 0, 1)))
                ->filter(static fn (string $l): bool => in_array($l, self::GROUPS, true))
                ->unique()->values()->all();
            if ($letters === []) {
                $warnings[] = 'Towary kampanii nie należą do grup asortymentu XL (A, B, S, T, H).';

                return null;
            }
            $query->whereHas('items', static fn (Builder $q) => $q->where('last_sale_at', '>=', $cutoff)
                ->whereHas('item', static function (Builder $i) use ($letters): void {
                    $i->where(static function (Builder $w) use ($letters): void {
                        foreach ($letters as $letter) {
                            $w->orWhere('code', 'like', $letter.'%');
                        }
                    });
                }));
        }
        if ($xl['only_mine']) {
            $query->where('main_operator', $ident);
        }

        return $query;
    }

    /** @param  list<string>  $prefixes */
    private function isGeneric(string $email, array $prefixes): bool
    {
        $local = (string) strstr($email, '@', true);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($local, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zbiór adresów z listy, które zwraca zapytanie (paczkami — whereIn ma limit parametrów).
     *
     * @param  list<string>  $emails
     * @param  callable(list<string>): iterable<string>  $query
     * @return array<string, true>
     */
    private function existing(array $emails, callable $query): array
    {
        $out = [];
        foreach (array_chunk($emails, self::CHUNK) as $chunk) {
            foreach ($query($chunk) as $email) {
                $out[mb_strtolower((string) $email)] = true;
            }
        }

        return $out;
    }

    private function name(?string $primary, ?string $fallback): ?string
    {
        $name = trim((string) ($primary ?? ''));
        if ($name === '') {
            $name = trim((string) ($fallback ?? ''));
        }

        return $name === '' ? null : mb_substr($name, 0, 200);
    }
}
