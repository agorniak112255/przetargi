<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\PrestaCategory;
use App\Models\PrestaCategoryMap;
use App\Models\Product;
use App\Services\Presta\ProductCategorySanitizer;
use Illuminate\Console\Command;

/**
 * Pochodzenie kategorii na kartach sprzed kolumny category_source (null = nieznane). Kategoria sklepu nadana
 * automatem nie jest dowodem rodzaju wyrobu (decyzja użytkownika 22.09.2026), ale z samej bazy nie widać, kto
 * ją nadał — przebiegi presta:rewrite-categories nie zostawiały śladu. Rozstrzygamy tylko to, co da się
 * udowodnić, reszta zostaje null:
 *
 * - manual: dziennik aktywności ma ręczną zmianę kategorii w panelu (PATCH products/{id}/category) i karta
 *   wciąż ma dokładnie tę wartość — dziennik trzyma 120 dni, starsze wybory ręczne tu nie wyjdą;
 * - presta_rewrite: kategoria to dosłownie ścieżka drzewa Presty albo etykieta rodziny z automatu
 *   („Rękawice”, „Obuwie”) — tak zapisuje przepisanie, import i uzupełnianie opisu. Ścieżkę wybraną ręcznie
 *   ponad 120 dni temu też tu zaliczy — dlatego najpierw podgląd.
 *
 * Nie oznaczamy jako automatu (decyzja użytkownika 22.09.2026 — zostają null, czyli dowód jak dotąd):
 * - ścieżki, na które wskazuje mapa kategorii (presta_category_maps): mapę układa człowiek, to tłumaczenie
 *   kategorii z cennika na drzewo sklepu, a nie zgadywanie;
 * - etykiety rodziny, której nazwa karty nie wskazuje: automat bierze etykietę z nazwy, więc „Rękawice” przy
 *   nazwie bez słowa o rękawicach przyszła z kolumny kategorii cennika.
 *
 * Domyślnie tylko podgląd. Zapis nie przelicza rodziny PPE — po --apply: products:rebuild-search-index.
 */
final class MarkCategoryProvenanceCommand extends Command
{
    protected $signature = 'products:category-provenance
                            {--manufacturer= : Tylko karty tego producenta (domyślnie cały katalog)}
                            {--apply : Zapisz pochodzenie (bez tej flagi tylko podgląd)}
                            {--samples=25 : Ile grup kategorii pokazać}';

    protected $description = 'Oznacza pochodzenie kategorii (category_source) na starych kartach: ręczne z dziennika, automatyczne ze ścieżek drzewa Presty';

    public function handle(): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $apply = (bool) $this->option('apply');
        $samples = max(0, (int) $this->option('samples'));

        $treePaths = [];
        foreach (PrestaCategory::query()->get(['name', 'path']) as $cat) {
            $path = trim((string) ($cat->path !== '' && $cat->path !== null ? $cat->path : $cat->name));
            if ($path !== '') {
                $treePaths[mb_strtolower($path)] = true;
            }
        }
        $labels = [];
        foreach (ProductCategorySanitizer::FAMILY_LABELS as $label) {
            $labels[mb_strtolower($label)] = true;
        }
        $mapPaths = $this->mapTargetPaths();
        $manual = $this->manualChoices();
        $sanitizer = app(ProductCategorySanitizer::class);

        /** @var array<string, list<int>> $ids */
        $ids = [Product::CATEGORY_SOURCE_MANUAL => [], Product::CATEGORY_SOURCE_PRESTA_REWRITE => []];
        $unknown = 0;
        /** @var array<string, array{0: string, 1: string, 2: string, 3: int}> $groups */
        $groups = [];

        Product::query()
            ->select(['id', 'manufacturer', 'name', 'category', 'category_source'])
            ->whereNull('category_source')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->orderBy('id')
            ->chunkById(1000, function ($products) use ($treePaths, $labels, $mapPaths, $manual, $sanitizer, &$ids, &$unknown, &$groups): void {
                foreach ($products as $product) {
                    $category = trim((string) $product->category);
                    $key = mb_strtolower($category);
                    if (($manual[(int) $product->id] ?? null) === $category) {
                        $decision = Product::CATEGORY_SOURCE_MANUAL;
                    } elseif ((isset($treePaths[$key]) && ! isset($mapPaths[$key]))
                        || (isset($labels[$key])
                            && mb_strtolower((string) $sanitizer->inferLabel((string) $product->name)) === $key)) {
                        $decision = Product::CATEGORY_SOURCE_PRESTA_REWRITE;
                    } else {
                        $unknown++;

                        continue;
                    }
                    $ids[$decision][] = (int) $product->id;
                    $groupKey = $decision.'|'.(string) $product->manufacturer.'|'.$category;
                    $groups[$groupKey] ??= [(string) $product->manufacturer, $category, $decision, 0];
                    $groups[$groupKey][3]++;
                }
            });

        if ($samples > 0 && $groups !== []) {
            $rows = array_values($groups);
            usort($rows, static fn (array $a, array $b): int => $b[3] <=> $a[3]);
            $this->table(
                ['Producent', 'Kategoria', 'Pochodzenie', 'Kart'],
                array_map(
                    static fn (array $r): array => [$r[0], mb_substr($r[1], 0, 90), $r[2], $r[3]],
                    array_slice($rows, 0, $samples),
                ),
            );
        }

        $this->info(sprintf(
            '%s: ręczne (dziennik) %d, nadane automatem %d, nieznane — bez zmian %d.',
            $manufacturer !== '' ? $manufacturer : 'Cały katalog',
            count($ids[Product::CATEGORY_SOURCE_MANUAL]),
            count($ids[Product::CATEGORY_SOURCE_PRESTA_REWRITE]),
            $unknown,
        ));
        // Kategorii ze źródła sprzed przepisania na drzewo nie da się odtworzyć z bazy — wraca dopiero z samego źródła.
        $this->line('Karty z kategorią już nadpisaną ścieżką drzewa odzyskają kategorię-dowód (category_evidence) po '
            .'ponownej synchronizacji B2B albo ponownym imporcie cennika.');

        if (! $apply) {
            $this->warn('Podgląd — nic nie zapisano. Zapis: --apply, potem php artisan products:rebuild-search-index.');

            return self::SUCCESS;
        }

        foreach ($ids as $source => $list) {
            foreach (array_chunk($list, 1000) as $chunk) {
                // tylko karty wciąż bez pochodzenia — wybór zapisany w międzyczasie (panel, import) wygrywa
                Product::query()
                    ->whereIn('id', $chunk)
                    ->whereNull('category_source')
                    ->update(['category_source' => $source]);
            }
        }
        $this->info('Zapisano. Rodzina PPE liczy się teraz bez kategorii automatu — przelicz: php artisan products:rebuild-search-index');

        return self::SUCCESS;
    }

    /**
     * Ścieżki drzewa, na które wskazuje mapa „kategoria lokalna → Presta” z innej nazwy lokalnej (małe litery).
     * Wpis mapy „ścieżka → ta sama ścieżka” (dopisywany przy przepisaniu) nic nie mówi o pochodzeniu.
     *
     * @return array<string, true>
     */
    private function mapTargetPaths(): array
    {
        $pathById = [];
        foreach (PrestaCategory::query()->get(['presta_id', 'name', 'path']) as $cat) {
            $path = trim((string) ($cat->path !== '' && $cat->path !== null ? $cat->path : $cat->name));
            if ($path !== '') {
                $pathById[(int) $cat->presta_id] = mb_strtolower($path);
            }
        }
        $out = [];
        foreach (PrestaCategoryMap::query()->whereNotNull('presta_id')->where('presta_id', '>', 0)->get() as $map) {
            $path = $pathById[(int) $map->presta_id] ?? null;
            if ($path !== null && mb_strtolower(trim((string) $map->local_category)) !== $path) {
                $out[$path] = true;
            }
        }

        return $out;
    }

    /**
     * Ostatnia wartość wybrana ręcznie w panelu dla każdej karty (dziennik aktywności, 120 dni).
     *
     * @return array<int, string>
     */
    private function manualChoices(): array
    {
        $out = [];
        ActivityLog::query()
            ->where('subject_type', Product::class)
            ->where('meta', 'like', '%category%')
            ->orderBy('id')
            ->chunkById(1000, function ($logs) use (&$out): void {
                foreach ($logs as $log) {
                    $meta = is_array($log->meta) ? $log->meta : [];
                    if (strtoupper((string) ($meta['method'] ?? '')) !== 'PATCH'
                        || preg_match('#^products/\d+/category$#', (string) ($meta['path'] ?? '')) !== 1) {
                        continue;
                    }
                    $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : [];
                    $out[(int) $log->subject_id] = trim((string) ($payload['category'] ?? ''));
                }
            });

        return $out;
    }
}
