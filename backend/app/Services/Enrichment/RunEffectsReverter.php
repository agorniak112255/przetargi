<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Support\ManufacturerNormFacts;

/**
 * Skutki przebiegu wersji opisu poza samym opisem — cofane, gdy opis z tej wersji schodzi z karty bez nowego
 * pobrania: odrzucenie opisu z karty w przeglądzie (ProductReviewService::reject) i wycofanie partii
 * (products:rollback-batch). Jedna reguła dla obu miejsc:
 *   - zdjęcia i pliki z internetu dodane przez ten przebieg (_version.web_file_ids, DescriptionVersionStore::versionMeta)
 *     znikają z karty i z dysku razem z wpisem pamięci SKU tej karty — tylko pliki z internetu (z adresem źródła, bez
 *     konta B2B; plik wgrany ręcznie zostaje: ProductEnrichmentResetter::dropRejectedRunFiles) i nic spoza listy
 *     tej wersji;
 *   - normy producenta wracają do stanu sprzed przebiegu (manufacturer_norms_before) tylko wtedy, gdy ten przebieg je
 *     zapisał i na karcie stoi dalej jego wartość (ManufacturerNormFacts::sameFacts, ta sama reguła co przy
 *     propozycji); zapis późniejszy (synchronizacja, inny przebieg, handlowiec) zostaje.
 * Wersja bez danych technicznych (bazowa, z przeglądu, propozycja) — tylko pamięć SKU. Plików z internetu, które
 * przebieg z force usunął z karty przed zapisem (ProductEnrichmentService::dropPreviousWebFiles), nic nie przywraca.
 */
final class RunEffectsReverter
{
    public function __construct(
        private readonly DescriptionVersionStore $versions,
        private readonly ProductEnrichmentResetter $resetter,
    ) {}

    /**
     * Zdjęcia i dokumenty, które revert() usunie z karty: z listy wersji (_version.web_file_ids) te, które są na karcie
     * dalej jako pliki z internetu — do podglądu i dziennika przed zapisem.
     *
     * @return array{images: list<int>, documents: list<int>}
     */
    public function filesToDrop(Product $product, ProductDescriptionVersion $v): array
    {
        $ids = $this->webFileIds($v);
        $present = static fn (string $model, array $wanted): array => $wanted === [] ? [] : $model::query()
            ->where('product_id', $product->id)
            ->whereNull('b2b_account_id')
            ->whereNotNull('source_url')
            ->where('source_url', '!=', '')
            ->whereIn('id', $wanted)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [
            'images' => $present(ProductImage::class, $ids['images']),
            'documents' => $present(ProductDocument::class, $ids['documents']),
        ];
    }

    /**
     * Cofa skutki przebiegu wersji (opis klasy). Wołać na zablokowanym wierszu karty, przed zapisem poprzedniego opisu
     * (DescriptionVersionStore::publish liczy listę plików karty już bez usuniętych).
     */
    public function revert(Product $product, ProductDescriptionVersion $v): void
    {
        $meta = $this->versions->meta($v);
        $files = $this->webFileIds($v);
        $this->resetter->dropRejectedRunFiles($product, $files['images'], $files['documents']);
        $written = $meta['manufacturer_norms_written'] ?? null;
        if (array_key_exists('manufacturer_norms_before', $meta)
            && is_array($written)
            && ManufacturerNormFacts::sameFacts($product->manufacturer_norms, $written)) {
            // przez model — hak saving przelicza indeks wyszukiwania
            $product->manufacturer_norms = $meta['manufacturer_norms_before'];
            if ($product->isDirty('manufacturer_norms')) {
                $product->save();
            }
        }
    }

    /**
     * Numery zdjęć i dokumentów z internetu dodanych przez przebieg wersji (_version.web_file_ids).
     *
     * @return array{images: list<int>, documents: list<int>}
     */
    private function webFileIds(ProductDescriptionVersion $v): array
    {
        $meta = $this->versions->meta($v);
        $files = is_array($meta['web_file_ids'] ?? null) ? $meta['web_file_ids'] : [];
        $ids = static fn (mixed $list): array => array_values(array_map(
            static fn ($id): int => (int) $id,
            array_filter(is_array($list) ? $list : [], 'is_numeric'),
        ));

        return ['images' => $ids($files['images'] ?? []), 'documents' => $ids($files['documents'] ?? [])];
    }
}
