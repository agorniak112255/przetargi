<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Co minutę: kolejna partia maili kampanii w limicie godzinowym skrzynki każdego nadawcy. Odbiorca jest rezerwowany
 * (pending → sending jednym UPDATE), więc dwa równoległe przebiegi nie wyślą tego samego adresu.
 */
class CampaignsDispatchCommand extends Command
{
    /** Rezerwacja starsza niż tyle minut = przebieg przerwany; nie wiadomo, czy mail wyszedł — nie ponawiamy. */
    public const STALE_MINUTES = 15;

    /** Tyle odrzuconych adresów pod rząd u jednego nadawcy w przebiegu → przerwa skrzynki. */
    public const MAX_REJECTED_IN_ROW = 3;

    protected $signature = 'campaigns:dispatch';

    protected $description = 'Wysyła kolejną partię maili kampanii w limicie godzinowym skrzynki każdego nadawcy';

    public function handle(CampaignSender $sender): int
    {
        $staleQuery = CampaignRecipient::query()
            ->where('status', CampaignRecipient::STATUS_SENDING)
            ->where('updated_at', '<', Carbon::now()->subMinutes(self::STALE_MINUTES));
        $staleCampaigns = (clone $staleQuery)->distinct()->pluck('campaign_id')->all();
        $stale = $staleQuery->update([
            'status' => CampaignRecipient::STATUS_FAILED,
            'error' => 'przerwane — nie wiadomo, czy wysłano',
            'updated_at' => Carbon::now(),
        ]);
        // kampanie w wysyłce przeliczy finishIfDone; anulowanej liczniki poprawiamy tutaj
        foreach (Campaign::query()->whereIn('id', $staleCampaigns)->where('status', Campaign::STATUS_CANCELLED)->get() as $cancelled) {
            $cancelled->forceFill(['totals' => CampaignSender::totals($cancelled)])->save();
        }

        $sent = 0;
        $campaigns = Campaign::query()->where('status', Campaign::STATUS_SENDING)
            ->orderBy('sending_started_at')->orderBy('id')->get();
        foreach ($campaigns->groupBy('user_id') as $userId => $userCampaigns) {
            $sent += $this->dispatchSender((int) $userId, $userCampaigns->all(), $sender);
        }

        $finished = 0;
        foreach ($campaigns as $campaign) {
            $finished += $this->finishIfDone($campaign) ? 1 : 0;
        }

        if ($sent > 0 || $finished > 0 || $stale > 0) {
            $this->info(sprintf('Wysłano: %d, zakończone kampanie: %d, przerwane rezerwacje: %d.', $sent, $finished, $stale));
        }

        return self::SUCCESS;
    }

    /**
     * Partia jednego nadawcy: budżet = limit/godz. − (wysłane + w trakcie z 60 min), najwyżej ceil(limit/60) + 1.
     *
     * @param  list<Campaign>  $campaigns
     */
    private function dispatchSender(int $userId, array $campaigns, CampaignSender $sender): int
    {
        if (CampaignSender::isPaused($userId)) {
            return 0;
        }
        $account = UserMailAccount::query()->where('user_id', $userId)->first();
        if ($account === null) {
            return 0;
        }
        $rate = max(1, (int) $account->rate_per_hour);
        $hourAgo = Carbon::now()->subHour();
        $used = CampaignRecipient::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->where('campaigns.user_id', $userId)
            ->where(static fn ($q) => $q
                ->where(static fn ($s) => $s->where('campaign_recipients.status', CampaignRecipient::STATUS_SENT)->where('campaign_recipients.sent_at', '>=', $hourAgo))
                ->orWhere(static fn ($s) => $s->where('campaign_recipients.status', CampaignRecipient::STATUS_SENDING)->where('campaign_recipients.updated_at', '>=', $hourAgo)))
            ->count();
        $budget = min($rate - $used, (int) ceil($rate / 60) + 1);

        $sent = 0;
        $rejected = 0;
        $tried = [];
        foreach ($campaigns as $campaign) {
            while ($budget > 0) {
                // przed każdym odbiorcą: anulowana kampania przerywa partię
                if (Campaign::query()->whereKey($campaign->id)->value('status') !== Campaign::STATUS_SENDING) {
                    break;
                }
                $next = CampaignRecipient::query()
                    ->where('campaign_id', $campaign->id)
                    ->where('status', CampaignRecipient::STATUS_PENDING)
                    ->when($tried !== [], static fn ($q) => $q->whereNotIn('id', $tried))
                    ->orderBy('attempts')->orderBy('id')
                    ->first();
                if ($next === null) {
                    break;
                }
                $tried[] = (int) $next->id;
                $reserved = DB::table('campaign_recipients')
                    ->where('id', $next->id)
                    ->where('status', CampaignRecipient::STATUS_PENDING)
                    ->update(['status' => CampaignRecipient::STATUS_SENDING, 'updated_at' => Carbon::now()]);
                if ($reserved !== 1) {
                    // inny przebieg był szybszy
                    continue;
                }

                try {
                    $sender->sendOne($next);
                } catch (Throwable $e) {
                    // nieoczekiwany błąd (np. baza) — ten nadawca czeka do następnej minuty; rezerwacja po 15 min → failed
                    report($e);
                    $this->error('Kampania '.$campaign->code.': '.$e->getMessage());

                    return $sent;
                }
                $status = CampaignRecipient::query()->whereKey($next->id)->value('status');
                if ($status !== CampaignRecipient::STATUS_SKIPPED) {
                    $budget--;
                }
                if ($status === CampaignRecipient::STATUS_SENT) {
                    $sent++;
                }
                if (CampaignSender::isPaused($userId)) {
                    return $sent;
                }
                // odrzucenie adresu (próba policzona: pending albo failed) — kilka pod rząd to raczej blokada skrzynki
                // (spam, limit) niż złe adresy; dalsze próby zużywałyby próby dobrych adresów
                if ($status === CampaignRecipient::STATUS_SENT) {
                    $rejected = 0;
                } elseif (in_array($status, [CampaignRecipient::STATUS_PENDING, CampaignRecipient::STATUS_FAILED], true)
                    && ++$rejected >= self::MAX_REJECTED_IN_ROW) {
                    $sender->pause($userId, $account, 'Serwer odrzuca kolejne adresy — sprawdź skrzynkę');

                    return $sent;
                }
            }
        }

        return $sent;
    }

    /** Brak odbiorców pending i sending → kampania wysłana (sent_at, totals). */
    private function finishIfDone(Campaign $campaign): bool
    {
        return DB::transaction(function () use ($campaign): bool {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== Campaign::STATUS_SENDING) {
                return false;
            }
            $open = CampaignRecipient::query()->where('campaign_id', $locked->id)
                ->whereIn('status', [CampaignRecipient::STATUS_PENDING, CampaignRecipient::STATUS_SENDING])
                ->exists();
            if ($open) {
                $locked->forceFill(['totals' => CampaignSender::totals($locked)])->save();

                return false;
            }
            $locked->forceFill([
                'status' => Campaign::STATUS_SENT,
                'sent_at' => Carbon::now(),
                'totals' => CampaignSender::totals($locked),
            ])->save();

            return true;
        });
    }
}
