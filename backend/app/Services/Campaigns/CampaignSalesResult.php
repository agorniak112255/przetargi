<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignRecipient;
use App\Models\ErpCustomer;
use App\Models\ErpSaleLine;
use App\Services\Erp\ErpCampaignSalesSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * „Kupili odbiorcy kampanii”: faktury i paragony z XL z towarami kampanii od dnia wysyłki przez
 * ErpCampaignSalesSync::WINDOW_DAYS dni — osobno odbiorcy (klient XL, któremu mail wyszedł; adres z grupy dopasowany
 * do kontrahenta po e-mailu) i pozostali klienci dla porównania. Zakup po mailu nie dowodzi, że kupił dzięki kampanii.
 * Ilości tylko per towar (różne jednostki się nie sumują); wartość netto PLN można sumować.
 */
final class CampaignSalesResult
{
    /** Najwięcej wierszy „kto kupił” w odpowiedzi. */
    private const MAX_BUYERS = 200;

    /** @return array<string, mixed>|null null = kampania jeszcze nie wysyłana */
    public function forCampaign(Campaign $campaign): ?array
    {
        [$from, $to] = $this->window($campaign) ?? [null, null];
        if ($from === null) {
            return null;
        }
        $emailCustomers = $this->emailToCustomers();
        $recipients = $this->recipientCustomers([(int) $campaign->id], $emailCustomers)[(int) $campaign->id] ?? ['customers' => [], 'since' => [], 'emails' => [], 'sent' => 0];
        $items = CampaignItem::query()->where('campaign_id', $campaign->id)->whereNotNull('erp_item_id')
            ->with('erpItem:id,code,name,unit')->orderBy('position')->get();
        $itemIds = $items->pluck('erp_item_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        $lines = $itemIds === [] ? collect() : ErpSaleLine::query()
            ->whereIn('erp_item_id', $itemIds)
            ->whereBetween('sold_at', [$from->toDateString(), $to->toDateString()])
            ->with('customer:id,acronym,name')
            ->orderBy('sold_at')->orderBy('id')
            ->get();

        $perItem = [];
        foreach ($items as $item) {
            $perItem[(int) $item->erp_item_id] ??= [
                'erp_item_id' => (int) $item->erp_item_id,
                'code' => (string) ($item->erpItem->code ?? $item->snap_code ?? ''),
                'name' => (string) ($item->snap_name ?? $item->erpItem->name ?? ''),
                'unit' => $item->erpItem->unit ?? $item->snap_unit,
                'quantity_recipients' => 0.0,
                'quantity_others' => 0.0,
                'value_recipients' => 0.0,
                'value_others' => 0.0,
            ];
        }
        $buyers = [];
        $recipientBuyers = $otherBuyers = [];
        $valueRecipients = $valueOthers = 0.0;
        foreach ($lines as $line) {
            $customerId = $line->erp_customer_id !== null ? (int) $line->erp_customer_id : null;
            // odbiorca dopisany później: jego zakupy sprzed maila liczą się jak pozostałych klientów
            $isRecipient = $customerId !== null && isset($recipients['customers'][$customerId])
                && $line->sold_at !== null && $line->sold_at->toDateString() >= $recipients['since'][$customerId];
            $key = $isRecipient ? 'recipients' : 'others';
            $row = &$perItem[(int) $line->erp_item_id];
            $row['quantity_'.$key] += (float) $line->quantity;
            $row['value_'.$key] += (float) $line->net_value;
            unset($row);
            if ($isRecipient) {
                $valueRecipients += (float) $line->net_value;
                $recipientBuyers[$customerId] = true;
                if (count($buyers) < self::MAX_BUYERS) {
                    $item = $perItem[(int) $line->erp_item_id];
                    $buyers[] = [
                        'customer_id' => $customerId,
                        'acronym' => (string) ($line->customer->acronym ?? ''),
                        'name' => $line->customer->name ?? null,
                        'email' => $recipients['customers'][$customerId],
                        'sold_at' => $line->sold_at?->toDateString(),
                        'code' => $item['code'],
                        'item_name' => $item['name'],
                        'unit' => $item['unit'],
                        'quantity' => round((float) $line->quantity, 3),
                        'net_value' => round((float) $line->net_value, 2),
                        'document_number' => $line->document_number,
                    ];
                }
            } else {
                $valueOthers += (float) $line->net_value;
                $otherBuyers[$customerId ?? ('xl-'.$line->customer_xl_gid)] = true;
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => ErpCampaignSalesSync::WINDOW_DAYS,
            'complete' => $to->lt(CarbonImmutable::today()),
            'synced_at' => Cache::get(ErpCampaignSalesSync::SYNCED_AT_CACHE_KEY),
            'recipients_sent' => $recipients['sent'],
            // ilu odbiorców da się śledzić: klient XL albo adres, który jest na karcie kontrahenta XL
            'recipients_in_xl' => count($recipients['emails']),
            'recipients' => ['customers' => count($recipientBuyers), 'net_value' => round($valueRecipients, 2)],
            'others' => ['customers' => count($otherBuyers), 'net_value' => round($valueOthers, 2)],
            'items' => array_values(array_map(static fn (array $r): array => [
                ...$r,
                'quantity_recipients' => round($r['quantity_recipients'], 3),
                'quantity_others' => round($r['quantity_others'], 3),
                'value_recipients' => round($r['value_recipients'], 2),
                'value_others' => round($r['value_others'], 2),
            ], $perItem)),
            'buyers' => $buyers,
            'buyers_truncated' => count($buyers) >= self::MAX_BUYERS,
        ];
    }

    /**
     * Skrót do listy kampanii: ilu odbiorców kupiło i za ile netto.
     *
     * @param  list<int>  $campaignIds
     * @return array<int, array{customers: int, net_value: float, complete: bool}|null>
     */
    public function summaries(array $campaignIds): array
    {
        $out = array_fill_keys($campaignIds, null);
        $campaigns = Campaign::query()->whereIn('id', $campaignIds)->whereNotNull('sending_started_at')->get(['id', 'sending_started_at']);
        if ($campaigns->isEmpty()) {
            return $out;
        }
        $recipients = $this->recipientCustomers($campaigns->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $this->emailToCustomers());
        $itemsByCampaign = CampaignItem::query()->whereIn('campaign_id', $campaigns->pluck('id'))->whereNotNull('erp_item_id')
            ->get(['campaign_id', 'erp_item_id'])->groupBy('campaign_id');

        foreach ($campaigns as $campaign) {
            [$from, $to] = $this->window($campaign);
            $customerIds = array_keys($recipients[(int) $campaign->id]['customers'] ?? []);
            $itemIds = ($itemsByCampaign[$campaign->id] ?? collect())->pluck('erp_item_id')->unique()->values()->all();
            if ($customerIds === [] || $itemIds === []) {
                $out[(int) $campaign->id] = ['customers' => 0, 'net_value' => 0.0, 'complete' => $to->lt(CarbonImmutable::today())];

                continue;
            }
            // per klient od dnia jego maila (odbiorca dopisany później) — jak w forCampaign
            $since = $recipients[(int) $campaign->id]['since'];
            $buyers = [];
            $value = 0.0;
            foreach (ErpSaleLine::query()
                ->whereIn('erp_item_id', $itemIds)
                ->whereIntegerInRaw('erp_customer_id', $customerIds)
                ->whereBetween('sold_at', [$from->toDateString(), $to->toDateString()])
                ->get(['erp_customer_id', 'sold_at', 'net_value']) as $line) {
                $customerId = (int) $line->erp_customer_id;
                if ($line->sold_at === null || $line->sold_at->toDateString() < $since[$customerId]) {
                    continue;
                }
                $buyers[$customerId] = true;
                $value += (float) $line->net_value;
            }
            $out[(int) $campaign->id] = [
                'customers' => count($buyers),
                'net_value' => round($value, 2),
                'complete' => $to->lt(CarbonImmutable::today()),
            ];
        }

        return $out;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function window(Campaign $campaign): ?array
    {
        if ($campaign->sending_started_at === null) {
            return null;
        }
        $from = CarbonImmutable::parse($campaign->sending_started_at)->startOfDay();

        return [$from, $from->addDays(ErpCampaignSalesSync::WINDOW_DAYS)];
    }

    /**
     * Klienci XL, do których mail wyszedł: odbiorca z XL wprost, odbiorca z grupy — gdy jego adres jest na karcie
     * kontrahenta XL. customers: id klienta → adres, na który poszedł mail; since: id klienta → dzień pierwszego maila
     * (Y-m-d; odbiorca dopisany później ma późniejszy); emails: dopasowane adresy odbiorców.
     *
     * @param  list<int>  $campaignIds
     * @param  array<string, list<int>>  $emailCustomers
     * @return array<int, array{customers: array<int, string>, since: array<int, string>, emails: array<string, true>, sent: int}>
     */
    private function recipientCustomers(array $campaignIds, array $emailCustomers): array
    {
        $out = [];
        foreach (CampaignRecipient::query()->whereIn('campaign_id', $campaignIds)->where('status', CampaignRecipient::STATUS_SENT)
            ->orderBy('id')->get(['campaign_id', 'email', 'erp_customer_id', 'sent_at']) as $r) {
            $c = (int) $r->campaign_id;
            $out[$c] ??= ['customers' => [], 'since' => [], 'emails' => [], 'sent' => 0];
            $out[$c]['sent']++;
            $email = mb_strtolower((string) $r->email);
            $day = $r->sent_at?->toDateString() ?? '0000-00-00';
            $ids = $r->erp_customer_id !== null ? [(int) $r->erp_customer_id] : ($emailCustomers[$email] ?? []);
            foreach ($ids as $id) {
                $out[$c]['customers'][$id] ??= $email;
                $out[$c]['since'][$id] = min($out[$c]['since'][$id] ?? $day, $day);
                $out[$c]['emails'][$email] = true;
            }
        }

        return $out;
    }

    /** @return array<string, list<int>> adres (małe litery) → klienci XL, którzy mają go na karcie */
    private function emailToCustomers(): array
    {
        $map = [];
        foreach (ErpCustomer::query()->whereNotNull('emails')->whereNull('removed_at')->get(['id', 'emails']) as $c) {
            foreach (is_array($c->emails) ? $c->emails : [] as $email) {
                $map[mb_strtolower((string) $email)][] = (int) $c->id;
            }
        }

        return $map;
    }
}
