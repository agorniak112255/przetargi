<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use App\Support\CatalogManufacturerContext;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Przegląd 25.09.2026: SIWZ wersalikami bez żadnej marki („SPODNIE ROBOCZE DO PASA KRÓJ PROSTY”) dawał intencję
 * z producentem PROS — słowo „PROSTY” zawiera nazwę krótkiego producenta, a luźny etap dopasowania producenta
 * szukał podciągu. Marka z intencji zawężała pulę do kart PROS i kart z „pros” w nazwie; spodnie innych marek
 * znikały przed oceną modelu.
 */
final class ProductAiSearchManufacturerInsideWordTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'SPODNIE ROBOCZE DO PASA KRÓJ PROSTY';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    public function test_uppercase_word_containing_short_manufacturer_does_not_become_the_brand(): void
    {
        $this->card('PROS-SP-1', 'Spodnie robocze do pasa', 'PROS');
        $this->card('PW-SP-PROSTY', 'Spodnie robocze do pasa KRÓJ prosty', 'Portwest');
        $this->card('PW-SP-2', 'Spodnie robocze do pasa', 'Portwest');
        CatalogManufacturerContext::forgetCache();

        $search = $this->app->make(ProductAiSearchService::class);
        $intent = $this->invokePrivate($search, 'localIntent', self::QUERY);

        $this->assertNull($intent['manufacturer'], 'słowo „PROSTY” to nie marka PROS');
        $this->assertNull($intent['manufacturer_requested']);
        $this->assertFalse($intent['manufacturer_absent_in_catalog']);

        $skus = $this->invokePrivate($search, 'retrieveCandidates', self::QUERY, $intent, 40)->pluck('sku')->all();
        foreach (['PROS-SP-1', 'PW-SP-PROSTY', 'PW-SP-2'] as $sku) {
            $this->assertContains($sku, $skus, "karta {$sku} wypadła z puli");
        }

        // Ta sama pomyłka w dopasowaniu przetargu oznaczała każdą kartę spoza PROS jako „Zamiennik”.
        $this->assertNull($this->app->make(ProductMatchService::class)->requestedManufacturerFromSiwz(self::QUERY));
    }

    public function test_real_brand_in_uppercase_requirement_still_sets_the_manufacturer(): void
    {
        $this->card('PROS-SP-1', 'Spodnie robocze do pasa', 'PROS');
        $this->card('PW-SP-2', 'Spodnie robocze do pasa', 'Portwest');
        CatalogManufacturerContext::forgetCache();

        $intent = $this->invokePrivate($this->app->make(ProductAiSearchService::class), 'localIntent', 'SPODNIE ROBOCZE DO PASA PROS');

        $this->assertSame('PROS', $intent['manufacturer']);
    }

    /**
     * Marka z konfiguracji albo zgadnięta przez model, której katalog nie ma jako producenta, ale ma w nazwach kart
     * („kask”, linia „MaxiCut” producenta ATG), to nie marka spoza katalogu. Od 23.09.2026 ratował je przypadkiem
     * producent „.....” (karty PLUM bez nazwy producenta): luźny etap aliasów porównywał z pustym zapisem jego nazwy.
     * Bez tego flaga „spoza katalogu” wycinała słowo z fraz i kroków — każde zapytanie o kask traciło „kask”.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function catalogLinesWithoutOwnManufacturer(): iterable
    {
        yield 'kask z zapytania' => ['Kask ochronny przemysłowy z regulacją', null];
        yield 'linia ATG z zapytania' => ['Rękawice MaxiCut 44-3745', null];
        yield 'linia ATG zgadnięta przez model' => ['Rękawice MaxiCut 44-3745', 'MaxiCut'];
    }

    #[DataProvider('catalogLinesWithoutOwnManufacturer')]
    public function test_brand_named_on_catalog_cards_is_not_absent_from_catalog(string $query, ?string $modelManufacturer): void
    {
        $this->card('JSP-KASK-1', 'Kask ochronny EVO3 z regulacją', 'JSP');
        $this->card('ATG-MC-1', 'Rękawice MaxiCut Ultra 44-3745', 'ATG');
        $this->card('PW-SP-2', 'Spodnie robocze do pasa', 'Portwest');
        CatalogManufacturerContext::forgetCache();

        $search = $this->app->make(ProductAiSearchService::class);
        $intent = $modelManufacturer === null
            ? $this->invokePrivate($search, 'localIntent', $query)
            : $this->invokePrivate($search, 'parseIntent', [
                'needed' => $query,
                'search_phrases' => [$query],
                'manufacturer' => $modelManufacturer,
            ], $query);

        $this->assertFalse($intent['manufacturer_absent_in_catalog'], 'marka z nazw kart oznaczona jako spoza katalogu');
        $this->assertNull($intent['manufacturer']);
        $this->assertNull($intent['manufacturer_requested']);
    }

    /**
     * Przegląd drugiego agenta (25.09.2026): marka spoza katalogu wymieniona pierwsza zostaje, choć dalsze słowo („delta”
     * — typ łącznika) trafia w początek nazwy producenta „Delta Plus”. Ten producent i tak odpada w
     * reconcileManufacturerIntent, bo jego nazwy nie ma w wymaganiu — zostawało wtedy nic zamiast marki spoza katalogu.
     */
    public function test_absent_brand_named_first_is_not_replaced_by_manufacturer_matched_by_first_word(): void
    {
        $this->card('DP-LAN-1', 'Linka bezpieczeństwa z amortyzatorem', 'Delta Plus');
        CatalogManufacturerContext::forgetCache();

        $intent = $this->invokePrivate($this->app->make(ProductAiSearchService::class), 'localIntent', 'Szelki bezpieczeństwa Cerva z łącznikiem delta');

        $this->assertTrue($intent['manufacturer_absent_in_catalog']);
        $this->assertSame('CERVA', $intent['manufacturer_requested']);
    }

    /** „prod. Bollé” ucinało się do „Boll” (wzorzec tylko ASCII), a „Boll” trafiało w „Bolle” wyłącznie jako podciąg. */
    public function test_manufacturer_after_prod_keeps_accented_letters(): void
    {
        $this->card('BOL-COBRA', 'Okulary ochronne Cobra', 'Bolle');
        CatalogManufacturerContext::forgetCache();

        $requested = $this->app->make(ProductMatchService::class)->requestedManufacturerFromSiwz('Okulary ochronne prod. Bollé, soczewka bezbarwna');

        $this->assertSame('Bollé', $requested);
        $this->assertSame('Bolle', $this->app->make(CatalogManufacturerContext::class)->matchManufacturer($requested));
    }

    /** Model oddaje nazwę z listy („Bolle”), a wymaganie pisze „Bollé” — producent stoi w treści wymagania. */
    public function test_catalog_name_without_accent_appears_in_accented_requirement(): void
    {
        $this->card('BOL-COBRA', 'Okulary ochronne Cobra', 'Bolle');
        CatalogManufacturerContext::forgetCache();

        $query = 'Okulary ochronne Bollé Cobra';
        $intent = $this->invokePrivate($this->app->make(ProductAiSearchService::class), 'parseIntent', [
            'needed' => 'okulary ochronne',
            'search_phrases' => ['okulary ochronne cobra'],
            'manufacturer' => 'Bolle',
        ], $query);

        $this->assertSame('Bolle', $intent['manufacturer']);
    }

    public function test_brand_missing_from_catalog_is_still_absent(): void
    {
        $this->card('PW-SP-2', 'Spodnie robocze do pasa', 'Portwest');
        CatalogManufacturerContext::forgetCache();

        $intent = $this->invokePrivate($this->app->make(ProductAiSearchService::class), 'localIntent', 'Obuwie robocze CERVA S3');

        $this->assertTrue($intent['manufacturer_absent_in_catalog']);
        $this->assertSame('CERVA', $intent['manufacturer_requested']);
    }

    /**
     * Słowa wielowyrazowej marki stoją osobno w nazwach różnych kart — to nie dowód, że katalog ma tę markę. Krótki człon
     * („KS”) też jest słowem: marka z nim nie staje się jednowyrazowa („Tools” w nazwie karty).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function multiWordAbsentBrands(): iterable
    {
        yield 'dwa zwykłe słowa' => ['Safety Jogger', 'Półbuty robocze Safety Jogger S3'];
        yield 'krótki człon marki' => ['KS Tools', 'Rękawice monterskie KS Tools'];
    }

    #[DataProvider('multiWordAbsentBrands')]
    public function test_multi_word_model_brand_with_common_words_is_still_absent(string $brand, string $query): void
    {
        $this->card('PW-JOG-1', 'Spodnie jogger robocze', 'Portwest');
        $this->card('TS-1', 'Szelki Safety Pro', 'PROTEKT');
        $this->card('TB-1', 'Skrzynka Tools Pro na narzędzia', 'Portwest');
        CatalogManufacturerContext::forgetCache();

        $intent = $this->invokePrivate($this->app->make(ProductAiSearchService::class), 'parseIntent', [
            'needed' => 'wyrób roboczy',
            'search_phrases' => ['wyrób roboczy'],
            'manufacturer' => $brand,
        ], $query);

        $this->assertTrue($intent['manufacturer_absent_in_catalog']);
        $this->assertSame($brand, $intent['manufacturer_requested']);
    }

    /**
     * Nazwa linii odrzucona w parseIntent („MaxiCut” — karty ATG) nie jest marką także po przepisaniu zapytania:
     * przepisanie wycinało ją z kroków („Rękawice MaxiCut Ultra” → „Rękawice Ultra”), a pierwsze szukanie ją zostawiało.
     */
    public function test_rewrite_keeps_catalog_line_name_in_steps_like_first_pass(): void
    {
        $this->card('ATG-MC-1', 'Rękawice MaxiCut Ultra 44-3745', 'ATG');
        $this->card('PW-SP-2', 'Spodnie robocze do pasa', 'Portwest');
        CatalogManufacturerContext::forgetCache();

        $search = $this->app->make(ProductAiSearchService::class);
        $query = 'Rękawice MaxiCut Ultra 44-3745 powlekane nitrylem';
        $understood = [
            'needed' => 'rękawice powlekane nitrylem',
            'search_phrases' => ['rękawice maxicut ultra'],
            'search_steps' => ['Rękawice MaxiCut Ultra'],
            'manufacturer' => 'MaxiCut',
        ];
        $searched = $this->invokePrivate($search, 'parseIntent', $understood, $query);
        $this->assertContains('Rękawice MaxiCut Ultra', $searched['search_steps']);

        $rewritten = $this->invokePrivate($search, 'rewriteSearchIntent', $query, $searched, [
            ...$understood,
            'search_steps' => ['Rękawice MaxiCut Ultra', 'powlekane nitrylem'],
        ]);

        $this->assertNotNull($rewritten, 'nowy krok to zmiana szukania');
        $this->assertContains('Rękawice MaxiCut Ultra', $rewritten['search_steps']);
    }

    private function card(string $sku, string $name, string $manufacturer): void
    {
        Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'category' => 'Odzież robocza',
            'description' => $name.'. Spodnie robocze z kieszeniami, tkanina mieszana.',
            'catalog_price_net' => 80,
            'purchase_price' => 40,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonth(),
        ]);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
