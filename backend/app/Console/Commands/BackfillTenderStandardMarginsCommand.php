<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\TenderPricingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Marża bliźniacza przetargów (decyzja właściciela 30.09.2026): migracja 2026_09_30_210100 skopiowała marżę tam,
 * gdzie żadna karta pozycji nie ma slotu B2B z ceną specjalną; reszta ma margin_percent_standard = NULL („—” dla
 * użytkownika bez prices.supplier_special.view). Polecenie liczy te braki od ceny standardowej — tą samą regułą co
 * TenderPricingService::recalculateItemMargin — i zapisuje wyłącznie margin_percent_standard.
 *
 * Tylko pozycje z margin_percent (marża rzeczywista policzona) i bez bliźniaczej; margin_percent, ceny ofert i daty
 * zmian zostają nietknięte. Prawdziwej marży nie kopiujemy nigdy — pozycja, której bliźniaczej nie da się dziś
 * policzyć (karta bez ceny albo skasowana), zostaje NULL. Marża przetargu tą samą regułą co
 * TenderPricingService::weightedMargin: czeka, póki któraś pozycja da się jeszcze policzyć, a pozycje niepoliczalne
 * pomija. Powtórne uruchomienie nic nie zmienia (idempotentne); wdrożenie (deploy/server-update.sh) woła je z --apply
 * po migracji. Serwer ma 128 MB pamięci dla CLI — czytamy tylko kolumny potrzebne do marży, porcjami.
 *
 * Domyślnie podgląd — nic nie zapisuje.
 */
final class BackfillTenderStandardMarginsCommand extends Command
{
    protected $signature = 'tenders:backfill-standard-margins
        {--apply : Zapisz marże bliźniacze (bez tej flagi tylko podgląd)}';

    protected $description = 'Uzupełnia marże przetargów i pozycji liczone od ceny standardowej kart z ceną specjalną B2B (margin_percent_standard)';

    private const CHUNK = 100;

    /** Kolumny pozycji potrzebne do marży (lineOfferUnit, offerVariant, karty) — bez treści wymagań i uzasadnień. */
    private const ITEM_COLUMNS = [
        'id', 'tender_id', 'main_product_id', 'main_variant_id', 'companion_product_id', 'quantity',
        'offer_price', 'companion_offer_price', 'margin_percent', 'margin_percent_standard',
    ];

    private const PRODUCT_COLUMNS = 'id,purchase_price,catalog_price_net,discount_percent,currency';

    private const VARIANT_COLUMNS = 'id,product_id,purchase_price,currency,b2b_account_id';

    public function handle(TenderPricingService $pricing): int
    {
        $apply = (bool) $this->option('apply');

        [$items, $itemsSkipped] = $this->backfillItems($pricing, $apply);
        [$tenders, $tendersWaiting] = $this->backfillTenders($pricing, $apply);

        $this->table(['', 'uzupełnione', 'bez wyniku'], [
            ['Pozycje', $items, $itemsSkipped],
            ['Przetargi', $tenders, $tendersWaiting],
        ]);
        // jedna linia do logu wdrożenia (server-update.sh woła polecenie z „|| true”)
        $this->info(sprintf(
            'Marże od ceny standardowej%s: pozycje %d uzupełnione, %d bez wyniku; przetargi %d uzupełnione, %d bez wyniku; pamięć %.1f MB.',
            $apply ? '' : ' (podgląd, bez zapisu)',
            $items,
            $itemsSkipped,
            $tenders,
            $tendersWaiting,
            memory_get_peak_usage(true) / 1048576,
        ));
        if (! $apply) {
            $this->warn('Podgląd — nic nie zapisano. Zapis: --apply');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [uzupełnione, bez wyniku]
     */
    private function backfillItems(TenderPricingService $pricing, bool $apply): array
    {
        $done = 0;
        $skipped = 0;
        TenderItem::query()
            ->select(self::ITEM_COLUMNS)
            ->whereNotNull('margin_percent')
            ->whereNull('margin_percent_standard')
            ->with([
                'mainProduct:'.self::PRODUCT_COLUMNS,
                'mainVariant:'.self::VARIANT_COLUMNS,
                'companionProduct:'.self::PRODUCT_COLUMNS,
            ])
            ->chunkById(self::CHUNK, function (Collection $items) use ($pricing, $apply, &$done, &$skipped): void {
                // maska na porcję — pamięta ceny, więc nie przeżywa porcji
                $mask = $pricing->standardMask($items);
                foreach ($items as $item) {
                    /** @var TenderItem $item */
                    $margin = $pricing->itemMargin($item, $mask);
                    if ($margin === null) {
                        $skipped++;

                        continue;
                    }
                    if ($apply) {
                        // bez modelu: nie ruszamy updated_at ani haków pozycji; warunek NULL chroni przed wyścigiem
                        TenderItem::query()->whereKey($item->getKey())->whereNull('margin_percent_standard')
                            ->toBase()->update(['margin_percent_standard' => $margin]);
                    }
                    $done++;
                }
            });

        return [$done, $skipped];
    }

    /**
     * @return array{0: int, 1: int} [uzupełnione, czekające na pozycje]
     */
    private function backfillTenders(TenderPricingService $pricing, bool $apply): array
    {
        $done = 0;
        $waiting = 0;
        Tender::query()
            ->select(['id', 'margin_percent', 'margin_percent_standard'])
            ->whereNotNull('margin_percent')
            ->whereNull('margin_percent_standard')
            ->with(['items' => static fn ($query) => $query->select(self::ITEM_COLUMNS)])
            ->chunkById(self::CHUNK, function (Collection $tenders) use ($pricing, $apply, &$done, &$waiting): void {
                foreach ($tenders as $tender) {
                    /** @var Tender $tender */
                    // null, gdy któraś pozycja z ofertą i marżą czeka na bliźniaczą, którą da się policzyć (weightedMargin);
                    // w podglądzie pozycji nie zapisujemy, więc przetargi z pozycjami z tego przebiegu wychodzą jako czekające
                    $margin = $pricing->weightedMargin($tender, 'margin_percent_standard');
                    if ($margin === null) {
                        $waiting++;

                        continue;
                    }
                    if ($apply) {
                        Tender::query()->whereKey($tender->getKey())->whereNull('margin_percent_standard')
                            ->toBase()->update(['margin_percent_standard' => $margin]);
                    }
                    $done++;
                }
            });

        return [$done, $waiting];
    }
}
