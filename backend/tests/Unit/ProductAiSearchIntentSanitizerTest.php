<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\CatalogSlangDictionary;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Sanitizer kroków i frazy żargonu (bez modelu): cechy techniczne i klasy nie są żargonem,
 * nazwy z cennika zostają krokiem, frazy nie niosą interpunkcji z SIWZ.
 */
final class ProductAiSearchIntentSanitizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitize_search_steps_keeps_classes_features_and_catalog_nouns(): void
    {
        $svc = $this->service();

        // Kroki modelu dla poz. 5 (AUDYT B, _probe_b3): S5 i antyprzebiciowe wypadały jako „żargon”.
        $waders = $this->invokePrivate($svc, 'sanitizeSearchSteps',
            ['spodniobuty', 'wodoochronne', 'S5', 'antyprzebiciowe', 'fluorescencyjne', 'szelki'],
            $this->invokePrivate($svc, 'localIntent', Opisowy15Fixture::requirement(5)),
        );
        $this->assertContains('spodniobuty', $waders);
        $this->assertContains('S5', $waders);
        $this->assertContains('antyprzebiciowe', $waders);
        $this->assertSame('spodniobuty', $waders[0]);

        // Poz. 1: „narękawniki” to nazwa z cennika, „antystatyczne” to cecha (jargon=false).
        $sleeve = $this->invokePrivate($svc, 'sanitizeSearchSteps',
            ['narękawniki', 'antyprzecięciowe', 'fluorescencyjne', 'antystatyczne'],
            $this->invokePrivate($svc, 'localIntent', Opisowy15Fixture::requirement(1)),
        );
        $this->assertContains('narękawniki', $sleeve);
        $this->assertContains('antystatyczne', $sleeve);

        // Żargon (wampirki) dalej nie jest krokiem — kaskada szuka po frazach cennika.
        $vampire = $this->invokePrivate($svc, 'sanitizeSearchSteps',
            ['rękawice', 'wampirki', 'dłoń powlekana'],
            $this->invokePrivate($svc, 'localIntent', 'Rękawice wampirki uniwersalne'),
        );
        $this->assertSame(['rękawice', 'dłoń powlekana'], $vampire);
    }

    /**
     * Wpis żargonu „nitryle, nitrylki, nitrylowe” ma flagę jargon=true na całości, a „nitrylowe” stoi w nazwach setek
     * kart. Zdjęty krok zostawiał kaskadzie samo „rękawice” (24.09: 16 ze 115 zapisanych rozumień).
     */
    public function test_catalog_name_words_stay_steps_despite_jargon_flag(): void
    {
        foreach (range(1, 5) as $i) {
            Product::query()->create([
                'sku' => 'NIT-'.$i,
                'name' => 'Rękawice nitrylowe jednorazowe bezpudrowe '.$i,
                'manufacturer' => 'Delta Plus',
                'catalog_price_net' => 8,
                'purchase_price' => 4,
                'stock' => 1,
                'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            ]);
        }
        // Jedna karta ze słowem żargonu to nie słowo katalogu.
        Product::query()->create([
            'sku' => 'WAMP-1',
            'name' => 'Rękawice wampirki powlekane',
            'manufacturer' => 'Delta Plus',
            'catalog_price_net' => 5,
            'purchase_price' => 2,
            'stock' => 1,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $svc = $this->service();

        $steps = $this->invokePrivate($svc, 'sanitizeSearchSteps',
            ['rękawice', 'nitrylowe', 'jednorazowe', 'wampirki'],
            $this->invokePrivate($svc, 'localIntent', 'Rękawice nitrylowe jednorazowe'),
        );

        $this->assertSame(['rękawice', 'nitrylowe', 'jednorazowe'], $steps);
        $this->assertFalse($this->invokePrivate($svc, 'isWeakSearchStep', 'nitrylowe'));
        $this->assertTrue($this->invokePrivate($svc, 'isWeakSearchStep', 'wampirki'));
    }

    public function test_jargon_flag_still_strips_words_the_catalog_does_not_name(): void
    {
        // Pusty katalog: „nitrylowe” nie stoi w żadnej nazwie — jak dotąd traktujemy je jak żargon wpisu.
        $svc = $this->service();

        $steps = $this->invokePrivate($svc, 'sanitizeSearchSteps',
            ['rękawice', 'nitrylowe'],
            $this->invokePrivate($svc, 'localIntent', 'Rękawice nitrylowe'),
        );

        $this->assertSame(['rękawice'], $steps);
    }

    public function test_class_or_norm_token_is_a_strong_step(): void
    {
        $svc = $this->service();
        foreach (['S5', 'S1P', 'FFP2', 'OB', 'A2', 'EN 149', 'SRC'] as $step) {
            $this->assertFalse($this->invokePrivate($svc, 'isWeakSearchStep', $step), $step);
        }
        foreach (['uniwersalne', 'ochrona przed cieczą', 'lekkie', 'proste'] as $step) {
            $this->assertTrue($this->invokePrivate($svc, 'isWeakSearchStep', $step), $step);
        }
    }

    public function test_slang_search_phrases_carry_no_punctuation(): void
    {
        $svc = $this->service();
        $query = Opisowy15Fixture::requirement(3);
        $rewrite = $this->app->make(CatalogSlangDictionary::class)->searchRewrite($query);
        $this->assertNotNull($rewrite);

        $phrases = $this->invokePrivate($svc, 'slangSearchPhrases', $rewrite, $query, []);

        $this->assertContains('cholewką', $phrases);
        foreach ($phrases as $phrase) {
            $this->assertDoesNotMatchRegularExpression('/[()\[\]"„”;:.,]$|^[("„]/u', $phrase, $phrase);
        }
    }

    private function service(): ProductAiSearchService
    {
        return $this->app->make(ProductAiSearchService::class);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
