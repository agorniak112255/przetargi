<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderItem;
use App\Models\User;

final class TenderActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function log(
        Tender $tender,
        string $action,
        ?User $user = null,
        ?TenderItem $item = null,
        ?array $meta = null,
    ): TenderActivity {
        return TenderActivity::query()->create([
            'tender_id' => $tender->id,
            'tender_item_id' => $item?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'meta' => $meta,
        ]);
    }
}
