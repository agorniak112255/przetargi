<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Presta\PrestaCategoryRewriteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class RewriteProductCategoriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public const QUEUE = 'default';

    public const CACHE_KEY = 'presta.category_rewrite';

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(PrestaCategoryRewriteService $rewrite): void
    {
        $result = $rewrite->rewrite();
        Cache::put(self::CACHE_KEY, $result + [
            'running' => false,
            'finished_at' => now()->toIso8601String(),
        ], 3600);
    }
}
