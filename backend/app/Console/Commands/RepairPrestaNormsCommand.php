<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Console\Command;

/**
 * Naprawa kart z PrestaShop: import wpisywał cechy sklepu („1 sztuka”, „Silikon”, „Bagnetowe Secura”) do
 * `attributes.normy_en` i do kolumny `norms` (SECURA 3000 S56T0SM0, przetarg 1 poz. 13 — model czytał je jako
 * normy karty). Zostają wpisy z kodem normy i normy wymienione w opisie karty. Kolumnę `norms` zmieniamy tylko,
 * gdy powstała z tej listy albo jest pusta — import pisał ją wyłącznie do pustej kolumny, więc normy wpisane
 * inną drogą zostają. Cechy zostają w `features`. Domyślnie tylko raport; zapis z --apply.
 */
final class RepairPrestaNormsCommand extends Command
{
    protected $signature = 'products:repair-presta-norms
                            {--apply : Zapisz zmiany (bez tej opcji tylko raport)}
                            {--limit=0 : Maksymalna liczba zmienionych kart (0 = bez limitu)}';

    protected $description = 'Usuwa cechy PrestaShop z norm kart i dopisuje normy wymienione w opisie';

    public function handle(BhpAttributeNormalizer $normalizer): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $checked = 0;
        $changed = 0;
        $samples = [];

        Product::query()
            ->whereNotNull('enrichment_payload')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($normalizer, $apply, $limit, &$checked, &$changed, &$samples): bool {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                    if (empty($payload['from_presta'])) {
                        continue;
                    }
                    $checked++;
                    $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                    $old = array_values(array_map(
                        static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                        is_array($attrs['normy_en'] ?? null) ? $attrs['normy_en'] : []
                    ));
                    $new = array_values(array_unique([
                        ...$normalizer->normEntries($old),
                        ...$normalizer->detectNormsFromText((string) ($product->description ?? '')),
                    ]));
                    $oldColumn = trim((string) ($product->norms ?? ''));
                    $columnFromList = $old !== [] && $oldColumn === implode(', ', $old);
                    $newColumn = ($columnFromList || $oldColumn === '') ? implode(', ', $new) : $oldColumn;
                    if ($new === $old && $newColumn === $oldColumn) {
                        continue;
                    }
                    if ($limit > 0 && $changed >= $limit) {
                        return false;
                    }
                    $changed++;
                    if (count($samples) < 15) {
                        $samples[] = [
                            (string) $product->sku,
                            mb_substr(implode(', ', $old), 0, 60),
                            mb_substr(implode(', ', $new), 0, 60),
                            $newColumn === $oldColumn ? 'bez zmian' : 'zmieniona',
                        ];
                    }
                    if (! $apply) {
                        continue;
                    }
                    $attrs['normy_en'] = $new;
                    $payload['attributes'] = $attrs;
                    $product->enrichment_payload = $payload;
                    $product->norms = $newColumn !== '' ? $newColumn : null;
                    $product->save();
                }

                return true;
            });

        if ($samples !== []) {
            $this->table(['SKU', 'normy przed', 'normy po', 'kolumna norms'], $samples);
        }
        $this->info(
            ($apply ? 'Poprawiono' : 'Do poprawy').": {$changed} z {$checked} kart z PrestaShop."
            .($apply ? '' : ' Zapis: dodaj --apply.')
        );

        return self::SUCCESS;
    }
}
