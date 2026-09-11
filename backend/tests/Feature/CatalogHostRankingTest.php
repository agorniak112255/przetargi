<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogHostScore;
use App\Services\Enrichment\CatalogHostRanking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Liczba zapytań site: jest ograniczona, więc o wyniku decyduje kolejność domen.
 */
final class CatalogHostRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_collected_data_order_from_config_stays(): void
    {
        $hosts = ['icd.pl', 'bhp-gabi.pl', 'marketbhp.pl', 'bogarobhp.pl'];

        $this->assertSame($hosts, app(CatalogHostRanking::class)->order($hosts));
    }

    public function test_shop_that_gave_cards_goes_first(): void
    {
        CatalogHostScore::query()->create(['host' => 'marketbhp.pl', 'hits' => 7]);
        CatalogHostScore::query()->create(['host' => 'icd.pl', 'hits' => 2]);

        $ordered = app(CatalogHostRanking::class)->order(
            ['bhp-gabi.pl', 'icd.pl', 'marketbhp.pl', 'bogarobhp.pl']
        );

        // najpierw sklepy z trafieniami (więcej trafień wyżej), potem reszta bez zmian
        $this->assertSame(['marketbhp.pl', 'icd.pl', 'bhp-gabi.pl', 'bogarobhp.pl'], $ordered);
    }

    public function test_shop_that_never_helped_lands_last(): void
    {
        CatalogHostScore::query()->create(['host' => 'bhp-gabi.pl', 'misses' => 12]);

        $ordered = app(CatalogHostRanking::class)->order(['bhp-gabi.pl', 'icd.pl', 'marketbhp.pl']);

        $this->assertSame(['icd.pl', 'marketbhp.pl', 'bhp-gabi.pl'], $ordered);
    }

    public function test_hit_and_miss_are_counted_per_host(): void
    {
        $ranking = app(CatalogHostRanking::class);
        $ranking->recordHit('https://icd.pl/produkt/rekawice-123');
        $ranking->recordHit('https://icd.pl/produkt/inne-456');
        $ranking->recordMiss('https://bhp-gabi.pl/szukaj?q=nic');

        $icd = CatalogHostScore::query()->where('host', 'icd.pl')->firstOrFail();
        $this->assertSame(2, $icd->hits);
        $this->assertNotNull($icd->last_hit_at);
        $this->assertSame(
            1,
            (int) CatalogHostScore::query()->where('host', 'bhp-gabi.pl')->value('misses')
        );
    }
}
