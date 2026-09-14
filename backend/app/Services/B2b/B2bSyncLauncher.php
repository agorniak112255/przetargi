<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;

/**
 * Uruchomienie przebiegu konta z b2b:sync-due. Tło (osobny proces na konto) pozwala pobierać cenniki
 * różnych dostawców równolegle; to samo konto nie ruszy dwa razy — pilnuje tego zajęcie konta w runnerze.
 * Wiązanie: AppServiceProvider (w testach InlineB2bSyncLauncher, poza testami BackgroundB2bSyncLauncher).
 */
interface B2bSyncLauncher
{
    /**
     * @param  string  $trigger  B2bSyncRun::TRIGGER_MANUAL albo TRIGGER_SCHEDULE
     * @return bool true — przebieg zakończył się już w tym procesie (wynik w $account->last_sync_message);
     *              false — przebieg ruszył w tle
     *
     * @throws \Throwable gdy przebiegu nie udało się uruchomić (albo — w tym procesie — gdy przebieg się nie udał)
     */
    public function launch(B2bAccount $account, string $trigger): bool;
}
