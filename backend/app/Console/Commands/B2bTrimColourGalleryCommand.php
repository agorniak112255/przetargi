<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\ColourGalleryTrim;
use Illuminate\Console\Command;

/**
 * Jedno zdjęcie na kolor na kartach modeli scalonych przed tą zasadą (decyzja właściciela 28.09.2026; MAVIBO i JHK
 * scalone tego samego dnia z pełnymi galeriami). Które zdjęcie było główne na której karcie koloru, czyta z kopii
 * scalenia konta (storage/app/repair-backups/size-prices-{konto}-*.jsonl: wiersz „before” z galeriami kart i
 * „committed” po zatwierdzeniu) — po scaleniu ta wiedza jest tylko tam. Tylko wyroby z listą „Kolory: …” i tylko
 * zdjęcia z tych galerii, które nadal są na karcie modelu. Domyślnie podgląd; --apply usuwa przez odrzucenie
 * (galeria dostawcy ich nie dołoży; plik zostaje na dysku, wiersz w kopii).
 */
final class B2bTrimColourGalleryCommand extends Command
{
    protected $signature = 'b2b:trim-colour-gallery
        {account : ID konta B2B (widoczne na karcie w Cenniki → B2B)}
        {--apply : Usuń nadmiar zdjęć (bez tej flagi tylko podgląd)}
        {--details : Każda karta osobno (domyślnie pierwsze wiersze i podsumowanie)}';

    protected $description = 'Zostawia jedno zdjęcie na kolor na kartach modeli scalonych z kart kolorów (podgląd bez --apply)';

    private const PREVIEW_LINES = 30;

    public function handle(ColourGalleryTrim $trim): int
    {
        B2bAccountSyncRunner::raiseMemoryLimit();
        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o ID '.$this->argument('account').'.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $files = glob(storage_path('app/repair-backups').DIRECTORY_SEPARATOR.'size-prices-'.$account->id.'-*.jsonl') ?: [];
        sort($files);
        $this->line(sprintf('Konto #%d · kopii scalania: %d · %s', $account->id, count($files), $apply ? 'USUWANIE' : 'podgląd, nic nie zapisuje'));

        // karta modelu => galerie kart sprzed scalenia (z kolejnych scaleń tej samej karty — suma)
        $galleries = [];
        foreach ($files as $file) {
            foreach ($this->committedColourMerges($file) as $keepId => $imagesByCard) {
                foreach ($imagesByCard as $cardId => $rows) {
                    $galleries[$keepId][$cardId] = $rows;
                }
            }
        }

        $lines = [];
        $cards = 0;
        $surplusTotal = 0;
        $removed = 0;
        foreach ($galleries as $keepId => $imagesByCard) {
            $surplus = $trim->surplus($imagesByCard, (int) $keepId);
            if ($surplus === []) {
                continue;
            }
            $cards++;
            $surplusTotal += count($surplus);
            $product = Product::query()->find((int) $keepId, ['id', 'sku', 'name']);
            $lines[] = sprintf('#%d %s · kolorów %d · zdjęć do usunięcia %d', $keepId, $product?->name ?? '?', count($imagesByCard), count($surplus));
            if ($apply) {
                $removed += $trim->remove($surplus);
            }
        }

        foreach ($this->option('details') ? $lines : array_slice($lines, 0, self::PREVIEW_LINES) as $line) {
            $this->line('  '.$line);
        }
        $this->line(sprintf('Kart modeli: %d · zdjęć ponad jedno na kolor: %d%s', $cards, $surplusTotal, $apply ? ' · usunięte: '.$removed : ''));
        if (! $apply && $surplusTotal > 0) {
            $this->line('Podgląd — nic nie zmieniono. Uruchom z --apply, żeby usunąć.');
        }

        return self::SUCCESS;
    }

    /**
     * Zatwierdzone scalenia kolorów z jednej kopii: karta modelu => [karta sprzed scalenia => wiersze product_images].
     *
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function committedColourMerges(string $file): array
    {
        $before = [];
        $committed = [];
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            $this->warn('Nie można odczytać kopii '.$file.'.');

            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            $sku = (string) ($row['sku'] ?? '');
            if (($row['status'] ?? null) === 'before' && ColourGalleryTrim::isColourGroup(is_array($row['group'] ?? null) ? $row['group'] : [])) {
                $images = [];
                foreach (is_array($row['cards'] ?? null) ? $row['cards'] : [] as $card) {
                    $id = (int) ($card['product']['id'] ?? 0);
                    // karta, która przed scaleniem już była kartą modelu, ma zdjęcie na każdy kolor — bez przycinania
                    if ($id > 0 && ! ColourGalleryTrim::isModelCard($card['product']['variant_summary'] ?? null)) {
                        $images[$id] = array_values(array_filter(
                            is_array($card['rows']['product_images'] ?? null) ? $card['rows']['product_images'] : [],
                            'is_array',
                        ));
                    }
                }
                $before[$sku] = ['keep' => (int) ($row['keep_product_id'] ?? 0), 'images' => $images];
            } elseif (($row['status'] ?? null) === 'committed' && isset($before[$sku])
                && (int) ($row['keep_product_id'] ?? 0) === $before[$sku]['keep']) {
                $committed[$before[$sku]['keep']] = $before[$sku]['images'];
                unset($before[$sku]);
            }
        }
        fclose($handle);

        return $committed;
    }
}
