<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVisualCheck;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Catalog\ProductVisualFeatureCheck;
use App\Support\PpeAssortment;
use Illuminate\Console\Command;

/**
 * Zabudowana pięta sandałów ze zdjęcia karty — tylko karty, które nie mówią o pięcie słowami (decyzja właściciela
 * z 25.09.2026). Wynik to wniosek modelu ze zdjęcia, zapisany osobno (product_visual_checks); wyszukiwarka pokazuje go
 * modelowi oceny jako „photo_inference” i dopisuje pochodzenie do uzasadnienia.
 */
final class CheckClosedHeelCommand extends Command
{
    protected $signature = 'products:check-closed-heel
                            {--sku=* : Tylko te karty (SKU) — bez filtra „sandał”}
                            {--manufacturer= : Tylko ten producent}
                            {--limit=50 : Ile kart ocenić w jednym przebiegu}
                            {--include-web : Oceniaj też zdjęcia z sieci (bywają innym modelem)}
                            {--allow-default-profile : Pozwól na konfigurację główną, gdy zadanie „Weryfikacja zdjęć” nie ma własnego modelu}
                            {--dry-run : Tylko lista kart i powodów — bez modelu i bez zapisu}';

    protected $description = 'Ocena zabudowanej pięty sandałów ze zdjęcia karty (gdy karta nie mówi o tym słowami)';

    public function handle(ProductVisualFeatureCheck $checker, PpeAssortment $assortment, AiSettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeWeb = (bool) $this->option('include-web');
        if (! $dryRun && ($settings->profileForTask(AiTask::ImageVerification)['is_default'] ?? true)
            && ! $this->option('allow-default-profile')) {
            $this->error('Zadanie „Weryfikacja zdjęć” nie ma przypisanego modelu z obsługą obrazu (Ustawienia AI → Profile).'
                .' Przypisz go albo uruchom z --allow-default-profile, jeśli model główny widzi obrazy.');

            return self::FAILURE;
        }

        $skus = array_values(array_filter(array_map('strval', (array) $this->option('sku'))));
        $query = Product::query()->whereHas('images')->orderBy('id');
        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        } else {
            $query->where(function ($w): void {
                foreach (['name', 'description', 'shop_fields_summary', 'enrichment_payload'] as $column) {
                    $w->orWhere($column, 'like', '%sanda%');
                }
            });
        }
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($manufacturer !== '') {
            $query->where('manufacturer', $manufacturer);
        }

        $limit = max(1, (int) $this->option('limit'));
        $done = 0;
        $attempts = 0;
        $failedInRow = 0;
        $counts = [];
        foreach ($query->lazyById(200) as $product) {
            if ($skus === [] && ! $assortment->isSandalCard($product)) {
                continue;
            }
            if ($dryRun) {
                $plan = $checker->plan($product, $includeWeb);
                $reason = $plan['skip'] ?? 'do oceny';
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
                $this->line(sprintf('  %s | %s | %s', $product->sku, mb_substr((string) $product->name, 0, 60), $reason));

                continue;
            }
            // --limit liczy zapytania do modelu, nie tylko udane zapisy: leżący model nie może przejść całego katalogu.
            if ($attempts >= $limit) {
                break;
            }
            $result = $checker->checkClosedHeel($product, $includeWeb);
            $check = $result['check'];
            if ($result['attempted']) {
                $attempts++;
            }
            if ($check === null) {
                $counts[(string) $result['skip']] = ($counts[(string) $result['skip']] ?? 0) + 1;
                $failedInRow = $result['attempted'] ? $failedInRow + 1 : $failedInRow;
                if ($failedInRow >= 5) {
                    $this->error('5 kolejnych nieudanych zapytań do modelu — przerywam. Sprawdź profil „Weryfikacja zdjęć”.');

                    break;
                }

                continue;
            }
            $failedInRow = 0;
            $done++;
            $counts[$check->answer] = ($counts[$check->answer] ?? 0) + 1;
            // „open” przy klasie S1–S5: norma wymaga zamkniętej części piętowej — raczej pomyłka oceny albo sprzeczna karta.
            $flag = $check->answer === ProductVisualCheck::ANSWER_OPEN
                && preg_match('/(?<![\p{L}\d])S[1-5]/u', (string) $product->name.' '.(string) $product->norms) === 1
                ? '  ← DO PRZEJRZENIA: odkryta pięta przy klasie S1–S5'
                : '';
            $this->line(sprintf(
                '  %s | %s | %s | %s | %s%s',
                $product->sku,
                mb_substr((string) $product->name, 0, 50),
                $check->answer,
                (string) $check->what_seen,
                $check->image?->url ?? '',
                $flag,
            ));
        }

        ksort($counts);
        $this->info(($dryRun ? 'Podgląd: ' : 'Ocenione: '.$done.'. ').'Podsumowanie: '.json_encode($counts, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
