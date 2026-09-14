<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;

/**
 * Przebieg w bieżącym procesie, konto po koncie — w testach i jako zastępstwo na Windows (lokalny XAMPP).
 */
final class InlineB2bSyncLauncher implements B2bSyncLauncher
{
    public function __construct(private readonly B2bAccountSyncRunner $runner) {}

    public function launch(B2bAccount $account, string $trigger): bool
    {
        $this->runner->run($account, trigger: $trigger);

        return true;
    }
}
