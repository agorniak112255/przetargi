<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bCatalogSync;
use Illuminate\Console\Command;

/**
 * Odcina z opisów kart sekcję „Z karty technicznej (…)” — dosłowny tekst PDF-a, który synchronizacja B2B
 * doklejała do opisu do 20.09.2026 (B2bCatalogSync).
 *
 * Powód: testujący zgłosił, że opisy brzmią jak tłumaczenie ulotki — „Delikatny powietrzny dotyk”,
 * „supporting HAPPINESS” — i mają połamane wyrazy („cholewk a”), bo tak wychodzą z PDF-a. Tekst karty
 * technicznej zostaje przy karcie jako dokument (product_documents.text) i od tej samej zmiany wchodzi do
 * indeksu wyszukiwania (ProductEmbeddingIndexer), więc nic nie ginie — opis wraca do samej prozy.
 *
 * Domyślnie polecenie **tylko liczy i wypisuje**. Zapisuje wyłącznie po jawnym `--apply`.
 *
 * Karty, którym po odcięciu nie zostałby żaden tekst, są pomijane i wypisane osobno: opis to warunek wejścia
 * do propozycji przetargowych, więc pusty opis wyrzuciłby wyrób z ofert. Takiej karcie trzeba opis napisać
 * (wzbogacanie), a nie skasować.
 *
 * Powiązaniu B2B, które nosiło odcisk starego opisu, wpisujemy odcisk nowego — bez tego karta wyglądałaby jak
 * zmieniona ręcznie i synchronizacja nigdy więcej nie odświeżyłaby jej opisu (B2bCatalogSync::mayWriteDescription).
 */
final class B2bStripDatasheetDescriptionsCommand extends Command
{
    /** Nagłówek sekcji doklejanej kiedyś przez synchronizację; ten sam ciąg zna b2b:relink-descriptions. */
    private const MARK = B2bCatalogSync::DATASHEET_MARK;

    protected $signature = 'b2b:strip-datasheet-descriptions
                            {--apply : Zapisz zmiany (bez tej flagi tylko raport)}
                            {--manufacturer= : Tylko karty tego producenta}
                            {--show=15 : Ile przykładów wypisać}';

    protected $description = 'Usuwa z opisów kart dosłowny tekst karty technicznej (kasuje tylko z --apply)';

    public function handle(): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $apply = (bool) $this->option('apply');
        $show = max(0, (int) $this->option('show'));

        $trimmed = 0;
        $skipped = [];
        $examples = [];

        $this->line('Czytam opisy…');
        Product::query()
            ->where('description', 'like', '%'.self::MARK.'%')
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->select(['id', 'sku', 'manufacturer', 'description'])
            ->chunkById(500, function ($chunk) use ($apply, $show, &$trimmed, &$skipped, &$examples): void {
                foreach ($chunk as $product) {
                    $old = (string) $product->description;
                    $new = self::withoutDatasheet($old);
                    if ($new === $old) {
                        continue;
                    }
                    if ($new === '') {
                        $skipped[] = $product->sku.' ('.$product->manufacturer.')';

                        continue;
                    }
                    $trimmed++;
                    if (count($examples) < $show) {
                        $examples[] = ['sku' => (string) $product->sku, 'was' => mb_strlen($old), 'is' => mb_strlen($new)];
                    }
                    if ($apply) {
                        $this->save($product, $old, $new);
                    }
                }
            });

        $this->newLine();
        $this->line('Opisy do skrócenia:      '.$trimmed);
        $this->line('Pominięte (byłby pusty): '.count($skipped));

        if ($examples !== []) {
            $this->newLine();
            $this->line('Przykłady:');
            foreach ($examples as $row) {
                $this->line('  '.$row['sku'].'  '.$row['was'].' → '.$row['is'].' znaków');
            }
        }
        if ($skipped !== [] && $show > 0) {
            $this->newLine();
            $this->line('Karty, które straciłyby cały opis — zostają bez zmian, opis trzeba im napisać:');
            foreach (array_slice($skipped, 0, $show) as $sku) {
                $this->line('  '.$sku);
            }
            if (count($skipped) > $show) {
                $this->line('  … i '.(count($skipped) - $show).' więcej');
            }
        }

        if ($trimmed === 0) {
            $this->newLine();
            $this->info('Nie ma czego skracać.');

            return self::SUCCESS;
        }

        $this->newLine();
        if (! $apply) {
            $this->warn('Raport — nic nie zapisano. Żeby skrócić opisy: php artisan b2b:strip-datasheet-descriptions --apply');

            return self::SUCCESS;
        }

        $this->info('Skrócono '.$trimmed.' opisów. Karty wróciły do kolejki embeddingów, '
            .'a tekst karty technicznej został przy dokumentach wyrobu.');

        return self::SUCCESS;
    }

    /** Opis bez sekcji karty technicznej: wszystko od jej nagłówka w dół. */
    public static function withoutDatasheet(string $description): string
    {
        $at = mb_strpos($description, self::MARK);

        return $at === false ? $description : trim(mb_substr($description, 0, $at));
    }

    /**
     * Zapis opisu razem z odciskiem w powiązaniach, które nosiły odcisk starego tekstu. Powiązania z innym
     * odciskiem zostają nietknięte — tam opis zmienił się poza synchronizacją i nie nam to prostować.
     */
    private function save(Product $product, string $old, string $new): void
    {
        $product->forceFill(['description' => $new])->save();

        B2bProductLink::query()
            ->where('product_id', $product->id)
            ->where('description_hash', sha1($old))
            ->update(['description_hash' => sha1($new)]);
    }
}
