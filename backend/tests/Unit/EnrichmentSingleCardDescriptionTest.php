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
 *
 * Od 22.09.2026 opis pisze wyłącznie model — składanie opisu zapasowego z akapitów kart
 * (i jego testy) usunięto razem z tym mechanizmem; zostają testy wyboru i scalania kart.
 */
final class EnrichmentSingleCardDescriptionTest extends TestCase
{
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

    /**
     * Nasz sklep (i jego środowisko migracyjne) nie jest źródłem: eksport wysyła tam nasze
     * opisy, więc opis stamtąd to nasz własny tekst. Audyt 22.09.2026: 91 kart z opisem
     * „ze sklepu” supon.rzeszow.pl. Zmiana decyzji użytkownika — wcześniej sklep zostawał.
     */
    public function test_blocked_source_hosts_drop_own_shop_and_migration_shop(): void
    {
        $service = app(ProductEnrichmentService::class);

        $kept = $this->invoke($service, 'dropBlockedSourceHosts', [[
            ['url' => 'https://migracja.supon.rzeszow.pl/3263-rekawice-mapa-vital-117.html'],
            ['url' => 'https://www.supon.rzeszow.pl/297-polmaska-secura-3000.html'],
            ['url' => 'https://supon.rzeszow.pl/apteczki/390102-apteczka-cederroth.html'],
            ['url' => 'https://www.mapa-pro.pl/produkty/vital-117'],
        ]]);

        $this->assertSame(['https://www.mapa-pro.pl/produkty/vital-117'], array_column($kept, 'url'));
    }

    /** Host sklepu z prestashop.shop_url jest wykluczony także wtedy, gdy nie ma go na liście. */
    public function test_own_shop_host_from_presta_config_is_always_blocked(): void
    {
        config([
            'enrichment.blocked_source_hosts' => [],
            'prestashop.shop_url' => 'https://www.nasz-sklep.example/',
        ]);
        $service = app(ProductEnrichmentService::class);

        $kept = $this->invoke($service, 'dropBlockedSourceHosts', [[
            ['url' => 'https://nasz-sklep.example/297-polmaska-secura-3000.html'],
            ['url' => 'https://b2b.nasz-sklep.example/297'],
            ['url' => 'https://inny-nasz-sklep.example/297'],
        ]]);

        $this->assertSame(['https://inny-nasz-sklep.example/297'], array_column($kept, 'url'));
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
