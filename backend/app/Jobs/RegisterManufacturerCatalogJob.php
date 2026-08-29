<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerCatalogRegistrar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RegisterManufacturerCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 90];

    public int $timeout = 240;

    public int $uniqueFor = 3600;

    public const QUEUE = 'default';

    public function __construct(
        public readonly string $manufacturer,
        public readonly int $sampleProductId = 0,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        $key = mb_strtolower(trim($this->manufacturer));
        $key = preg_replace('/[^a-z0-9]+/u', '-', $key) ?? $key;

        return trim($key, '-');
    }

    public function handle(ManufacturerCatalogRegistrar $registrar): void
    {
        $sample = $this->sampleProductId > 0
            ? Product::query()->find($this->sampleProductId)
            : null;

        $registrar->register($this->manufacturer, $sample, true);
    }
}
