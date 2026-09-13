<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductMatchService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bramka podtypów i punktacja „ten sam typ” w dopasowaniu przetargu (W3):
 * ta sama rodzina to za mało — półmaska wielorazowa ≠ FFP1, gogle ≠ FFP, OB ≠ elektroizolacyjne.
 */
final class ProductMatchSubtypeTest extends TestCase
{
    private ProductMatchService $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(ProductMatchService::class);
    }

    #[Test]
    public function welding_goggles_do_not_match_ffp_mask(): void
    {
        $req = 'Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0 – do ochrony oczu podczas spawania gazowego i cięcia '
            .'metalu. Wymagane: soczewka poliwęglanowa; wysoki profil umożliwiający noszenie na okularach korekcyjnych; '
            .'możliwość stosowania łącznie z półmaskami oddechowymi. Zgodność z EN 166, oznakowanie CE.';
        $ffp = $this->fakeProduct([
            'sku' => '9310+',
            'name' => '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+',
            'manufacturer' => '3M',
            'category' => 'Środki ochrony indywidualnej',
            'description' => 'Półmaska filtrująca 3M Aura 9310+ to jednorazowa półmaska klasy FFP1. Kompatybilna z okularami i goglami 3M.',
        ]);
        $goggles = $this->fakeProduct([
            'sku' => '34340',
            'name' => '3M™ 2890 Gogle ochronne, szczelne, powłoka odporna na zarysowanie/zaparowanie, zaciemnienie spawalnicze 5.0, 2895S',
            'manufacturer' => '3M',
            'category' => 'Materiały ścierne',
            'description' => 'Szczelne gogle spawalnicze z zaciemnieniem 5.0, również w połączeniu z półmaskami oddechowymi 3M. EN 166.',
        ]);

        $rejected = $this->matcher->explainMatch($req, $ffp);
        $this->assertSame('asortyment_reject', $rejected['reasons'][0]['code']);
        $this->assertSame(0, $rejected['score']);

        $accepted = $this->matcher->explainMatch($req, $goggles);
        $codes = array_column($accepted['reasons'], 'code');
        $this->assertNotContains('asortyment_reject', $codes);
        $this->assertContains('type_name', $codes);
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $accepted['score']);
    }

    #[Test]
    public function reusable_half_mask_rejects_ffp_and_keeps_secura(): void
    {
        $req = 'Półmaska wielokrotnego użytku do ochrony układu oddechowego – po skompletowaniu z elementami oczyszczającymi chroni '
            .'przed aerozolami, parami i gazami. Wymagane: korpus z dwoma zaworami wdechowymi z łącznikami bagnetowymi; zawór '
            .'wydechowy z pokrywą; jednoczęściowe nagłowie tekstylne; zgodność z normą PN-EN 140:2004 (EN 140:1998).';
        $ffp = $this->fakeProduct([
            'sku' => '9310+',
            'name' => '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+',
            'manufacturer' => '3M',
            'description' => 'Jednorazowa półmaska klasy FFP1, EN 149.',
        ]);
        $secura = $this->fakeProduct([
            'sku' => 'S56T0SM0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'category' => 'PÓŁMASKA SECURA 3000',
            'description' => 'Półmaska SECURA 3000 składa się z korpusu, dwóch zaworów wdechowych z łącznikami bagnetowymi, '
                .'zaworu wydechowego oraz nagłowia. Zgodność z PN-EN 140:2004.',
        ]);

        $this->assertSame('asortyment_reject', $this->matcher->explainMatch($req, $ffp)['reasons'][0]['code']);
        $accepted = $this->matcher->explainMatch($req, $secura);
        $this->assertContains('type_name', array_column($accepted['reasons'], 'code'));
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $accepted['score']);
    }

    #[Test]
    public function type_name_bonus_requires_subtype_agreement(): void
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'typeNameScore');
        $reusable = 'Półmaska wielokrotnego użytku z łącznikami bagnetowymi, zawór wydechowy z pokrywą, PN-EN 140';
        $ffp = $this->fakeProduct([
            'sku' => '9310+',
            'name' => '3M™ Aura™ półmaska filtrująca, FFP1, bez zaworu, 9310+',
        ]);
        $secura = $this->fakeProduct([
            'sku' => 'S56T0SM0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
        ]);
        $elastomer = $this->fakeProduct([
            'sku' => '6200',
            'name' => 'Półmaska wielorazowa 3M 6200 elastomerowa',
        ]);

        $this->assertSame(0, $method->invoke($this->matcher, $this->norm($reusable), $ffp), 'FFP1 vs półmaska wielorazowa: sprzeczne podtypy');
        $this->assertSame(40, $method->invoke($this->matcher, $this->norm($reusable), $secura), 'goła „półmaska” = podtyp nieznany, bonus zostaje');
        $this->assertSame(40, $method->invoke($this->matcher, $this->norm($reusable), $elastomer));

        // Rodzina bez bramki podtypu (asekuracja): szelki vs linka też nie dostają „ten sam typ”.
        $harnessReq = $this->norm('Szelki bezpieczeństwa z linką EN 361');
        $this->assertSame(0, $method->invoke($this->matcher, $harnessReq, $this->fakeProduct([
            'sku' => 'L-1',
            'name' => 'Linka bezpieczeństwa z amortyzatorem',
        ])));
        $this->assertSame(40, $method->invoke($this->matcher, $harnessReq, $this->fakeProduct([
            'sku' => 'AB178',
            'name' => 'Szelki bezpieczeństwa typu kamizelka 3M Protecta',
        ])));
    }

    #[Test]
    public function insulating_shoes_reject_ob_esd_shoe_and_keep_antyamper(): void
    {
        $req = 'Półbuty elektroizolacyjne do prac przy urządzeniach i instalacjach elektroenergetycznych o napięciu przemiennym do '
            .'17 kV, przeznaczone do nakładania na inne obuwie robocze. Wymagane: klasa 2 AC zgodnie z normą EN 50321-1; wykonanie '
            .'z gumy naturalnej; zgodność z normą EN 20347:2012 dla obuwia zawodowego kategorii OB; odporność na poślizg SRA.';
        $antyamper = $this->fakeProduct([
            'sku' => 'T5912100',
            'name' => 'Półbuty elektroizolacyjne 20 kV - ANTYAMPER',
            'manufacturer' => 'SECURA',
            'category' => '11.1 OBUWIE ELEKTROIZOLACYJNE',
            'description' => 'Półbuty elektroizolacyjne ANTYAMPER 20 kV marki SECURA. Produkt klasy 2 AC zgodnie z normą EN 50321-1. '
                .'Obuwie wykonane z gumy naturalnej. Spełnia wymagania normy EN 20347:2012 dla obuwia zawodowego kategorii OB, SRA.',
        ]);
        $plainOb = $this->fakeProduct([
            'sku' => 'ART 702 Air 6660 OB A E FO',
            'name' => 'ART 702 Air 6660 OB A E FO',
            'manufacturer' => 'ARTRA',
            'description' => 'Obuwie robocze ART 702 Air 6660 OB A E FO do kontroli ładunków elektrostatycznych. Spełnia normę '
                .'EN ISO 20347:2012 w klasie OB A E FO SRC oraz wymagania ESD zgodnie z EN IEC 61340-4-3:2018.',
        ]);

        $rejected = $this->matcher->explainMatch($req, $plainOb);
        $this->assertSame('asortyment_reject', $rejected['reasons'][0]['code']);

        $accepted = $this->matcher->explainMatch($req, $antyamper);
        $codes = array_column($accepted['reasons'], 'code');
        $this->assertContains('attr_klasa', $codes, 'OB potwierdzone razem z elektroizolacją punktuje');
        $this->assertGreaterThanOrEqual(ProductMatchService::MIN_MATCH_SCORE, $accepted['score']);
    }

    #[Test]
    public function attr_klasa_does_not_score_ob_without_electrical_insulation(): void
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'attributeMatchScore');
        $req = $this->norm('Półbuty elektroizolacyjne 20 kV klasa 2 AC EN 50321-1, EN 20347 kategorii OB, SRA');
        $plainOb = $this->fakeProduct([
            'sku' => 'ART 702 Air 6660 OB A E FO',
            'name' => 'ART 702 Air 6660 OB A E FO',
            'description' => 'Obuwie robocze EN ISO 20347:2012 w klasie OB A E FO SRC, ESD EN IEC 61340-4-3.',
        ]);
        $insulating = $this->fakeProduct([
            'sku' => 'T5912100',
            'name' => 'Półbuty elektroizolacyjne 20 kV - ANTYAMPER',
            'description' => 'Obuwie klasy 2 AC EN 50321-1, EN 20347:2012 kategorii OB.',
        ]);

        $plainCodes = array_column($method->invoke($this->matcher, $req, $plainOb, [])['reasons'], 'code');
        $this->assertNotContains('attr_klasa', $plainCodes, 'OB bez elektroizolacji nie jest dowodem');

        $insulatingCodes = array_column($method->invoke($this->matcher, $req, $insulating, [])['reasons'], 'code');
        $this->assertContains('attr_klasa', $insulatingCodes);

        // Bez wymogu elektroizolacji zwykłe OB punktuje jak dotąd.
        $plainReq = $this->norm('Półbuty robocze OB SRA EN 20347');
        $this->assertContains('attr_klasa', array_column($method->invoke($this->matcher, $plainReq, $plainOb, [])['reasons'], 'code'));
    }

    #[Test]
    public function lower_footwear_class_does_not_score_attr_klasa(): void
    {
        $req = 'Sandały ochronne (obuwie bezpieczne z odkrytą cholewką) kategorii S1 P wg EN ISO 20345, do prac w suchych '
            .'pomieszczeniach. Wymagane: zabudowana pięta; podnosek ochronny; właściwości antyelektrostatyczne (ESD); podeszwa FO.';
        $s1p = $this->fakeProduct([
            'sku' => 'ARMEN 9007 6660 S1 P',
            'name' => 'ARMEN 9007 6660 S1 P',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Sandały robocze ARTRA ARMEN 9007 6660 S1 P. Model spełnia wymagania klasy S1 według normy EN ISO 20345, '
                .'podnosek ochronny oraz właściwości antyelektrostatyczne (ESD). Podeszwa odporna na oleje i paliwa (FO).',
        ]);
        $s1 = $this->fakeProduct([
            'sku' => 'AROX 733 641460 S1 ESD',
            'name' => 'AROX 733 641460 S1 ESD',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Obuwie ochronne AROX 733 641460 S1 ESD. Spełnia normę EN ISO 20345:2022 w klasie S1 z FO i SR, '
                .'a także normę ESD EN IEC 61340-4-3:2018.',
        ]);

        $sandals = $this->matcher->explainMatch($req, $s1p);
        $lowShoes = $this->matcher->explainMatch($req, $s1);

        $this->assertContains('attr_klasa', array_column($sandals['reasons'], 'code'), 'S1 P ze spacją = S1P');
        $this->assertNotContains('attr_klasa', array_column($lowShoes['reasons'], 'code'), 'S1 nie spełnia S1P');
        $this->assertGreaterThan($lowShoes['score'], $sandals['score']);
    }

    private function norm(string $text): string
    {
        $method = new \ReflectionMethod(ProductMatchService::class, 'normalize');

        return $method->invoke($this->matcher, $text);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function fakeProduct(array $attrs): Product
    {
        $p = new Product;
        $p->forceFill(array_merge([
            'id' => random_int(1, 999999),
            'sku' => 'X',
            'name' => 'X',
            'manufacturer' => 'X',
            'category' => null,
            'norms' => null,
            'description' => null,
            'enrichment_payload' => null,
            'purchase_price' => 1,
            'catalog_price_net' => 1,
        ], $attrs));

        return $p;
    }
}
