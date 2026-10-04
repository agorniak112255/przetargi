<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Raport „Wynik kampanii” (04.10.2026): uzupełnienie campaign_recipient_customers dla odbiorców kampanii wystartowanych
 * przed tą zmianą — reguła jak App\Services\Campaigns\RecipientCustomerFreezer (kod samodzielny, bez klas aplikacji):
 * erp_customer_id odbiorcy → direct; inaczej adres (małe litery) na kartach erp_customers.emails bez usuniętych →
 * email, wiersz na każdą kartę, cards_count = liczba kart. Karty są dzisiejsze, nie z dnia wysyłki — innych nie ma.
 * Odbiorcy, którzy mają już wiersze, są pomijani. down() kasuje wszystkie wiersze tabeli.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        // adres → karty (id rosnąco, bez powtórzeń)
        $cards = [];
        foreach (DB::table('erp_customers')->whereNotNull('emails')->whereNull('removed_at')->select(['id', 'emails'])->lazyById(1000) as $c) {
            $emails = is_string($c->emails) ? json_decode($c->emails, true) : null;
            foreach (is_array($emails) ? $emails : [] as $email) {
                $email = mb_strtolower(trim((string) $email));
                if ($email !== '') {
                    $cards[$email][(int) $c->id] = (int) $c->id;
                }
            }
        }

        $now = now();
        DB::table('campaign_recipients as r')
            ->join('campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->whereNotNull('c.sending_started_at')
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')
                ->from('campaign_recipient_customers as crc')
                ->whereColumn('crc.campaign_recipient_id', 'r.id'))
            ->select(['r.id', 'r.campaign_id', 'r.email', 'r.erp_customer_id'])
            ->chunkById(self::CHUNK, static function ($recipients) use ($cards, $now): void {
                $rows = [];
                foreach ($recipients as $r) {
                    if ($r->erp_customer_id !== null) {
                        $matches = [(int) $r->erp_customer_id];
                        $by = 'direct';
                    } else {
                        $matches = array_values($cards[mb_strtolower(trim((string) $r->email))] ?? []);
                        $by = 'email';
                    }
                    foreach ($matches as $customerId) {
                        $rows[] = [
                            'campaign_id' => (int) $r->campaign_id,
                            'campaign_recipient_id' => (int) $r->id,
                            'erp_customer_id' => $customerId,
                            'matched_by' => $by,
                            'cards_count' => count($matches),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('campaign_recipient_customers')->insertOrIgnore($chunk);
                }
            }, 'r.id', 'id');
    }

    public function down(): void
    {
        DB::table('campaign_recipient_customers')->delete();
    }
};
