<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\ProductImage;
use App\Models\ProductImageRejection;

/**
 * Jedno zdjęcie na kolor na karcie modelu (decyzja właściciela 28.09.2026): scalenie kart kolorów przenosiło na kartę
 * modelu całe galerie wszystkich kolorów (MAVIBO „PROMOSTARS HEAVY 21172” — 100 zdjęć). Zostaje zdjęcie główne każdej
 * karty sprzed scalenia (pierwsze w jej galerii), pozostałe zdjęcia tych kart idą przez odrzucenie
 * (ProductImageRejection::rejectAndDelete) — galeria dostawcy ich nie dołoży. Plik zostaje na dysku, wiersz jest
 * w kopii zapasowej scalenia. Zdjęcia spoza galerii scalanych kart (dodane później) nie są ruszane.
 */
final class ColourGalleryTrim
{
    /** Scalenie kolorów, nie rozmiarów: łącznik podał listę „Kolory: …” (B2bRemoteProduct::variantSummary). */
    public static function isColourGroup(array $group): bool
    {
        return str_starts_with(trim((string) ($group['variant_summary'] ?? '')), 'Kolory:');
    }

    /**
     * Zdjęcia do usunięcia z karty modelu: z galerii każdej karty sprzed scalenia wszystkie poza główną (is_primary,
     * potem sort_order i id — jak kolejność galerii), tylko te, które nadal są na karcie modelu.
     *
     * @param  array<int, list<array<string, mixed>>>  $imagesByCard  id karty sprzed scalenia => wiersze product_images
     * @return list<int>
     */
    public function surplus(array $imagesByCard, int $keepId): array
    {
        $candidates = [];
        foreach ($imagesByCard as $rows) {
            usort($rows, static fn (array $a, array $b): int => [(int) ! ((bool) ($a['is_primary'] ?? false)), (int) ($a['sort_order'] ?? 0), (int) $a['id']]
                <=> [(int) ! ((bool) ($b['is_primary'] ?? false)), (int) ($b['sort_order'] ?? 0), (int) $b['id']]);
            foreach (array_slice($rows, 1) as $row) {
                $candidates[] = (int) $row['id'];
            }
        }
        if ($candidates === []) {
            return [];
        }

        return ProductImage::query()->where('product_id', $keepId)->whereIn('id', $candidates)->orderBy('id')
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $imageIds
     */
    public function remove(array $imageIds): int
    {
        $removed = 0;
        foreach (ProductImage::query()->whereIn('id', $imageIds)->orderBy('id')->get() as $image) {
            ProductImageRejection::rejectAndDelete($image, ProductImageRejection::REASON_COLOUR_GALLERY);
            $removed++;
        }

        return $removed;
    }
}
