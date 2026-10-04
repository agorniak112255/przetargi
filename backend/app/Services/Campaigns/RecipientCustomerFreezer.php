<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRecipientCustomer;
use App\Models\ErpCustomer;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zamrożenie „odbiorca kampanii → klienci ERP XL” (campaign_recipient_customers) przy starcie wysyłki i przy dopisaniu
 * odbiorców — raport „Wynik kampanii” nie zmienia się po późniejszej edycji kart w XL. Reguła jak w
 * CampaignSalesResult: odbiorca wybrany jako klient XL (erp_customer_id) → direct; adres z grupy (małe litery) na
 * kartach kontrahentów (erp_customers.emails, bez usuniętych) → email, wiersz na każdą kartę, cards_count = liczba kart.
 * Tylko odbiorcy bez wierszy (idempotentne); odbiorca bez dopasowania nie dostaje wiersza.
 */
final class RecipientCustomerFreezer
{
    private const CHUNK = 500;

    /** Zwraca liczbę zapisanych wierszy. */
    public function freeze(Campaign $campaign): int
    {
        $recipients = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotExists(static fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('campaign_recipient_customers as crc')
                ->whereColumn('crc.campaign_recipient_id', 'campaign_recipients.id'))
            ->orderBy('id')
            ->get(['id', 'email', 'erp_customer_id']);
        if ($recipients->isEmpty()) {
            return 0;
        }

        $wanted = [];
        foreach ($recipients as $r) {
            if ($r->erp_customer_id === null) {
                $wanted[mb_strtolower(trim((string) $r->email))] = true;
            }
        }
        $cards = $this->cardsByEmail($wanted);

        $now = Carbon::now();
        $rows = [];
        foreach ($recipients as $r) {
            if ($r->erp_customer_id !== null) {
                $matches = [(int) $r->erp_customer_id];
                $by = CampaignRecipientCustomer::MATCHED_DIRECT;
            } else {
                $matches = $cards[mb_strtolower(trim((string) $r->email))] ?? [];
                $by = CampaignRecipientCustomer::MATCHED_EMAIL;
            }
            foreach ($matches as $customerId) {
                $rows[] = [
                    'campaign_id' => (int) $campaign->id,
                    'campaign_recipient_id' => (int) $r->id,
                    'erp_customer_id' => $customerId,
                    'matched_by' => $by,
                    'cards_count' => count($matches),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $saved = 0;
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // unikalny (odbiorca, klient) — równoległe zamrożenie tego samego odbiorcy nie zdubluje wierszy
            $saved += DB::table('campaign_recipient_customers')->insertOrIgnore($chunk);
        }

        return $saved;
    }

    /**
     * Karty kontrahentów (bez usuniętych) z adresami z listy — jeden przebieg po kartach z e-mailem.
     *
     * @param  array<string, true>  $wanted  adres (małe litery) => true
     * @return array<string, list<int>> adres => id kart rosnąco, bez powtórzeń
     */
    private function cardsByEmail(array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }
        $out = [];
        foreach (ErpCustomer::query()->whereNotNull('emails')->whereNull('removed_at')->select(['id', 'emails'])->lazyById(1000) as $c) {
            foreach (is_array($c->emails) ? $c->emails : [] as $email) {
                $email = mb_strtolower(trim((string) $email));
                if (isset($wanted[$email])) {
                    $out[$email][(int) $c->id] = (int) $c->id;
                }
            }
        }

        return array_map(static fn (array $ids): array => array_values($ids), $out);
    }
}
