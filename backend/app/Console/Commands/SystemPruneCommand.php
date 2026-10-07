<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Czyszczenie starych danych pomocniczych (codziennie 4:35 czasu polskiego):
 * - wpisy ochrony przed powtórką powiadomień (notification_dispatches) starsze niż
 *   notifications.dispatches_retention_days,
 * - przebiegi zadań (scheduled_task_runs) starsze niż system_health.runs_retention_days,
 * - pełny HTML ogłoszeń z Biuletynu niepowiązanych z przetargiem ani z częścią wyniku, pobranych dawniej niż
 *   bzp.html_retention_days (sam wiersz ogłoszenia i odczytane z niego dane zostają),
 * - logowania z kodem e-mailem (network_access_challenges) starsze niż 7 dni i dostępy spoza sieci z kodem
 *   (network_access_grants) po 90 dniach od końca ważności — kto i kiedy logował się kodem zostaje w dzienniku aktywności.
 * Porcjami po id — bez długich blokad tabel i bez wczytywania treści do pamięci.
 */
final class SystemPruneCommand extends Command
{
    protected $signature = 'system:prune';

    protected $description = 'Usuwa stare przebiegi zadań, wpisy wysłanych powiadomień i treść starych ogłoszeń z Biuletynu';

    private const BATCH = 1000;

    public function handle(): int
    {
        DB::disableQueryLog();

        $dispatches = $this->deleteOlderThan('notification_dispatches', (int) config('notifications.dispatches_retention_days', 180));
        $runs = $this->deleteOlderThan('scheduled_task_runs', (int) config('system_health.runs_retention_days', 60));
        $html = $this->clearNoticeHtml((int) config('bzp.html_retention_days', 30));
        $challenges = $this->deleteOlderThan('network_access_challenges', 7);
        $grants = $this->deleteExpiredGrants(90);

        $this->info(sprintf(
            'Usunięte wpisy wysłanych powiadomień: %d. Usunięte przebiegi zadań: %d. Wyczyszczona treść ogłoszeń: %d. Usunięte logowania z kodem: %d, stare dostępy z kodem: %d.',
            $dispatches,
            $runs,
            $html,
            $challenges,
            $grants,
        ));

        return self::SUCCESS;
    }

    private function deleteOlderThan(string $table, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cut = now()->subDays($days);
        $deleted = 0;
        do {
            $ids = DB::table($table)->where('created_at', '<', $cut)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if ($ids !== []) {
                $deleted += DB::table($table)->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === self::BATCH);

        return $deleted;
    }

    private function deleteExpiredGrants(int $days): int
    {
        return DB::table('network_access_grants')->where('expires_at', '<', now()->subDays($days))->delete();
    }

    private function clearNoticeHtml(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cut = now()->subDays($days);
        $cleared = 0;
        $afterId = 0;
        do {
            $ids = DB::table('procurement_notices as pn')
                ->where('pn.id', '>', $afterId)
                ->whereNotNull('pn.html_body')
                ->where('pn.fetched_at', '<', $cut)
                ->whereNotExists(static fn (Builder $q) => $q->from('tenders')->selectRaw('1')
                    ->where(static fn (Builder $w) => $w->whereColumn('tenders.contract_notice_id', 'pn.id')->orWhereColumn('tenders.result_notice_id', 'pn.id')))
                ->whereNotExists(static fn (Builder $q) => $q->from('tender_lots')->selectRaw('1')->whereColumn('tender_lots.bzp_notice_id', 'pn.id'))
                ->orderBy('pn.id')
                ->limit(self::BATCH)
                ->pluck('pn.id')
                ->all();
            if ($ids !== []) {
                $cleared += DB::table('procurement_notices')->whereIn('id', $ids)->update(['html_body' => null]);
                $afterId = (int) max($ids);
            }
        } while (count($ids) === self::BATCH);

        return $cleared;
    }
}
