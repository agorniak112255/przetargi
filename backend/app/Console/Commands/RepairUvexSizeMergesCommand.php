<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Łączenie rozmiarów (15–16.09.2026) czytało końcówkę kodu UVEX z kropką jako rozmiar i skleiło 12 kart różnych
 * wyrobów albo wersji (kolory K JUNIOR 2600.010/.011/.013, klasy spawalnicze i-5 9183.041/.043/.045, warianty
 * u-cap…). Regułę poprawiono (e88b2db), a skasowane karty synchronizacja B2B założyła od nowa — na 12 kartach
 * docelowych zostały: SKU obcięte do rdzenia („2600.0”), lista merged_size_skus z kodami, które znów są osobnymi
 * kartami, zdjęcia i dokumenty przeniesione z kart skasowanych wtedy.
 *
 * Plan ustalony na produkcji 23.09.2026 (odczyt przez tinker): SKU = kod z własnego powiązania B2B karty;
 * obce zdjęcie = niegłówne, powstałe 2–4 s po karcie razem z kartą skasowaną i obecne na karcie odtworzonej
 * (plus zdjęcie z sieci „…2600-013-rozowy.jpg” na karcie niebieskiej); obcy dokument = z katalogu zasobów innego
 * wyrobu w B2B („SST super f OTG 9169.543.pdf” na karcie 9169.541) — wraca na kartę, do której należy.
 * Kombinezony 89843/89976 i czapka 9794.407 mają wspólne zdjęcie jako własne — zostaje.
 *
 * Zdjęcia idą przez ProductImageRejection::rejectAndDelete — odrzucenie nie pozwoli wzbogacaniu dołożyć ich znowu.
 * Historia cen skasowanych kart zostaje na kartach docelowych (ceny wersji były równe, a wierszy nie da się pewnie
 * przypisać). Każdy krok sprawdza, że rekord wciąż należy do tej karty — ponowne uruchomienie niczego nie psuje.
 * Domyślnie podgląd; zapis wymaga --apply i zostawia kopię zapasową stanu sprzed naprawy.
 */
final class RepairUvexSizeMergesCommand extends Command
{
    /**
     * karta => [sku: kod z własnego powiązania, images: obce zdjęcia, documents: dokument => karta, do której należy]
     *
     * @var array<int, array{sku: string, images?: list<int>, documents?: array<int, int>}>
     */
    private const PLAN = [
        23168 => ['sku' => '2111.235', 'images' => [24466], 'documents' => [10535 => 42438, 10536 => 42438]],
        23180 => ['sku' => '2112.010', 'images' => [24479], 'documents' => [10560 => 42441]],
        23226 => ['sku' => '2600.010', 'images' => [24523, 24525, 28713]],
        23489 => ['sku' => '9169.541', 'images' => [24774, 24775], 'documents' => [14623 => 42486, 14624 => 42487]],
        23521 => ['sku' => '9183.041', 'images' => [24806, 24807], 'documents' => [14672 => 42509]],
        23608 => ['sku' => '9199.245', 'images' => [24890], 'documents' => [14761 => 42551]],
        23637 => ['sku' => '9302.245', 'images' => [24919], 'documents' => [14800 => 42552]],
        24924 => ['sku' => '9774.237', 'images' => [26200]],
        24972 => ['sku' => '9794.407'],
        24988 => ['sku' => '9794.442', 'images' => [26260, 26261]],
        25074 => ['sku' => '89843.09'],
        25083 => ['sku' => '89976.09'],
    ];

    protected $signature = 'products:repair-uvex-size-merges
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Naprawia 12 kart UVEX błędnie scalonych jako rozmiary 15–16.09.2026: SKU, lista scalonych kodów, obce zdjęcia i dokumenty (podgląd bez --apply)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backup = [];
        $changed = 0;

        foreach (self::PLAN as $productId => $plan) {
            $product = Product::query()->find($productId);
            if ($product === null) {
                $this->line("#{$productId}: brak karty — pominięta");

                continue;
            }
            $steps = $this->steps($product, $plan);
            if ($steps['lines'] === []) {
                $this->line("#{$productId} [{$product->sku}]: bez zmian");

                continue;
            }
            $this->line("#{$productId} [{$product->sku}] ".mb_substr((string) $product->name, 0, 60));
            foreach ($steps['lines'] as $line) {
                $this->line('   '.$line);
            }
            // samo ostrzeżenie (zajęte SKU) to nie naprawa
            if ($steps['sku'] === null && $steps['unmerge'] === [] && $steps['images'] === [] && $steps['documents'] === []) {
                continue;
            }
            $changed++;
            if ($apply) {
                $backup[] = $this->snapshot($product, $steps);
            }
        }

        if (! $apply) {
            $this->info("Podgląd: {$changed} kart do naprawy. Zapis: --apply");

            return self::SUCCESS;
        }
        if ($backup === []) {
            $this->info('Nic do naprawy.');

            return self::SUCCESS;
        }

        $path = trim((string) $this->option('backup'));
        if ($path === '') {
            $path = storage_path('app/repair-backups/uvex-size-merges-'.now()->format('Ymd-His').'.json');
        }
        try {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            $this->error('Kopia zapasowa nie powstała: '.$e->getMessage().' — nic nie zapisano.');

            return self::FAILURE;
        }

        foreach (self::PLAN as $productId => $plan) {
            $product = Product::query()->find($productId);
            if ($product !== null) {
                $this->repair($product, $this->steps($product, $plan));
            }
        }
        $this->info("Naprawiono {$changed} kart. Kopia zapasowa: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array{sku: string, images?: list<int>, documents?: array<int, int>}  $plan
     * @return array{lines: list<string>, sku: string|null, unmerge: list<string>, images: list<ProductImage>, documents: array<int, array{document: ProductDocument, target: int, duplicate: bool}>}
     */
    private function steps(Product $product, array $plan): array
    {
        $lines = [];
        $sku = null;
        if ((string) $product->sku !== $plan['sku']) {
            $taken = Product::query()->where('sku', $plan['sku'])->where('id', '!=', $product->id)->value('id');
            if ($taken !== null) {
                $lines[] = "SKU {$plan['sku']} ma już karta #{$taken} — SKU bez zmian";
            } else {
                $sku = $plan['sku'];
                $lines[] = "SKU {$product->sku} → {$sku}";
            }
        }

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $unmerge = [];
        foreach (is_array($payload['merged_size_skus'] ?? null) ? $payload['merged_size_skus'] : [] as $merged) {
            $card = Product::query()->where('sku', (string) $merged)->value('id');
            if ($card !== null) {
                $unmerge[] = (string) $merged;
            }
        }
        if ($unmerge !== []) {
            $lines[] = 'lista scalonych kodów bez: '.implode(', ', $unmerge).' (osobne karty)';
        }

        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereIn('id', $plan['images'] ?? [])
            ->orderBy('id')
            ->get()
            ->all();
        foreach ($images as $image) {
            $lines[] = "zdjęcie #{$image->id} usunięte i odrzucone (".mb_substr((string) $image->source_url, 0, 70).')';
        }

        $documents = [];
        foreach ($plan['documents'] ?? [] as $documentId => $targetId) {
            $document = ProductDocument::query()->where('product_id', $product->id)->find($documentId);
            if ($document === null || ! Product::query()->whereKey($targetId)->exists()) {
                continue;
            }
            $duplicate = $document->checksum !== null && ProductDocument::query()
                ->where('product_id', $targetId)
                ->where('checksum', $document->checksum)
                ->exists();
            $documents[$documentId] = ['document' => $document, 'target' => $targetId, 'duplicate' => $duplicate];
            $lines[] = "dokument #{$documentId} ".($duplicate ? "usunięty (karta #{$targetId} ma ten sam plik)" : "→ karta #{$targetId}")
                .' ('.mb_substr(urldecode((string) $document->source_url), -60).')';
        }

        return ['lines' => $lines, 'sku' => $sku, 'unmerge' => $unmerge, 'images' => $images, 'documents' => $documents];
    }

    /**
     * @param  array{sku: string|null, unmerge: list<string>, images: list<ProductImage>, documents: array<int, array{document: ProductDocument, target: int, duplicate: bool}>}  $steps
     * @return array<string, mixed>
     */
    private function snapshot(Product $product, array $steps): array
    {
        return [
            'product_id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'enrichment_payload' => $product->enrichment_payload,
            'images' => array_map(static fn (ProductImage $i): array => $i->getAttributes(), $steps['images']),
            'documents' => array_map(
                static fn (array $d): array => ['target' => $d['target'], 'row' => $d['document']->getAttributes()],
                array_values($steps['documents']),
            ),
        ];
    }

    /**
     * @param  array{sku: string|null, unmerge: list<string>, images: list<ProductImage>, documents: array<int, array{document: ProductDocument, target: int, duplicate: bool}>}  $steps
     */
    private function repair(Product $product, array $steps): void
    {
        DB::transaction(function () use ($product, $steps): void {
            foreach ($steps['documents'] as $step) {
                if ($step['duplicate']) {
                    $step['document']->delete();

                    continue;
                }
                $step['document']->product_id = $step['target'];
                $step['document']->save();
            }
            if ($steps['unmerge'] !== []) {
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $left = array_values(array_diff((array) ($payload['merged_size_skus'] ?? []), $steps['unmerge']));
                if ($left === []) {
                    unset($payload['merged_size_skus']);
                } else {
                    $payload['merged_size_skus'] = $left;
                }
                $payload['unmerged_size_skus'] = $steps['unmerge'];
                $payload['unmerged_at'] = now()->toIso8601String();
                $product->enrichment_payload = $payload;
            }
            if ($steps['sku'] !== null) {
                $product->sku = $steps['sku'];
            }
            // zwykły save(): hak modelu przelicza indeks tekstowy i zleca reindeks wektora
            $product->save();
        });
        // osobno: rejectAndDelete ma własną transakcję i przenumerowuje zdjęcia karty
        foreach ($steps['images'] as $image) {
            ProductImageRejection::rejectAndDelete($image, ProductImageRejection::REASON_MANUAL);
        }
    }
}
