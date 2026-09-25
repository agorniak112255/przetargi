<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CardRedirect;
use App\Models\Product;
use App\Services\Catalog\CardRedirectStore;
use App\Services\ProductSizeMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Scala kartę-duplikat w kartę, która zostaje — np. kartę dystrybutora założoną obok karty producenta, bo
 * synchronizacja dystrybutora nie trafiła po SKU (23.09.2026: P4S założyło 2111.235, 9169.541, 9183.041 obok kart
 * UVEX z obciętym SKU). Przenoszenie to samo co przy łączeniu rozmiarów (ProductSizeMergeService::mergeDuplicate):
 * powiązania B2B (kolejna synchronizacja trafia już w kartę, która zostaje), sloty cen, tabelki ze sklepu, zdjęcia
 * i dokumenty bez powtórzeń, historia cen, identyfikatory, pozycje przetargów, zamienniki, akcesoria innych kart
 * wskazujące duplikat, PrestaShop, cenniki.
 *
 * Nazwa, opis i SKU karty, która zostaje, się nie zmieniają. --take-sku przejmuje SKU duplikatu (po jego usunięciu
 * kod jest wolny) — dla karty z obciętym SKU. Pusta kategoria karty, która zostaje, bierze kategorię duplikatu.
 * Odmowa, gdy producent jest inny albo duplikat ma dane, których przenoszenie nie obejmuje (wersje, ceny specjalne,
 * akcesoria). Domyślnie podgląd; --apply zapisuje kopię zapasową obu kart i scala. Kody duplikatu (powiązania B2B,
 * pozycje cenników z pliku) trafiają do mapy połączeń (card_redirects) jako decyzja bez propozycji i autora.
 */
final class MergeDuplicateProductsCommand extends Command
{
    /** tabela => [kolumna karty, opis] — to, co przenosi mergeDuplicate */
    private const MOVED = [
        'b2b_product_links' => ['product_id', 'powiązania B2B'],
        'product_source_prices' => ['product_id', 'sloty cen'],
        'product_shop_cards' => ['product_id', 'tabelki ze sklepu'],
        'product_images' => ['product_id', 'zdjęcia'],
        'product_documents' => ['product_id', 'dokumenty'],
        'product_price_history' => ['product_id', 'historia cen'],
        'product_identifiers' => ['product_id', 'identyfikatory'],
        'tender_items' => ['main_product_id', 'pozycje przetargów'],
        'presta_product_matches' => ['product_id', 'dopasowania PrestaShop'],
        // akcesoria innych kart wskazujące duplikat — potem wskazują kartę, która zostaje
        'product_accessories' => ['related_product_id', 'jako akcesorium innych kart'],
    ];

    /** tabela => opis — dane, których mergeDuplicate nie przenosi; kaskada skasowałaby je razem z duplikatem */
    private const BLOCKING = [
        'product_variants' => 'wersje',
        'product_special_prices' => 'ceny specjalne',
        'product_accessories' => 'akcesoria',
    ];

    protected $signature = 'products:merge-duplicate
                            {--pair=* : Para „zostaje:duplikat” (numery kart), można podać wiele razy}
                            {--take-sku : Karta, która zostaje, przejmuje SKU duplikatu}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--apply : Scal (bez tej flagi tylko podgląd)}';

    protected $description = 'Scala kartę-duplikat tego samego wyrobu w kartę, która zostaje (podgląd bez --apply)';

    public function handle(ProductSizeMergeService $merge, CardRedirectStore $redirects): int
    {
        $pairs = $this->pairs();
        if ($pairs === null) {
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $takeSku = (bool) $this->option('take-sku');

        $ready = [];
        foreach ($pairs as [$keepId, $dropId]) {
            $keep = Product::query()->find($keepId);
            $drop = Product::query()->find($dropId);
            if ($keep === null || $drop === null) {
                $this->error("{$keepId}:{$dropId} — brak karty #".($keep === null ? $keepId : $dropId));

                continue;
            }
            $this->line("#{$keep->id} [{$keep->sku}] ".mb_substr((string) $keep->name, 0, 60));
            $this->line("  ← #{$drop->id} [{$drop->sku}] ".mb_substr((string) $drop->name, 0, 60));
            $problem = $this->problem($keep, $drop);
            if ($problem !== null) {
                $this->error('   pominięte: '.$problem);

                continue;
            }
            foreach (self::MOVED as $table => [$column, $label]) {
                $count = DB::table($table)->where($column, $drop->id)->count();
                if ($count > 0) {
                    $this->line("   {$label}: {$count}");
                }
            }
            if ($takeSku) {
                $this->line("   SKU {$keep->sku} → {$drop->sku}");
            }
            if (trim((string) $keep->category) === '' && trim((string) $drop->category) !== '') {
                $this->line("   kategoria: {$drop->category}");
            }
            $ready[] = [$keep, $drop];
        }

        if (! $apply) {
            $this->info('Podgląd: '.count($ready).' par do scalenia. Zapis: --apply');

            return self::SUCCESS;
        }
        if ($ready === []) {
            $this->info('Nic do scalenia.');

            return self::SUCCESS;
        }

        $path = trim((string) $this->option('backup'));
        if ($path === '') {
            $path = storage_path('app/repair-backups/merge-duplicate-'.now()->format('Ymd-His').'.json');
        }
        try {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $backup = array_map(static fn (array $pair): array => [
                'keep' => $pair[0]->getAttributes(),
                'drop' => $pair[1]->getAttributes(),
            ], $ready);
            file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            $this->error('Kopia zapasowa nie powstała: '.$e->getMessage().' — nic nie scalono.');

            return self::FAILURE;
        }

        $done = 0;
        foreach ($ready as [$keep, $drop]) {
            $dropSku = (string) $drop->sku;
            $dropCategory = trim((string) $drop->category);
            try {
                // mapa połączeń przed scaleniem (powiązania i identyfikatory są jeszcze na duplikacie) — razem
                // ze scaleniem albo wcale
                DB::transaction(static function () use ($redirects, $merge, $keep, $drop): void {
                    $redirects->recordMerge($drop, $keep, CardRedirect::REASON_MERGE, null, null);
                    $merge->mergeDuplicate($keep, $drop);
                });
                $keep->refresh();
                if ($takeSku) {
                    $keep->sku = $dropSku;
                }
                if (trim((string) $keep->category) === '' && $dropCategory !== '') {
                    $keep->category = $dropCategory;
                }
                // zwykły save(): hak modelu przelicza indeks tekstowy i zleca reindeks wektora
                $keep->save();
                $done++;
            } catch (Throwable $e) {
                $this->error("#{$keep->id} ← #{$drop->id}: ".$e->getMessage());
            }
        }
        $this->info("Scalono {$done} par. Kopia zapasowa: {$path}");

        return $done === count($ready) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{0: int, 1: int}>|null
     */
    private function pairs(): ?array
    {
        $pairs = [];
        foreach ((array) $this->option('pair') as $raw) {
            if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', (string) $raw, $m) !== 1 || $m[1] === $m[2]) {
                $this->error("Zła para „{$raw}” — podaj numery dwóch różnych kart, np. --pair=23168:54892.");

                return null;
            }
            $pairs[] = [(int) $m[1], (int) $m[2]];
        }
        // karta w dwóch parach działałaby na stanie sprzed pierwszego scalenia (albo na karcie już usuniętej)
        $ids = array_merge(...$pairs);
        if (count($ids) !== count(array_unique($ids))) {
            $this->error('Każda karta może wystąpić tylko w jednej parze — scal kolejne duplikaty osobnym uruchomieniem.');

            return null;
        }
        if ($pairs === []) {
            $this->error('Podaj co najmniej jedną parę, np. --pair=23168:54892.');

            return null;
        }

        return $pairs;
    }

    private function problem(Product $keep, Product $drop): ?string
    {
        if (mb_strtolower(trim((string) $keep->manufacturer)) !== mb_strtolower(trim((string) $drop->manufacturer))) {
            return "inny producent ({$keep->manufacturer} / {$drop->manufacturer})";
        }
        foreach (self::BLOCKING as $table => $label) {
            $count = DB::table($table)->where('product_id', $drop->id)->count();
            if ($count > 0) {
                return "duplikat ma {$label} ({$count}) — scalenie ich nie przenosi";
            }
        }

        return null;
    }
}
