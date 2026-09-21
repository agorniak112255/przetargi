<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\ProductDocument;
use App\Support\PolishPdfMojibake;
use Illuminate\Console\Command;

/**
 * Poprawia polskie litery w tekstach plików zapisanych przy kartach (product_documents.text), odczytanych
 * z PDF-ów o złym kodowaniu czcionek: „Rêkawica antyprzeciêciowa” → „Rękawica antyprzecięciowa”
 * (App\Support\PolishPdfMojibake). Nowe pliki czyta już poprawnie PriceListPdfTextExtractor; to polecenie
 * dotyczy tekstów zapisanych wcześniej — 21.09.2026 17 kart katalogowych TK GLOVES z Tegro.
 *
 * Domyślnie polecenie **tylko liczy i wypisuje**. Zapisuje wyłącznie po jawnym `--apply`; wtedy zleca też
 * przeindeksowanie tych kart, bo tekst karty technicznej wchodzi do indeksu wyszukiwania (ProductEmbeddingIndexer).
 */
final class RepairPolishDocumentTextCommand extends Command
{
    protected $signature = 'documents:repair-polish-text
                            {--apply : Zapisz zmiany (bez tej flagi tylko raport)}
                            {--show=20 : Ile przykładów wypisać}';

    protected $description = 'Poprawia zepsute polskie litery w tekstach plików PDF przy kartach (zapisuje tylko z --apply)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $show = max(0, (int) $this->option('show'));
        $changed = 0;
        $products = [];
        $examples = [];

        ProductDocument::query()
            ->whereNotNull('text')
            ->select(['id', 'product_id', 'title', 'text'])
            ->chunkById(500, function ($chunk) use ($apply, $show, &$changed, &$products, &$examples): void {
                foreach ($chunk as $document) {
                    $old = (string) $document->text;
                    $new = PolishPdfMojibake::repair($old);
                    if ($new === $old) {
                        continue;
                    }
                    $changed++;
                    $products[(int) $document->product_id] = true;
                    if (count($examples) < $show) {
                        $examples[] = [
                            '#'.$document->id,
                            (string) $document->title,
                            mb_substr((string) preg_replace('/\s+/u', ' ', $old), 0, 60),
                            mb_substr((string) preg_replace('/\s+/u', ' ', $new), 0, 60),
                        ];
                    }
                    if ($apply) {
                        $document->forceFill(['text' => $new])->save();
                    }
                }
            });

        if ($examples !== []) {
            $this->table(['Dokument', 'Plik', 'Było', 'Będzie'], $examples);
        }
        $this->line('Teksty do poprawy: '.$changed.' (kart: '.count($products).')');

        if (! $apply) {
            $this->line($changed > 0 ? 'Nic nie zapisano. Uruchom z --apply, żeby poprawić.' : 'Nie ma czego poprawiać.');

            return self::SUCCESS;
        }

        foreach (array_keys($products) as $productId) {
            ReindexProductEmbeddingJob::dispatch($productId);
        }
        $this->info('Poprawiono '.$changed.' tekstów; przeindeksowanie zlecone dla '.count($products).' kart.');

        return self::SUCCESS;
    }
}
