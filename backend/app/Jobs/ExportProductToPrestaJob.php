<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Services\Presta\PrestaProductExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExportProductToPrestaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [20, 60];

    public int $timeout = 180;

    public const QUEUE = 'default';

    public function __construct(
        public readonly int $productId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(PrestaProductExportService $export): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product instanceof Product) {
            return;
        }

        try {
            $export->export($product, $this->force);
        } catch (Throwable $e) {
            report($e);
            throw $e;
        }
    }
}
