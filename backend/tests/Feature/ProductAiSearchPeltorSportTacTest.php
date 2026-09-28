<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use App\Support\ProductModelFuzzy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Golden czasze-peltor-sporttac-czarne (produkcja 28.09.2026): pod „3M PELTOR SportTac” igłą nazwanego modelu było
 * samo „peltor”, więc gałąź nazwanego modelu kończyła wyszukiwanie pulą dowolnych kart PELTOR (wkładki higieniczne,
 * okulary Solus, nauszniki X4, hełm G3000), a żadnej karty SportTac w niej nie było. Nazwy kart jak na produkcji.
 */
final class ProductAiSearchPeltorSportTacTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Wymienne czasze do nauszników 3M PELTOR SportTac w kolorze czarnym';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_named_line_after_sub_brand_is_the_model_needle(): void
    {
        $fuzzy = new ProductModelFuzzy;

        $this->assertSame(['sporttac'], $fuzzy->catalogModelNeedles(self::QUERY));
        $this->assertSame(['sporttac'], $fuzzy->needles('Nauszniki 3M™ Peltor™ SportTac™'));
        // Linia za podmarką nie rozstrzyga przetargu bez oceny modelu (dotąd rozstrzygało samo „peltor” — każda karta PELTOR).
        $this->assertSame([], $fuzzy->strongSkuNeedles(self::QUERY));
        // Zamienniki: z tekstu schodzi marka, podmarka i linia.
        $this->assertSame('Wymienne czasze do nauszników w kolorze czarnym', $fuzzy->withoutNamedModel(self::QUERY));
    }

    public function test_plain_words_after_sub_brand_keep_previous_needles(): void
    {
        $fuzzy = new ProductModelFuzzy;

        $this->assertSame(['peltor'], $fuzzy->needles('Nauszniki 3M PELTOR czarne'));
        $this->assertSame(['peltor'], $fuzzy->needles('Nauszniki 3M PELTOR Optime II'));
        $this->assertSame(['peltorx2'], $fuzzy->needles('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna'));
    }

    public function test_sporttac_ear_cups_enter_pool_despite_many_other_peltor_cards(): void
    {
        $decoys = [
            'Zestaw higieniczny 3M™ PELTOR™ do nauszników Optime I, HY51',
            'Wkładki higieniczne 3M™ PELTOR™, HY100A',
            'Nauszniki 3M™ PELTOR™ X4A, nagłowne, czarne',
            'Nauszniki 3M™ PELTOR™ X4P5E, mocowane na kasku',
            'Nauszniki 3M™ PELTOR™ Optime™ II, H520A',
            'Nauszniki 3M™ PELTOR™ Optime™ III, H540A',
            'Nauszniki 3M™ PELTOR™ WS™ ALERT™ XPI, MRX21A2WS6',
            'Nauszniki 3M™ PELTOR™ LiteCom Plus, MT73H7A4D10EU',
            'Hełm ochronny 3M™ PELTOR™ G3000, biały',
            'Okulary ochronne 3M™ PELTOR™ Solus™ 1000, szare',
        ];
        // Karty PELTOR przed kartami SportTac: skan po igle „peltor” oddaje wiersze w kolejności z bazy.
        foreach (range(1, 10) as $round) {
            foreach ($decoys as $n => $name) {
                $this->card('D'.$round.'-'.$n, $name.' '.$round, PpeAssortment::FAMILY_HEARING);
            }
        }
        $cups = $this->card('7000107844', 'Wymienne czasze 3M™ PELTOR™ SportTac™, 210100-478', PpeAssortment::FAMILY_HEARING);
        $covers = $this->card('7000107841', '3M™ PELTOR™ SportTac™ Pokrywy wymienne, 210100-478', PpeAssortment::FAMILY_HEARING);

        foreach ([40, 80] as $limit) {
            $ids = $this->retrieve($limit)->pluck('id')->map(intval(...))->all();

            $this->assertContains((int) $cups->id, $ids, 'pula '.$limit);
            $this->assertContains((int) $covers->id, $ids, 'pula '.$limit);
        }
    }

    /**
     * @return Collection<int, Product>
     */
    private function retrieve(int $limit): Collection
    {
        $search = $this->app->make(ProductAiSearchService::class);
        $local = new \ReflectionMethod($search, 'localIntent');
        $retrieve = new \ReflectionMethod($search, 'retrieveCandidates');

        return $retrieve->invoke($search, self::QUERY, $local->invoke($search, self::QUERY), $limit);
    }

    private function card(string $sku, string $name, ?string $family): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => '3M',
            'category' => 'Ochrona słuchu',
            'description' => $name,
            'catalog_price_net' => 100,
            'purchase_price' => 60,
            'stock' => 4,
            'ppe_family' => $family,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonth(),
        ]);
    }
}
