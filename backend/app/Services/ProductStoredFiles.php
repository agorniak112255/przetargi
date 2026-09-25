<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductDocument;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pliki kart na dysku public: zdjęcia (product_images) i dokumenty — karty techniczne, deklaracje, instrukcje
 * (product_documents). Usunięcie karty (ProductDeletionService) i cennika (PriceListDeletionService) kasuje je
 * dopiero po commit. Wcześniej kasowało je w transakcji: wycofanie (zakleszczenie przy dużym kasowaniu, błąd
 * w dalszym kroku) zostawiało karty z wierszami zdjęć i dokumentów, ale bez plików — nie do odtworzenia.
 */
final class ProductStoredFiles
{
    /** Ile nieusuniętych ścieżek trafia do dziennika — przy awarii dysku nie cały katalog kart. */
    private const LOGGED_PATHS = 20;

    /**
     * Ścieżki plików kart na dysku — zebrać przed usunięciem kart, bo kaskada kasuje wiersze zdjęć i dokumentów
     * razem z kartą. Bez zdjęć spoza dysku (path „remote” albo adres http).
     *
     * @param  list<int>  $productIds
     * @return list<string>
     */
    public function pathsOf(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $paths = [
            ...ProductImage::query()->whereIn('product_id', $productIds)->pluck('path')->all(),
            ...ProductDocument::query()->whereIn('product_id', $productIds)->pluck('path')->all(),
        ];

        return array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && self::isStored($path),
        )));
    }

    /**
     * Kasuje pliki po commit bieżącej transakcji — wycofana transakcja zostawia je razem z kartami. Nic nie wychodzi
     * z wywołania po commit: karty są już usunięte, a wyjątek zgłosiłby porażkę udanego usunięcia (kontroler → 422).
     * Błąd dysku tylko w dzienniku; plik bez wiersza w bazie znajdzie potem products:media-report.
     *
     * @param  list<string>  $paths
     */
    public function deleteAfterCommit(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        DB::afterCommit(function () use ($paths): void {
            try {
                $this->delete($paths);
            } catch (Throwable) {
                // sprzątanie plików nie cofa usunięcia kart
            }
        });
    }

    /**
     * @param  list<string>  $paths
     */
    private function delete(array $paths): void
    {
        $failed = [];
        $error = null;
        foreach ($paths as $path) {
            try {
                // brak pliku to nie błąd; false = dysk odmówił ('throw' => false w konfiguracji dysku)
                if (! Storage::disk('public')->delete($path)) {
                    $failed[] = $path;
                }
            } catch (Throwable $e) {
                $failed[] = $path;
                $error ??= $e->getMessage();
            }
        }

        if ($failed !== []) {
            Log::warning('Product files delete failed', [
                'failed' => count($failed),
                'paths' => array_slice($failed, 0, self::LOGGED_PATHS),
                'error' => $error,
            ]);
        }
    }

    private static function isStored(string $path): bool
    {
        return $path !== ''
            && $path !== 'remote'
            && ! str_starts_with($path, 'http://')
            && ! str_starts_with($path, 'https://');
    }
}
