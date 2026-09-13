<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Support\ProductDescriptionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Karty z opisem obcej strony (raport `products:audit-descriptions --only=unrelated`) i ze
 * zrzutem strony zamiast opisu (`--only=page_dump`)
 * wracają do kolejki wzbogacania: opis, normy, payload, źródło i cache SKU znikają,
 * status = none. Domyślnie tylko podgląd — zapis wymaga --apply.
 */
final class ResetForeignDescriptionsCommand extends Command
{
    protected $signature = 'products:reset-foreign-descriptions
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--limit=0 : Maksymalna liczba wierszy w tabeli podglądu (0 = wszystkie)}';

    protected $description = 'Czyści opisy przypisane do niewłaściwego produktu i ustawia karty do ponownego wzbogacenia';

    public function handle(ProductDescriptionAudit $audit): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $findings = [];

        Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($audit, $apply, &$findings): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $finding = $audit->inspect($product);
                    // obcy opis i zrzut strony czyścimy automatycznie; rozjazd rodziny/kroju wymaga oka człowieka
                    if ($finding === null || ! in_array($finding['reason'], [
                        ProductDescriptionAudit::REASON_UNRELATED,
                        ProductDescriptionAudit::REASON_PAGE_DUMP,
                    ], true)) {
                        continue;
                    }
                    $findings[] = $finding;
                    if ($apply) {
                        $this->reset($product);
                    }
                }
            });

        if ($findings === []) {
            $this->info('Brak kart z obcym opisem.');

            return self::SUCCESS;
        }

        $rows = $limit > 0 ? array_slice($findings, 0, $limit) : $findings;
        $this->table(
            ['ID', 'SKU', 'Nazwa'],
            array_map(static fn (array $f): array => [$f['id'], $f['sku'], $f['name']], $rows),
        );
        $count = count($findings);
        $this->info($apply
            ? "Wyczyszczono {$count} kart — wrócą do kolejki wzbogacania (status none)."
            : "Do wyczyszczenia: {$count} kart. Uruchom z --apply, żeby skasować obce opisy.");

        return self::SUCCESS;
    }

    private function reset(Product $product): void
    {
        // cache SKU→karta też wskazywał obcą stronę; bez tego enrichment wróciłby do tego samego opisu
        $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
        ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->delete();

        $product->update([
            'description' => null,
            'norms' => null,
            'enrichment_payload' => null,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            'enriched_at' => null,
            'enrichment_error' => null,
            'enrichment_trace' => null,
            'shop_source_url' => null,
        ]);
    }
}
