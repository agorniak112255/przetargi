<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Opis wolno napisać z JEDNEJ karty. Zgłoszenia z testów ręcznych (cenniki MAPA, SECURA,
 * AJ GROUP) pokazały, że akapity z dwóch stron trafiały do jednego opisu — pierścień
 * zaczepowy dostawał treść karty półmaski, a nagłowie treść całego zestawu SECURA 3100.
 * Drugi mechanizm tej samej usterki: przy wyborze opisu wygrywał tekst dłuższy, a karta
 * zestawu jest zawsze dłuższa niż karta pojedynczej części.
 */
final class EnrichmentSingleCardDescriptionTest extends TestCase
{
    private const NAGLOWIE_CARD = 'https://sklep.example/p/naglowie-tekstylne-secura-3100-s5621300';

    private const ZESTAW_CARD = 'https://innysklep.example/p/zestaw-secura-3100-lak';

    private function naglowieProduct(): Product
    {
        return new Product([
            'sku' => 'S5621300',
            'name' => 'Nagłowie tekstylne kompletne do półmaski SECURA 3100',
            'manufacturer' => 'SECURA',
        ]);
    }

    /** Akapity dwóch różnych kart nie mogą się skleić w jeden opis. */
    public function test_description_never_mixes_paragraphs_from_two_cards(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = $this->naglowieProduct();

        $naglowie = 'Nagłowie tekstylne kompletne S5621300 do półmaski SECURA 3100. '
            ."Zestaw taśm z regulacją długości, mocowany do korpusu półmaski zatrzaskami.\n\n"
            .'Element wymienny — zużyte taśmy wymienia się bez wymiany całej półmaski SECURA 3100.';
        $zestaw = 'Zestaw lakierniczy SECURA 3100 LAK zawiera półmaskę, dwa pochłaniacze A2 '
            ."oraz parę filtrów przeciwpyłowych P2 R.\n\n"
            ."Zestaw chroni drogi oddechowe przed parami rozpuszczalników organicznych podczas lakierowania.\n\n"
            .'Półmaska SECURA 3100 wykonana jest z elastomeru termoplastycznego, masa 180 g.';

        $out = $this->invoke($service, 'descriptionFromConfirmedCards', [
            [
                ['url' => self::NAGLOWIE_CARD, 'text' => $naglowie],
                ['url' => self::ZESTAW_CARD, 'text' => $zestaw],
            ],
            $product,
        ]);

        $this->assertNotSame('', $out);
        $this->assertStringContainsString('Nagłowie tekstylne kompletne', $out);
        $this->assertStringNotContainsString('pochłaniacze A2', $out);
        $this->assertStringNotContainsString('Zestaw lakierniczy', $out);
    }

    /** Karta z kodem produktu bije kartę dłuższą, ale słabiej potwierdzoną. */
    public function test_card_with_product_code_wins_over_longer_foreign_card(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = $this->naglowieProduct();

        $krotkaWlasciwa = 'Nagłowie tekstylne kompletne S5621300 do półmaski SECURA 3100 — komplet '
            .'taśm nagłownych z płynną regulacją długości. Taśmy mocuje się do korpusu półmaski '
            .'zatrzaskami, bez użycia narzędzi. Element zużywalny: wymiana nagłowia przywraca '
            .'szczelne przyleganie części twarzowej, więc półmaski nie trzeba wymieniać w całości. '
            .'Materiał tekstylny nadaje się do prania ręcznego w letniej wodzie.';
        $dlugaObca = str_repeat(
            'Zestaw SECURA 3100 LAK z pochłaniaczami i filtrami do prac lakierniczych. ',
            12
        );

        $out = $this->invoke($service, 'fallbackDescriptionFromPages', [
            [
                ['url' => self::ZESTAW_CARD, 'text' => $dlugaObca],
                ['url' => self::NAGLOWIE_CARD, 'text' => $krotkaWlasciwa],
            ],
            $product,
        ]);

        $this->assertStringContainsString('Nagłowie tekstylne kompletne', $out);
        $this->assertStringNotContainsString('pochłaniaczami', $out);
    }

    /** Kompletnego opisu nie podmienia tekst wyłącznie dłuższy. */
    public function test_longer_text_alone_does_not_replace_complete_description(): void
    {
        $service = app(ProductEnrichmentService::class);

        $kompletny = 'Rękawice chemiczne MAPA VITAL 540 z naturalnego lateksu, grubość 1,45 mm, '
            .'długość 300 mm, powierzchnia chropowata. Kategoria III, norma EN ISO 374-1:2016 Typ A. '
            .'Przeznaczone do prac z kwasami i detergentami w przemyśle spożywczym.';
        $dluzszy = $kompletny.' '.str_repeat('Dodatkowy akapit sklepu o wysyłce i gwarancji. ', 6);

        $this->assertFalse($this->invoke($service, 'isRicherDescription', [$dluzszy, $kompletny]));
    }

    /** Karta producenta z treścią wchodzi przed karty sklepów — to ona wypełnia budżet modelu. */
    public function test_manufacturer_card_goes_first_but_thin_landing_does_not(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => '34540358',
            'name' => 'VITAL 540',
            'manufacturer' => 'MAPA',
        ]);
        $mfrDomains = ['mapa-pro.pl', 'www.mapa-pro.pl'];

        $ordered = $this->invoke($service, 'orderPagesForDescription', [
            [
                ['url' => 'https://sklep.example/rekawice-mapa-vital-540', 'text' => str_repeat('Opis sklepu. ', 60)],
                ['url' => 'https://www.mapa-pro.pl/produkty/wodoodporne/strona-produktu/vital-540', 'text' => str_repeat('Karta producenta. ', 60)],
            ],
            $product,
            $mfrDomains,
        ]);

        $this->assertStringContainsString('mapa-pro.pl', (string) ($ordered[0]['url'] ?? ''));

        $withLanding = $this->invoke($service, 'orderPagesForDescription', [
            [
                ['url' => 'https://sklep.example/rekawice-mapa-vital-540', 'text' => str_repeat('Opis sklepu. ', 60)],
                ['url' => 'https://www.mapa-pro.pl/produkty/wodoodporne', 'text' => 'Wodoodporne'],
            ],
            $product,
            $mfrDomains,
        ]);

        $this->assertStringContainsString('sklep.example', (string) ($withLanding[0]['url'] ?? ''));
    }

    /** Własne środowisko migracyjne nie jest źródłem; działający sklep klienta zostaje. */
    public function test_blocked_source_hosts_drop_own_migration_shop(): void
    {
        $service = app(ProductEnrichmentService::class);

        $kept = $this->invoke($service, 'dropBlockedSourceHosts', [[
            ['url' => 'https://migracja.supon.rzeszow.pl/3263-rekawice-mapa-vital-117.html'],
            ['url' => 'https://www.supon.rzeszow.pl/297-polmaska-secura-3000.html'],
            ['url' => 'https://www.mapa-pro.pl/produkty/vital-117'],
        ]]);

        $urls = array_column($kept, 'url');
        $this->assertCount(2, $urls);
        $this->assertStringNotContainsString('migracja.', implode(' ', $urls));
        $this->assertStringContainsString('www.supon.rzeszow.pl', implode(' ', $urls));
    }

    /** Normy z dwóch kart różniące się samym zapisem to jedna pozycja na karcie. */
    public function test_merge_extracted_collapses_norm_spellings(): void
    {
        $service = app(ProductEnrichmentService::class);

        $merged = $this->invoke($service, 'mergeExtracted', [
            ['norms' => ['EN 388', 'EN ISO 374-1:2016 Typ A']],
            ['norms' => ['EN388:2016+A1:2018', 'PN-EN ISO 374-1:2016-11 Typ A']],
        ]);

        $this->assertCount(2, $merged['norms']);
    }

    /** Bez kodu/modelu w nazwie pliku zdjęcie nie jest niczym potwierdzone. */
    public function test_only_images_named_after_the_product_skip_vision(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => '34540358',
            'name' => 'VITAL 540',
            'manufacturer' => 'MAPA',
        ]);

        $proven = $this->invoke($service, 'provenProductImages', [
            [
                'https://sklep.example/media/logo.png',
                'https://sklep.example/uploads/2021/siedziba-firmy.jpg',
                'https://sklep.example/media/catalog/34540358.jpg',
            ],
            $product,
        ]);

        $this->assertSame(['https://sklep.example/media/catalog/34540358.jpg'], $proven);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invoke(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($service, ...$args);
    }
}
