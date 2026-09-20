<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bDocumentText;
use Illuminate\Console\Command;

/**
 * Odtwarza opis karty z tekstu jej karty technicznej tam, gdzie karta została bez opisu.
 *
 * Powód: przebieg witryny producenta z 20.09.2026 (UVEX) wyczyścił opisy złożone z samego tekstu PDF-a —
 * łącznik oddał pusty opis, a reguła „producent wycofał swój opis” (B2bCatalogSync::ownDescriptionIsGone)
 * nie odróżniała jeszcze tekstu ze sklepu od dawnego dopisku z karty technicznej. Karta bez opisu wypada
 * z propozycji przetargowych, więc tekst wraca w tej samej postaci, w jakiej zapisywała go dawna
 * synchronizacja (nagłówek B2bCatalogSync::DATASHEET_MARK + oczyszczony tekst dokumentu) — do czasu, aż
 * karta dostanie prawdziwy opis. Tekst dokumentu jest w bazie (product_documents.text), więc nic nie
 * trzeba pobierać.
 *
 * Bierzemy tylko karty bez tekstu opisu, z powiązaniem B2B i z kartą techniczną, która ma tekst. Karta,
 * która opis ma — jakikolwiek — zostaje nietknięta. Domyślnie polecenie **tylko liczy i wypisuje**;
 * zapisuje wyłącznie po jawnym `--apply`, razem z odciskiem opisu w powiązaniu.
 */
final class B2bRestoreDatasheetDescriptionsCommand extends Command
{
    protected $signature = 'b2b:restore-datasheet-descriptions
                            {--apply : Zapisz zmiany (bez tej flagi tylko raport)}
                            {--manufacturer= : Tylko karty tego producenta}
                            {--show=15 : Ile przykładów wypisać}';

    protected $description = 'Przywraca kartom bez opisu tekst karty technicznej (zapisuje tylko z --apply)';

    public function handle(): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $apply = (bool) $this->option('apply');
        $show = max(0, (int) $this->option('show'));

        $restored = 0;
        $examples = [];

        $this->line('Czytam karty bez opisu…');
        Product::query()
            ->where(static fn ($q) => $q->whereNull('description')->orWhere('description', ''))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->whereIn('id', B2bProductLink::query()->select('product_id'))
            ->whereHas('documents', static fn ($q) => $q->whereIn('kind', [ProductDocument::KIND_DATASHEET, ProductDocument::KIND_MANUAL])->whereNotNull('text'))
            ->with(['documents' => static fn ($q) => $q->whereIn('kind', [ProductDocument::KIND_DATASHEET, ProductDocument::KIND_MANUAL])->whereNotNull('text')->orderBy('sort_order')->orderBy('id')])
            ->select(['id', 'sku', 'manufacturer', 'description'])
            ->chunkById(500, function ($chunk) use ($apply, $show, &$restored, &$examples): void {
                foreach ($chunk as $product) {
                    $description = self::fromDatasheet($product);
                    if ($description === null) {
                        continue;
                    }
                    $restored++;
                    if (count($examples) < $show) {
                        $examples[] = $product->sku.' ('.$product->manufacturer.')  '.mb_strlen($description).' znaków';
                    }
                    if ($apply) {
                        $product->forceFill(['description' => $description])->save();
                        B2bProductLink::query()->where('product_id', $product->id)->update(['description_hash' => sha1($description)]);
                    }
                }
            });

        $this->newLine();
        $this->line('Opisy do odtworzenia:    '.$restored);
        if ($examples !== []) {
            $this->newLine();
            $this->line('Przykłady:');
            foreach ($examples as $row) {
                $this->line('  '.$row);
            }
        }

        if ($restored === 0) {
            $this->newLine();
            $this->info('Nie ma czego odtwarzać.');

            return self::SUCCESS;
        }

        $this->newLine();
        if (! $apply) {
            $this->warn('Raport — nic nie zapisano. Żeby odtworzyć opisy: php artisan b2b:restore-datasheet-descriptions --apply');

            return self::SUCCESS;
        }

        $this->info('Odtworzono '.$restored.' opisów z tekstu karty technicznej.');

        return self::SUCCESS;
    }

    /** Opis w postaci, jaką zapisywała dawna synchronizacja; null, gdy tekst dokumentu po oczyszczeniu jest pusty. */
    private static function fromDatasheet(Product $product): ?string
    {
        foreach ($product->documents as $document) {
            $text = B2bDocumentText::forCard((string) $document->text);
            if ($text !== '') {
                return B2bCatalogSync::DATASHEET_MARK.$document->title.'):'."\n".$text;
            }
        }

        return null;
    }
}
