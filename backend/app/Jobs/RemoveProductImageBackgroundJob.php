<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProductImage;
use App\Services\Catalog\ProductImageBackgroundRemover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Wycięcie tła z jednego zdjęcia karty (ProductImageBackgroundRemover). Osobna kolejka `images` na własnej tabeli
 * (`jobs_images`, połączenie z config('queue.images_connection')) i jeden worker: rembg liczy ~20 s na zdjęcie
 * i nie zniesie dwóch zapytań naraz.
 */
final class RemoveProductImageBackgroundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'images';

    /** Kolejne próby tylko po zajętej blokadzie rembg (release) — błędy usługi zapisuje sama usługa. */
    public int $tries = 5;

    /** Zapytanie do rembg do 300 s + odczyt i zapis plików; mniej niż retry_after połączenia (480 s). */
    public int $timeout = 420;

    public function __construct(public readonly int $imageId)
    {
        $this->onConnection(config('queue.images_connection'));
        $this->onQueue(self::QUEUE);
    }

    public function handle(ProductImageBackgroundRemover $remover): void
    {
        $image = ProductImage::query()->find($this->imageId);
        if ($image === null) {
            return;
        }
        try {
            $remover->remove($image);
        } catch (LockTimeoutException) {
            $this->release(30);
        }
    }

    /** Przerwane zadanie (limit czasu, wyczerpane próby) nie może zostawić zdjęcia „w kolejce” na zawsze. */
    public function failed(?Throwable $exception): void
    {
        ProductImage::query()
            ->whereKey($this->imageId)
            ->where('background_status', ProductImage::BACKGROUND_QUEUED)
            ->update([
                'background_status' => ProductImage::BACKGROUND_FAILED,
                'background_note' => mb_substr('przerwane: '.($exception?->getMessage() ?: 'zadanie nie skończyło się'), 0, 255),
                'updated_at' => now(),
            ]);
    }
}
