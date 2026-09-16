<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\ProductEnrichmentResetter;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Ponowne wzbogacenie wskazanych kart: po kodach (lista z ręcznych testów), po producencie albo
 * po cenniku. Po poprawce w doborze źródeł trzeba przejść jeszcze raz dokładnie ten sam zbiór
 * i porównać wynik. `products:queue-enrichment` bierze tylko karty bez opisu, a
 * `products:reset-foreign-descriptions` tylko te, które audyt sam rozpozna — żadne z nich nie
 * umie „zrób jeszcze raz te 43 kody” ani „cały cennik od nowa”.
 *
 * Kasowanie opisu przechodzi przez ProductEnrichmentResetter, więc razem z opisem znikają normy,
 * payload, cache SKU, zdjęcia, dokumenty i akcesoria pobrane z sieci, a także ręcznie wskazany
 * adres sklepu (`shop_source_url`) — przy złym przypiętym adresie to jest cel, przy dobrym trzeba
 * go wpisać ponownie. Przed pierwszą zmianą powstaje kopia zapasowa; `--restore` ją przywraca.
 */
final class RecheckProductSkusCommand extends Command
{
    protected $signature = 'products:recheck-skus
                            {--sku=* : Kod produktu; można podać wiele razy}
                            {--file= : Plik z kodami, po jednym w wierszu (# to komentarz)}
                            {--manufacturer= : Karty tego producenta — zawęża listę kodów albo bierze wszystkie jego karty}
                            {--price-list= : Numer cennika — wszystkie karty z tego importu}
                            {--limit=30 : Ile wierszy pokazać w podglądzie (0 = wszystkie)}
                            {--user= : E-mail użytkownika, na którego idą partie (domyślnie pierwszy administrator)}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć karty z kopii zapasowej i zakończ}
                            {--apply : Wykonaj (bez tej flagi tylko podgląd)}';

    protected $description = 'Kasuje opis wskazanych kodów i zleca pobranie go od nowa (podgląd bez --apply)';

    public function handle(
        ProductEnrichmentService $enrichment,
        ProductEnrichmentResetter $resetter,
        AiSettingsService $settings,
    ): int {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            $result = $resetter->restore($restore);
            if (is_string($result)) {
                $this->error('Nie przywrócono: '.$result);

                return self::FAILURE;
            }
            $this->info("Przywrócono {$result} kart z kopii {$restore}.");

            return self::SUCCESS;
        }

        $skus = $this->collectSkus();
        $manufacturer = trim((string) $this->option('manufacturer'));
        $priceListId = (int) $this->option('price-list');
        if ($skus === [] && $manufacturer === '' && $priceListId <= 0) {
            $this->error('Podaj kody (--sku=KOD, --file=lista.txt) albo cały zbiór (--manufacturer=, --price-list=).');

            return self::FAILURE;
        }

        $listIds = [];
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika numer {$priceListId}.");

                return self::FAILURE;
            }
            $listIds = array_values(array_unique(array_map('intval', $priceList->product_ids ?? [])));
            if ($listIds === []) {
                $this->error('Ten cennik nie ma zapisanych produktów (stary import) — użyj --manufacturer=.');

                return self::FAILURE;
            }
        }

        $products = Product::query()
            ->when($skus !== [], static fn ($q) => $q->whereIn('sku', $skus))
            ->when($listIds !== [], static fn ($q) => $q->whereIn('id', $listIds))
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->orderBy('id')
            ->get();

        if ($skus !== []) {
            $found = $products->map(static fn (Product $p): string => mb_strtolower(trim((string) $p->sku)))->all();
            $missing = array_values(array_filter(
                $skus,
                static fn (string $sku): bool => ! in_array(mb_strtolower($sku), $found, true)
            ));
            if ($missing !== []) {
                $this->warn('Nie ma w katalogu: '.implode(', ', $missing));
            }
        }
        if ($products->isEmpty()) {
            $this->error('Ten wybór nie ma żadnej karty w katalogu.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $shown = $limit > 0 ? $products->take($limit) : $products;
        $this->table(
            ['ID', 'SKU', 'Producent', 'Status', 'Opis (znaki)', 'Przypięty adres'],
            $shown->map(static fn (Product $p): array => [
                (int) $p->id,
                (string) $p->sku,
                (string) $p->manufacturer,
                (string) $p->enrichment_status,
                mb_strlen((string) $p->description),
                $p->shop_source_url !== null ? 'tak' : '',
            ])->all(),
        );
        if ($limit > 0 && $products->count() > $limit) {
            $this->line(sprintf('… i jeszcze %d kart (podgląd skrócony, --limit=0 pokaże wszystkie).', $products->count() - $limit));
        }

        $count = $products->count();
        $batchSize = $settings->enrichmentBatchLimit();
        if (! $this->option('apply')) {
            $this->info("Do ponownego wzbogacenia: {$count} kart, partiami po {$batchSize} (limit z Ustawień AI).");
            $this->line('Podgląd — uruchom z --apply, żeby skasować opisy i zlecić pobranie od nowa.');

            return self::SUCCESS;
        }

        $user = $this->resolveUser();
        if (! $user instanceof User) {
            return self::FAILURE;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/recheck-skus-'.now()->format('Ymd-His').'.json');
        }
        $error = $resetter->writeBackup($backup, 'recheck-skus', $products->all());
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }
        foreach ($products as $product) {
            $resetter->reset($product);
        }
        $this->info("Wyczyszczono {$count} kart. Kopia zapasowa: {$backup}");
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$backup}\"");

        $queued = 0;
        $batchIds = [];
        foreach (array_chunk($products->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $batchSize) as $chunk) {
            try {
                $result = $enrichment->enqueueProductIds($chunk, $user);
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage());

                continue;
            }
            $queued += count($result['product_ids']);
            $batchIds[] = (int) $result['batch']->id;
        }
        if ($batchIds === []) {
            $this->error('Nie udało się zlecić żadnej partii — karty zostały wyczyszczone, zleć ręcznie z panelu.');

            return self::FAILURE;
        }
        $this->info(sprintf('Zlecono pobranie opisu: %d kart w %d partiach (#%s).', $queued, count($batchIds), implode(', #', $batchIds)));

        return self::SUCCESS;
    }

    /**
     * Kody z --sku i z pliku, bez pustych i bez powtórzeń. Plik przyjmuje też wiersze
     * z przecinkami i średnikami, bo listy do sprawdzenia wklejane są z arkusza.
     *
     * @return list<string>
     */
    private function collectSkus(): array
    {
        $raw = [];
        foreach ((array) $this->option('sku') as $sku) {
            $raw[] = (string) $sku;
        }
        $file = trim((string) $this->option('file'));
        if ($file !== '') {
            if (! is_file($file)) {
                $this->error("Nie ma pliku {$file}.");

                return [];
            }
            foreach (preg_split('/\R/u', (string) file_get_contents($file)) ?: [] as $line) {
                // komentarz po kodzie („SB04 AIR  # spodniobuty”) — kod bywa ze spacją,
                // więc wiersza nie dzielimy po spacjach, tylko ucinamy od pierwszego #
                $line = trim((string) preg_replace('/#.*$/u', '', (string) $line));
                if ($line === '') {
                    continue;
                }
                foreach (preg_split('/[;,\t]+/u', $line) ?: [] as $part) {
                    $raw[] = (string) $part;
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

    private function resolveUser(): ?User
    {
        $email = trim((string) $this->option('user'));
        try {
            $user = $email !== ''
                ? User::query()->where('email', $email)->first()
                : User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $user = null;
        }
        if (! $user instanceof User) {
            $this->error($email !== '' ? "Nie ma użytkownika {$email}." : 'Nie ma administratora, na którego można zlecić partie — podaj --user=.');
        }

        return $user instanceof User ? $user : null;
    }
}
