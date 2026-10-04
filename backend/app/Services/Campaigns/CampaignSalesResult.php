<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\ErpSaleLine;
use App\Services\Erp\ErpCampaignSalesSync;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * „Kupili odbiorcy kampanii”: faktury i paragony z XL z towarami kampanii od dnia wysyłki przez
 * ErpCampaignSalesSync::WINDOW_DAYS dni (CampaignWindow, dni w czasie polskim) — osobno odbiorcy (klient XL, któremu
 * mail wyszedł: zamrożony przy wysyłce w campaign_recipient_customers, a w kampaniach sprzed tego — klient XL odbiorcy
 * albo karta z jego adresem) i pozostali klienci dla porównania. Zakup po mailu nie dowodzi, że kupił dzięki kampanii.
 * Korekty (FSK/PAK) odejmują ilość i wartość w tej grupie, do której należy korygowana pozycja z okna; korekta pozycji
 * spoza okna się nie liczy. Ilości tylko per towar (różne jednostki się nie sumują); wartość netto PLN można sumować.
 */
final class CampaignSalesResult
{
    /** Najwięcej wierszy „kto kupił” w odpowiedzi. */
    private const MAX_BUYERS = 200;

    public function __construct(private readonly CampaignAttribution $attribution) {}

    /** @return array<string, mixed>|null null = kampania jeszcze nie wysyłana */
    public function forCampaign(Campaign $campaign): ?array
    {
        [$from, $to] = $this->window($campaign) ?? [null, null];
        if ($from === null) {
            return null;
        }
        $recipients = $this->recipientCustomers([(int) $campaign->id => $from->toDateString()])[(int) $campaign->id];
        $items = CampaignItem::query()->where('campaign_id', $campaign->id)->whereNotNull('erp_item_id')
            ->with('erpItem:id,code,name,unit')->orderBy('position')->get();
        $itemIds = $items->pluck('erp_item_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        $lines = $itemIds === [] ? collect() : ErpSaleLine::query()
            ->whereIn('erp_item_id', $itemIds)
            // przedział [od, do + 1 dzień) — także dla daty zapisanej z godziną 00:00:00
            ->where('sold_at', '>=', $from->toDateString())
            ->where('sold_at', '<', $to->addDay()->toDateString())
            ->with('customer:id,acronym,name')
            ->orderBy('sold_at')->orderBy('id')
            ->get();
        $byDocument = self::byDocument($lines->all());

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
        // grupa pozycji sprzedaży (id → odbiorca?) — korekta trafia tam, gdzie pozycja, którą koryguje
        $groupOf = [];
        // najpierw faktury i paragony, potem korekty (korekta może mieć niższe id niż korygowana pozycja)
        foreach ([false, true] as $corrections) {
            foreach ($lines as $line) {
                $type = (int) $line->document_type;
                if ($corrections !== CampaignAttribution::isCorrection($type)) {
                    continue;
                }
                if ($corrections) {
                    $root = self::correctedSale($line, $byDocument);
                    if ($root === null || ! isset($groupOf[$root->id])) {
                        continue;
                    }
                    $isRecipient = $groupOf[$root->id];
                    $customerId = $root->erp_customer_id !== null ? (int) $root->erp_customer_id : null;
                } else {
                    if (! CampaignAttribution::isSale($type, (float) $line->quantity)) {
                        continue;
                    }
                    $customerId = $line->erp_customer_id !== null ? (int) $line->erp_customer_id : null;
                    // odbiorca dopisany później: jego zakupy sprzed maila liczą się jak pozostałych klientów
                    $isRecipient = $customerId !== null && isset($recipients['customers'][$customerId])
                        && $line->sold_at !== null && $line->sold_at->toDateString() >= $recipients['since'][$customerId];
                    $groupOf[$line->id] = $isRecipient;
                }
                $key = $isRecipient ? 'recipients' : 'others';
                $row = &$perItem[(int) $line->erp_item_id];
                $row['quantity_'.$key] += (float) $line->quantity;
                $row['value_'.$key] += (float) $line->net_value;
                unset($row);
                if ($isRecipient && $customerId !== null) {
                    $valueRecipients += (float) $line->net_value;
                    // kupujący = faktura albo paragon; sama korekta nie robi z klienta kupującego
                    if (! $corrections) {
                        $recipientBuyers[$customerId] = true;
                    }
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
                } else {
                    $valueOthers += (float) $line->net_value;
                    if (! $corrections) {
                        $otherBuyers[$customerId ?? ('xl-'.$line->customer_xl_gid)] = true;
                    }
                }
            }
        }
        // kolejność dat jak dotąd (sortowanie stabilne — w tym samym dniu faktura przed korektą)
        usort($buyers, static fn (array $a, array $b): int => (string) $a['sold_at'] <=> (string) $b['sold_at']);
        $buyers = array_slice($buyers, 0, self::MAX_BUYERS);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => ErpCampaignSalesSync::WINDOW_DAYS,
            'complete' => $to->lt(PolishTime::today()),
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
        $startDays = [];
        foreach ($campaigns as $campaign) {
            $startDays[(int) $campaign->id] = (string) CampaignWindow::startDay($campaign)?->toDateString();
        }
        $recipients = $this->recipientCustomers($startDays);
        $itemsByCampaign = CampaignItem::query()->whereIn('campaign_id', $campaigns->pluck('id'))->whereNotNull('erp_item_id')
            ->get(['campaign_id', 'erp_item_id'])->groupBy('campaign_id');

        foreach ($campaigns as $campaign) {
            [$from, $to] = $this->window($campaign);
            $customerIds = array_keys($recipients[(int) $campaign->id]['customers'] ?? []);
            $itemIds = ($itemsByCampaign[$campaign->id] ?? collect())->pluck('erp_item_id')->unique()->values()->all();
            if ($customerIds === [] || $itemIds === []) {
                $out[(int) $campaign->id] = ['customers' => 0, 'net_value' => 0.0, 'complete' => $to->lt(PolishTime::today())];

                continue;
            }
            // per klient od dnia jego maila (odbiorca dopisany później) — jak w forCampaign
            $since = $recipients[(int) $campaign->id]['since'];
            $buyers = [];
            $value = 0.0;
            $lines = ErpSaleLine::query()
                ->whereIn('erp_item_id', $itemIds)
                ->whereIntegerInRaw('erp_customer_id', $customerIds)
                ->where('sold_at', '>=', $from->toDateString())
                ->where('sold_at', '<', $to->addDay()->toDateString())
                ->get(['id', 'document_type', 'document_id', 'line', 'erp_item_id', 'erp_customer_id', 'sold_at', 'quantity', 'net_value', 'corrects_document_type', 'corrects_document_id'])
                ->all();
            $byDocument = self::byDocument($lines);
            $counted = [];
            foreach ($lines as $line) {
                $customerId = (int) $line->erp_customer_id;
                if (! CampaignAttribution::isSale((int) $line->document_type, (float) $line->quantity)
                    || $line->sold_at === null || $line->sold_at->toDateString() < $since[$customerId]) {
                    continue;
                }
                $counted[$line->id] = true;
                $buyers[$customerId] = true;
                $value += (float) $line->net_value;
            }
            // korekta odejmuje tylko od policzonej pozycji z okna
            foreach ($lines as $line) {
                if (CampaignAttribution::isCorrection((int) $line->document_type)
                    && ($root = self::correctedSale($line, $byDocument)) !== null && isset($counted[$root->id])) {
                    $value += (float) $line->net_value;
                }
            }
            $out[(int) $campaign->id] = [
                'customers' => count($buyers),
                'net_value' => round($value, 2),
                'complete' => $to->lt(PolishTime::today()),
            ];
        }

        return $out;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null dzień startu i ostatni dzień okna (czas polski) */
    private function window(Campaign $campaign): ?array
    {
        $from = CampaignWindow::startDay($campaign);
        $to = CampaignWindow::endDay($campaign);

        return $from === null || $to === null ? null : [$from, $to];
    }

    /**
     * Pozycje według dokumentu i towaru (najniższy numer pozycji) — do znalezienia pozycji korygowanej.
     *
     * @param  list<ErpSaleLine>  $lines
     * @return array<string, ErpSaleLine>
     */
    private static function byDocument(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $key = $line->document_type.':'.$line->document_id.':'.$line->erp_item_id;
            if (! isset($out[$key]) || $out[$key]->line > $line->line) {
                $out[$key] = $line;
            }
        }

        return $out;
    }

    /**
     * Faktura albo paragon, który korekta (także korekta korekty) koryguje — wśród pozycji z okna; null = brak.
     *
     * @param  array<string, ErpSaleLine>  $byDocument
     */
    private static function correctedSale(ErpSaleLine $line, array $byDocument): ?ErpSaleLine
    {
        for ($depth = 0; $depth < 5; $depth++) {
            if (! CampaignAttribution::isCorrection((int) $line->document_type)) {
                return CampaignAttribution::isSale((int) $line->document_type, (float) $line->quantity) ? $line : null;
            }
            if ($line->corrects_document_type === null || $line->corrects_document_id === null) {
                return null;
            }
            $next = $byDocument[$line->corrects_document_type.':'.$line->corrects_document_id.':'.$line->erp_item_id] ?? null;
            if ($next === null || $next->id === $line->id) {
                return null;
            }
            $line = $next;
        }

        return null;
    }

    /**
     * Klienci XL, do których mail wyszedł (CampaignAttribution::recipientMatches: zamrożeni przy wysyłce, w starszych
     * kampaniach — klient XL odbiorcy albo karta z jego adresem). customers: id klienta → adres, na który poszedł mail;
     * since: id klienta → dzień pierwszego maila (Y-m-d, czas polski; odbiorca dopisany później ma późniejszy);
     * emails: dopasowane adresy odbiorców.
     *
     * @param  array<int, string>  $startDays  id kampanii → dzień startu
     * @return array<int, array{customers: array<int, string>, since: array<int, string>, emails: array<string, true>, sent: int}>
     */
    private function recipientCustomers(array $startDays): array
    {
        $out = [];
        foreach ($this->attribution->recipientMatches($startDays) as $campaignId => $m) {
            $row = ['customers' => [], 'since' => [], 'emails' => $m['emails'], 'sent' => $m['sent']];
            foreach ($m['customers'] as $customerId => $entries) {
                $row['customers'][$customerId] = $entries[0]['email'];
                $row['since'][$customerId] = min(array_column($entries, 'day'));
            }
            $out[$campaignId] = $row;
        }

        return $out;
    }
}
