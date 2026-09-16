<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerDomainResolver;
use Illuminate\Console\Command;

/**
 * Poprawa marki na wskazanych kartach. Cennik dystrybutora bywa podpisany jego nazwą, choć
 * wyrób robi kto inny: „Kombinezon AlphaTec 2000” z cennika SECURA to wyrób Ansella. Dopóki
 * karta ma złą markę, wzbogacanie szuka jej na stronie złego producenta i nie ma szans trafić.
 *
 * Które karty poprawić, decyduje człowiek — polecenie niczego nie zgaduje. Zmienia wyłącznie
 * pole `manufacturer`; opisu, zdjęć ani dokumentów nie rusza (do ponownego pobrania służy
 * `products:recheck-skus`, które trzeba uruchomić osobno).
 *
 * UWAGA na wyrób cudzej marki sprzedawany przez dystrybutora (AlphaTec w cenniku SECURA,
 * Tyvek u Canisa): TAM MARKI ZWYKLE NIE ZMIENIAMY. Import cennika pomija wiersz, którego kod
 * należy już do karty innego producenta, więc po zmianie marki przestałyby się aktualizować
 * ceny. Wzbogacanie radzi sobie z tym inaczej — rozpoznaje markę towaru z nazwy
 * (`ProductSearchIdentity::goodsBrandKeys`). To polecenie jest do kart, które mają markę
 * po prostu błędną, a nie do towaru dystrybuowanego.
 */
final class SetProductManufacturerCommand extends Command
{
    protected $signature = 'products:set-manufacturer
                            {--sku=* : Kod produktu; można podać wiele razy}
                            {--file= : Plik z kodami, po jednym w wierszu (# to komentarz)}
                            {--to= : Nowa marka, np. Ansell}
                            {--from= : Bezpiecznik — ruszaj tylko karty, które mają dziś tę markę}
                            {--apply : Zapisz zmianę (bez tej flagi tylko podgląd)}';

    protected $description = 'Ustawia markę na wskazanych kartach (podgląd bez --apply)';

    public function handle(ManufacturerDomainResolver $manufacturers): int
    {
        $to = trim((string) $this->option('to'));
        if ($to === '') {
            $this->error('Podaj nową markę: --to=Ansell.');

            return self::FAILURE;
        }
        $skus = $this->collectSkus();
        if ($skus === []) {
            $this->error('Podaj kody: --sku=KOD (można wiele razy) albo --file=lista.txt.');

            return self::FAILURE;
        }

        $from = trim((string) $this->option('from'));
        $products = Product::query()
            ->whereIn('sku', $skus)
            ->when($from !== '', static fn ($q) => $q->where('manufacturer', $from))
            ->orderBy('id')
            ->get();
        if ($products->isEmpty()) {
            $this->error('Żaden z podanych kodów nie ma karty'.($from !== '' ? " z marką {$from}." : '.'));

            return self::FAILURE;
        }
        $changing = $products->filter(static fn (Product $p): bool => trim((string) $p->manufacturer) !== $to);

        $this->table(
            ['SKU', 'Nazwa', 'Marka teraz', 'Po zmianie'],
            $products->map(static fn (Product $p): array => [
                (string) $p->sku,
                mb_substr((string) $p->name, 0, 60),
                (string) $p->manufacturer,
                trim((string) $p->manufacturer) === $to ? '(bez zmian)' : $to,
            ])->all(),
        );

        $probe = new Product(['manufacturer' => $to, 'sku' => 'X', 'name' => 'X']);
        if ($manufacturers->domainsFor($probe) === []) {
            $this->warn("Marka {$to} nie ma znanych domen producenta w konfiguracji — wzbogacanie nie będzie"
                .' wiedziało, gdzie szukać jej kart. Rozważ dopisanie jej do config/enrichment.php.');
        }

        if ($changing->isEmpty()) {
            $this->info("Wszystkie wskazane karty mają już markę {$to} — nie ma czego zmieniać.");

            return self::SUCCESS;
        }
        $this->warn('Po zmianie marki import cennika pomija te kody („kod należy do karty producenta …”),'
            .' więc ceny przestaną się aktualizować. Dla wyrobu cudzej marki sprzedawanego przez'
            .' dystrybutora zostaw markę z cennika — wzbogacanie rozpozna markę towaru z nazwy.');
        if (! $this->option('apply')) {
            $this->info('Do zmiany: '.$changing->count().' kart. Uruchom z --apply, żeby zapisać.');

            return self::SUCCESS;
        }

        foreach ($changing as $product) {
            $product->manufacturer = $to;
            $product->save();
        }
        $this->info('Zmieniono markę na '.$to.' dla '.$changing->count().' kart.');
        $this->line('Teraz warto pobrać ich opisy od nowa:');
        $this->line('  products:recheck-skus '.implode(' ', $changing->map(
            static fn (Product $p): string => '--sku="'.$p->sku.'"'
        )->all()).' --apply');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collectSkus(): array
    {
        $raw = array_map(static fn ($sku): string => (string) $sku, (array) $this->option('sku'));
        $file = trim((string) $this->option('file'));
        if ($file !== '') {
            if (! is_file($file)) {
                $this->error("Nie ma pliku {$file}.");

                return [];
            }
            foreach (preg_split('/\R/u', (string) file_get_contents($file)) ?: [] as $line) {
                $line = trim((string) preg_replace('/#.*$/u', '', (string) $line));
                if ($line !== '') {
                    $raw[] = $line;
                }
            }
        }
        $out = [];
        foreach ($raw as $sku) {
            $sku = trim($sku);
            if ($sku !== '' && ! in_array($sku, $out, true)) {
                $out[] = $sku;
            }
        }

        return $out;
    }
}
