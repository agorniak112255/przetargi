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
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * „Załóż przetarg” z ogłoszenia o zamówieniu (zakładka Ogłoszenia): szkic jak TenderController::store (numer PRZ/…,
 * opiekun = zakładający), tytuł = przedmiot zamówienia, numer ogłoszenia, termin składania w czasie polskim, wpis
 * w historii. Zamawiający: NoticeClientMatcher (NIP → nazwa), inaczej nowy klient ręczny z nazwą, NIP-em i miastem
 * z ogłoszenia.
 *
 * Jedno postępowanie = jeden przetarg: wersje ogłoszenia tego postępowania są blokowane na czas zapisu, a istniejący
 * przetarg (reguła NoticeListQuery::tenderFor) jest zwracany zamiast nowego. Po zapisie przetarg jest od razu łączony
 * z ogłoszeniem (BzpTenderLinker — ustawia contract_notice_id i zakłada części z ogłoszenia); błąd łączenia nie cofa
 * przetargu — nocne bzp:fetch połączy go ponownie.
 */
final class NoticeTenderCreator
{
    public const CLIENT_NEW = 'new';

    public function __construct(
        private readonly TenderActivityLogger $activities,
        private readonly BzpTenderLinker $linker,
    ) {}

    /**
     * @return array{tender: Tender, created: bool}
     */
    public function create(ProcurementNotice $notice, User $user): array
    {
        /** @var array{tender: Tender, created: bool} $result */
        $result = DB::transaction(function () use ($notice, $user): array {
            ProcurementNotice::query()
                ->where('notice_type', ProcurementNotice::TYPE_CONTRACT)
                ->where('bzp_number', $notice->bzp_number)
                ->lockForUpdate()
                ->pluck('id');
            $existing = NoticeListQuery::tenderFor($notice);
            if ($existing !== null) {
                return ['tender' => $existing, 'created' => false];
            }

            [$client, $matchedBy] = $this->client($notice, $user);
            $deadline = $notice->submitting_offers_at?->copy()->setTimezone(PolishTime::TIMEZONE);
            $noticeNumber = NoticeNumber::parse($notice->notice_number)['normalized'] ?? $notice->notice_number;
            $title = trim((string) $notice->order_object);

            $tender = Tender::query()->create([
                'number' => Tender::nextNumber(),
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
                'notice_id' => (int) $notice->id,
                'notice_number' => $noticeNumber,
                'client' => ['id' => (int) $client->id, 'name' => (string) $client->name, 'matched_by' => $matchedBy],
                'note' => 'Założony z ogłoszenia '.$noticeNumber.' (Biuletyn Zamówień Publicznych). Zamawiający: '
                    .$client->name.' — '.self::clientRule($matchedBy).'.',
            ]);

            return ['tender' => $tender, 'created' => true];
        });

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
     * @return array{0: Client, 1: string} klient i reguła doboru (nip / name / new)
     */
    private function client(ProcurementNotice $notice, User $user): array
    {
        $match = (new NoticeClientMatcher)->match($notice->organization_nip, $notice->organization_name);
        $client = $match !== null ? Client::query()->find($match['id']) : null;
        if ($client !== null) {
            return [$client, $match['matched_by']];
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
            NoticeClientMatcher::BY_NAME => 'istniejący klient (ta sama nazwa)',
            default => 'nowy klient z danymi z ogłoszenia',
        };
    }
}
