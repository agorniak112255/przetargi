<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use App\Models\Client;
use App\Models\ProcurementNotice;
use App\Models\Tender;
use App\Models\User;
use App\Services\TenderActivityLogger;
use App\Support\CompanyName;
use App\Support\NoticeNumber;
use App\Support\OfferPricing;
use App\Support\PolishTime;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * „Załóż przetarg” z ogłoszenia o zamówieniu (zakładka Ogłoszenia): szkic jak TenderController::store (numer PRZ/…,
 * opiekun = zakładający), tytuł = przedmiot zamówienia, numer ogłoszenia, termin składania w czasie polskim, wpis
 * w historii. Dane bierze z NAJNOWSZEJ wersji ogłoszenia tego postępowania (bzp_number), nawet gdy wywołano starszą.
 * Zamawiający: klient wskazany przez człowieka albo NoticeClientMatcher (NIP → NIP i nazwa → nazwa); przy kilku
 * pasujących klientach bez wskazania — NoticeClientAmbiguousException z kandydatami (nic nie jest zapisywane);
 * bez dopasowania — nowy klient ręczny z nazwą, NIP-em i miastem z ogłoszenia.
 *
 * Zakładanie z ogłoszeń jest szeregowane blokadą (Cache::lock, LOCK_KEY) zakładaną PRZED transakcją, a dobór klienta
 * odbywa się w transakcji po jej uzyskaniu — dwie równoczesne próby dla tego samego zamawiającego nie założą dwóch
 * klientów (druga widzi klienta zapisanego przez pierwszą). Jedno postępowanie = jeden przetarg: wersje ogłoszenia
 * tego postępowania są blokowane na czas zapisu, a istniejący przetarg (reguła NoticeListQuery::tenderFor) jest
 * zwracany zamiast nowego. Po zapisie przetarg jest od razu łączony z ogłoszeniem (BzpTenderLinker — ustawia
 * contract_notice_id i zakłada części z ogłoszenia); błąd łączenia nie cofa przetargu — nocne bzp:fetch połączy go
 * ponownie.
 */
final class NoticeTenderCreator
{
    public const CLIENT_NEW = 'new';

    /** zamawiający wskazany przez człowieka (client_id w żądaniu) */
    public const CLIENT_CHOSEN = 'chosen';

    public const LOCK_KEY = 'notices:create-tender';

    /** blokada wygasa sama, gdyby proces padł w trakcie */
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly TenderActivityLogger $activities,
        private readonly BzpTenderLinker $linker,
        private readonly int $lockWaitSeconds = 10,
    ) {}

    /**
     * @return array{tender: Tender, created: bool}
     *
     * @throws NoticeClientAmbiguousException kilku pasujących klientów, a $chosen nie podano
     * @throws LockTimeoutException ktoś inny zakłada w tej chwili przetarg z ogłoszenia
     */
    public function create(ProcurementNotice $notice, User $user, ?Client $chosen = null): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        $lock->block($this->lockWaitSeconds);
        try {
            /** @var array{tender: Tender, created: bool} $result */
            $result = DB::transaction(function () use ($notice, $user, $chosen): array {
                $versions = ProcurementNotice::query()
                    ->where('notice_type', ProcurementNotice::TYPE_CONTRACT)
                    ->where('bzp_number', $notice->bzp_number)
                    ->lockForUpdate()
                    ->get();
                // najnowsza wersja postępowania (jak lista: największy numer ogłoszenia z wersją)
                $latest = $versions->sortByDesc('notice_number', SORT_STRING)->first() ?? $notice;

                // przetarg powiązany z którąkolwiek wersją albo z numerem postępowania
                foreach ($versions->isEmpty() ? collect([$latest]) : $versions as $version) {
                    $existing = NoticeListQuery::tenderFor($version);
                    if ($existing !== null) {
                        return ['tender' => $existing, 'created' => false];
                    }
                }

                [$client, $matchedBy] = $chosen !== null ? [$chosen, self::CLIENT_CHOSEN] : $this->client($latest, $user);
                $deadline = $latest->submitting_offers_at?->copy()->setTimezone(PolishTime::TIMEZONE);
                $noticeNumber = NoticeNumber::parse($latest->notice_number)['normalized'] ?? $latest->notice_number;
                $title = trim((string) $latest->order_object);

                $tender = Tender::createWithNextNumber([
                    'title' => mb_substr($title !== '' ? $title : 'Ogłoszenie '.$noticeNumber, 0, 255),
                    'client_id' => $client->id,
                    'owner_id' => $user->id,
                    'deadline' => $deadline?->format('Y-m-d'),
                    'deadline_time' => $deadline?->format('H:i'),
                    'notice_number' => $noticeNumber,
                    'status' => 'draft',
                    'ai_percent' => 0,
                    'target_margin_percent' => OfferPricing::markupPercent(),
                    'last_activity_at' => now(),
                ]);

                $this->activities->log($tender, 'created', $user, null, [
                    'title' => $tender->title,
                    'source' => 'notice',
                    'notice_id' => (int) $latest->id,
                    'notice_number' => $noticeNumber,
                    'client' => ['id' => (int) $client->id, 'name' => (string) $client->name, 'matched_by' => $matchedBy],
                    'note' => 'Założony z ogłoszenia '.$noticeNumber.' (Biuletyn Zamówień Publicznych). Zamawiający: '
                        .$client->name.' — '.self::clientRule($matchedBy).'.',
                ]);

                return ['tender' => $tender, 'created' => true];
            });
        } finally {
            $lock->release();
        }

        if ($result['created']) {
            try {
                // contract_notice_id i części z ogłoszenia (pierwsze powiązanie przetargu bez części)
                $this->linker->link($result['tender'], $user);
            } catch (Throwable $e) {
                report($e);
            }
            $result['tender']->refresh();
        }

        return $result;
    }

    /**
     * @return array{0: Client, 1: string} klient i reguła doboru (nip / nip_name / name / new)
     *
     * @throws NoticeClientAmbiguousException
     */
    private function client(ProcurementNotice $notice, User $user): array
    {
        $resolved = (new NoticeClientMatcher)->resolve($notice->organization_nip, $notice->organization_name);
        if ($resolved['candidates'] !== []) {
            throw new NoticeClientAmbiguousException($resolved['candidates']);
        }
        $client = $resolved['match'] !== null ? Client::query()->find($resolved['match']['id']) : null;
        if ($client !== null) {
            return [$client, $resolved['match']['matched_by']];
        }

        $name = trim((string) $notice->organization_name);
        $city = trim((string) $notice->organization_city);
        $client = Client::query()->create([
            'name' => mb_substr($name !== '' ? $name : 'Zamawiający z ogłoszenia '.$notice->notice_number, 0, 255),
            'nip' => CompanyName::nip($notice->organization_nip),
            'city' => $city !== '' ? mb_substr($city, 0, 100) : null,
            'owner_id' => $user->id,
            'source' => Client::SOURCE_MANUAL,
        ]);

        return [$client, self::CLIENT_NEW];
    }

    private static function clientRule(string $matchedBy): string
    {
        return match ($matchedBy) {
            NoticeClientMatcher::BY_NIP => 'istniejący klient (ten sam NIP)',
            NoticeClientMatcher::BY_NIP_AND_NAME => 'istniejący klient (ten sam NIP i ta sama nazwa)',
            NoticeClientMatcher::BY_NAME => 'istniejący klient (ta sama nazwa)',
            self::CLIENT_CHOSEN => 'klient wybrany przy zakładaniu przetargu',
            default => 'nowy klient z danymi z ogłoszenia',
        };
    }
}
