<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EnrichmentCancelledException;
use App\Exceptions\ManufacturerPageMissingException;
use App\Models\CatalogPage;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerCatalogPdf;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Etap 3 opisów z cenników (08.10.2026), przebieg wzbogacania — rdzeń (SUPON_AI_Plan_Etap3_Wspolne_poprawki_2026-10-08.md
 * §3, W1–W8): marka „tylko od producenta” bez sklepów (druga próba na hostach producenta, brak strony producenta →
 * manufacturer_missing, cofnięcie opisu ze sklepu przy force), najdłuższy kod marki decyduje o stronach, zdjęciach
 * i plikach, numer wpisu sklepu to nie kod, cechy bezpieczeństwa (kV) i sprawdzenie tekstu przed zapisem.
 * Atrapy: wyszukiwarka (Mockery), strony i pliki (Http::fake), model (Mockery — filtr stron oddaje tekst stron).
 */
final class EnrichmentStageThreeFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ROBOCZY = 'https://roboczystyl.pl/spodniobuty-antystatyczne-aj-group-sba01b';

    private const BEHAPOWNIA = 'https://behapownia.pl/spodniobuty-antystatyczne-sba01b';

    private const PROS_SBA01 = 'https://pros.pl/pl/spodniobuty/141-spodniobuty-antystatyczne-sba01.html';

    private const MAPA_SOLO = 'https://www.mapa-pro.pl/pl/rekawice/solo-987';

    private const BPBHP_SOLO = 'https://bpbhp.pl/rekawice-chemiczne-mapa-solo-987';

    private const SOLO_OLD = 'Rękawice chemiczne MAPA SOLO 987 z nitrylu, opis z poprzedniego pobrania ze sklepu. '
        .'Chronią dłonie przed rozpuszczalnikami i olejami podczas prac w warsztacie.';

    private const PROS_1011 = 'https://pros.pl/pl/plaszcze/104-plaszcz-przeciwdeszczowy-model-1011.html';

    private const PROS_1011R = 'https://pros.pl/pl/plaszcze/105-plaszcz-przeciwdeszczowy-model-1011-r.html';

    private const IMG_1011 = 'https://pros.pl/3879-large_default/plaszcz-przeciwdeszczowy-model-1011.jpg';

    private const IMG_1011R = 'https://pros.pl/3880-large_default/plaszcz-przeciwdeszczowy-model-1011-r.jpg';

    private const PDF_104 = 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=104';

    private const PDF_105 = 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=105';

    private const SECURA_3000 = 'https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/20-secura-3000.html';

    private const SECURA_3000_LAK = 'https://www.securabc.com/pl/produkty/67-zestaw-secura-3000-lak-blister.html';

    private const SECURA_SHOP = 'https://centrumelektronarzedzi.pl/pl/p/Polmaska-SECURA-3000-silikonowa-naglowie-jednoczesciowe-S56T0SM0/48399';

    private const SECURA_SHOP_HIT = ['url' => self::SECURA_SHOP, 'title' => 'Półmaska SECURA 3000 S56T0SM0', 'snippet' => 'S56T0SM0'];

    /** @var list<string> */
    private array $prompts = [];

    private int $modelCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        config()->set('enrichment.manufacturer_domains.testex', ['testex.example']);
    }

    /** (1) SBA01B: w wynikach tylko sklepy, druga próba („SBA01”) daje stronę pros.pl — model dostaje tylko ją, werdykt soft. */
    public function test_strict_brand_second_try_finds_manufacturer_page_under_other_code_form(): void
    {
        $product = $this->card('SBA01B', 'Spodniobuty antystatyczne SBA01B', 'AJ GROUP');
        $shop = fn (string $title): string => $this->html($title, 'Spodniobuty antystatyczne AJ GROUP SBA01B. '.str_repeat('Spodniobuty z PVC na podszewce poliestrowej, antystatyczne, z szelkami. ', 8));

        $error = $this->runEnrichment(
            $product,
            [
                ['url' => self::ROBOCZY, 'title' => 'Spodniobuty antystatyczne AJ GROUP SBA01B', 'snippet' => 'SBA01B'],
                ['url' => self::BEHAPOWNIA, 'title' => 'Spodniobuty SBA01B', 'snippet' => 'SBA01B AJ GROUP'],
            ],
            [
                self::ROBOCZY => $shop('Spodniobuty antystatyczne AJ GROUP SBA01B'),
                self::BEHAPOWNIA => $shop('Spodniobuty SBA01B AJ GROUP'),
                self::PROS_SBA01 => $this->html(
                    'Spodniobuty antystatyczne SBA01 - PROS',
                    'Spodniobuty antystatyczne SBA01 marki PROS. '.str_repeat('Spodniobuty wodoochronne z tkaniny PVC na podszewce poliestrowej, właściwości antystatyczne, regulowane szelki, buty z podnoskiem. ', 5)
                ),
            ],
            hostHits: ['SBA01' => [['url' => self::PROS_SBA01, 'title' => 'Spodniobuty antystatyczne SBA01', 'snippet' => '']]],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString(self::PROS_SBA01, $extraction, 'strona producenta z drugiej próby idzie do modelu');
        $this->assertStringNotContainsString('roboczystyl', $extraction, 'sklep nie idzie do modelu');
        $this->assertStringNotContainsString('behapownia', $extraction, 'sklep nie idzie do modelu');
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::PROS_SBA01, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame('soft', $product->enrichment_payload['identity']['verdict'] ?? null, json_encode($product->enrichment_payload['identity'] ?? null, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $product->review_reason);
        $this->assertStringContainsString('druga próba: kod SBA01', $this->trace($product));
    }

    /** (2) SOLO 987: strona producenta 404, sklep jest — model nie jest wołany, „manual” + manufacturer_missing; pozycja partii „manual”. */
    public function test_strict_brand_without_manufacturer_page_does_not_call_model(): void
    {
        $product = $this->card('987', 'Rękawice chemiczne MAPA SOLO 987', 'MAPA');

        $error = $this->runSolo($product, syncAs: User::factory()->create());

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error);
        $this->assertSame(0, $this->modelCalls, 'bez strony producenta model nie jest wołany (ani filtr stron)');
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame(Product::REVIEW_MANUFACTURER_MISSING, $product->review_reason);
        $this->assertNotNull($product->review_since);
        $this->assertStringStartsWith('Strony producenta nie znaleziono (hosty: mapa-pro.pl', (string) $product->enrichment_error);
        $this->assertStringContainsString('Wskaż adres w Do przeglądu', (string) $product->enrichment_error);
        $this->assertSame(
            ProductEnrichmentBatchItem::STATUS_MANUAL,
            ProductEnrichmentBatchItem::query()->where('product_id', $product->id)->value('status'),
            'pozycja partii jak przy braku źródeł'
        );
    }

    /** (2) Przy force opis ze sklepu wraca do historii wersji (decyzja właściciela 08.10.2026) — zdjęcie zostaje. */
    public function test_force_withdraws_shop_description_but_keeps_images(): void
    {
        $product = $this->soloWithOldDescription(self::BPBHP_SOLO);
        $image = $this->oldImage($product);

        $error = $this->runSolo($product, force: true);

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error);
        $this->assertSame(0, $this->modelCalls);
        $this->assertNull($product->description, 'opis ze sklepu cofnięty');
        $this->assertNull($product->norms);
        $this->assertSame(Product::REVIEW_MANUFACTURER_MISSING, $product->review_reason);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $version = ProductDescriptionVersion::query()->sole();
        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $version->status);
        $this->assertSame(self::SOLO_OLD, $version->description, 'tekst zostaje w historii');
        $this->assertSame([(int) $image->id], $product->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all(), 'zdjęć nie usuwamy');
        $this->assertStringContainsString('cofnięty do historii wersji', $this->trace($product));
    }

    /** (2) Opis ze strony producenta nie jest cofany. */
    public function test_force_keeps_description_from_manufacturer_page(): void
    {
        $product = $this->soloWithOldDescription('https://www.mapa-pro.pl/pl/rekawice/solo-987-stara');

        $error = $this->runSolo($product, force: true);

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error);
        $this->assertSame(self::SOLO_OLD, $product->description);
        $this->assertSame('EN 374', $product->norms);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::query()->sole()->status);
    }

    /** (3) Awaria wyszukiwarki: „failed”, bez cofania i bez powodu przeglądu. */
    public function test_search_outage_fails_without_withdrawal(): void
    {
        $product = $this->soloWithOldDescription(self::BPBHP_SOLO);

        $error = $this->runSolo($product, force: true, searchErrors: ['SearXNG: silniki zablokowane (captcha)']);

        $this->assertNotNull($error);
        $this->assertNotInstanceOf(ManufacturerPageMissingException::class, $error);
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->enrichment_status);
        $this->assertSame(self::SOLO_OLD, $product->description);
        $this->assertNull($product->review_reason);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::query()->sole()->status);
    }

    /**
     * (4) 1011: strona modelu 1011 R (karta 1011 R w katalogu marki) odpada z puli, zdjęcie „…-model-1011-r.jpg” i plik
     * wpisu 105 też; strona, zdjęcie i plik wpisu 104 zostają.
     */
    public function test_longer_code_of_another_card_drops_its_page_image_and_file(): void
    {
        $product = $this->card('1011', 'Płaszcz przeciwdeszczowy model 1011', 'AJ GROUP');
        $this->card('1011 R', 'Płaszcz przeciwdeszczowy model 1011 R', 'AJ GROUP');
        $text = static fn (string $model): string => 'Płaszcz przeciwdeszczowy PROS model '.$model.'. '
            .str_repeat('Płaszcz z tkaniny poliestrowej powlekanej PVC, szwy zgrzewane, kaptur chowany w kołnierzu, zapinany na napy. ', 5);

        $error = $this->runEnrichment(
            $product,
            [
                ['url' => self::PROS_1011, 'title' => 'Płaszcz przeciwdeszczowy model 1011', 'snippet' => ''],
                ['url' => self::PROS_1011R, 'title' => 'Płaszcz przeciwdeszczowy model 1011 R', 'snippet' => ''],
            ],
            [
                self::PROS_1011 => $this->html('Płaszcz przeciwdeszczowy model 1011 - PROS', $text('1011'), self::IMG_1011, [self::IMG_1011R], [self::PDF_104, self::PDF_105]),
                self::PROS_1011R => $this->html('Płaszcz przeciwdeszczowy model 1011 R - PROS', $text('1011 R'), self::IMG_1011R, [], [self::PDF_105]),
            ],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString('104-plaszcz-przeciwdeszczowy-model-1011.html', $extraction);
        $this->assertStringNotContainsString('105-plaszcz', $extraction, 'strona wariantu 1011 R nie idzie do modelu');
        $trace = $this->trace($product);
        $this->assertStringContainsString('plaszcz-przeciwdeszczowy-model-1011-r.jpg', $trace, 'zdjęcie wariantu odpada z wpisem w przebiegu');
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'model-1011-r.jpg'));
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'id_product=105'));
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'id_product=104'));
        $this->assertSame([self::IMG_1011], $product->images()->pluck('source_url')->all());
    }

    /** (5) 103: plik „pdf.php?id_product=103” przy stronie wpisu 64 to plik innego wpisu sklepu — odpada; wpis 64 zostaje. */
    public function test_file_with_shop_entry_number_of_another_page_is_dropped(): void
    {
        $product = $this->card('103', 'Kurtka przeciwdeszczowa model 103', 'AJ GROUP');
        $page = 'https://pros.pl/pl/kurtki/64-kurtka-przeciwdeszczowa-model-103.html';

        $error = $this->runEnrichment(
            $product,
            [['url' => $page, 'title' => 'Kurtka przeciwdeszczowa model 103', 'snippet' => '']],
            [$page => $this->html(
                'Kurtka przeciwdeszczowa model 103 - PROS',
                'Kurtka przeciwdeszczowa PROS model 103. '.str_repeat('Kurtka z tkaniny poliestrowej powlekanej PVC, szwy zgrzewane, kaptur, zapięcie na zamek i napy. ', 5),
                null,
                [],
                ['https://pros.pl/modules/x13producttopdf/pdf.php?id_product=103', 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=64'],
            )],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), 'id_product=103'));
        $this->assertStringContainsString('plik wpisu sklepu 103 spoza stron opisu', $this->trace($product));

        // plik wpisu tej strony (64) zostaje — ProductPageFetcher nie zawsze oddaje oba linki, więc sama reguła wprost
        $service = app(ProductEnrichmentService::class);
        $kept = (new ReflectionMethod($service, 'withoutForeignDocuments'))->invoke(
            $service,
            $product,
            ['https://pros.pl/modules/x13producttopdf/pdf.php?id_product=103', 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=64', 'https://cdn.example/karta-103.pdf'],
            [],
            [['url' => $page]],
        );
        $this->assertSame(['https://pros.pl/modules/x13producttopdf/pdf.php?id_product=64', 'https://cdn.example/karta-103.pdf'], $kept);
    }

    /** (6) CEDERROTH 51011013: plik z kodem innej karty marki (51011003) ze strony opisu odpada, własny zostaje. */
    public function test_file_named_with_another_card_code_of_the_brand_is_dropped(): void
    {
        $product = $this->card('51011013', 'Plaster Soft Foam Bandage 51011013', 'CEDERROTH');
        $this->card('51011003', 'Plaster Soft Foam Bandage 51011003', 'CEDERROTH');
        $page = 'https://www.cederroth.com/en/products/soft-foam-bandage-51011013';

        $error = $this->runEnrichment(
            $product,
            [['url' => $page, 'title' => 'Soft Foam Bandage 51011013', 'snippet' => '']],
            [$page => $this->html(
                'Soft Foam Bandage 51011013 - Cederroth',
                'Cederroth Soft Foam Bandage REF 51011013. '.str_repeat('Plaster z miękkiej pianki do opatrywania drobnych ran, łatwy do docięcia, oddychający, przylega do skóry. ', 5),
                null,
                [],
                ['https://www.cederroth.com/media/51011003-v03.pdf', 'https://www.cederroth.com/media/51011013-v03.pdf'],
            )],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '51011003-v03.pdf'));
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '51011013-v03.pdf'));
    }

    /** (7) T5912200 (30 kV): strona sklepu „…20-kv…T5912100” odpada (sprzeczna cecha), zostaje securabc z „Indeks”; zdjęcie „-20kv” odpada. */
    public function test_conflicting_voltage_drops_shop_page_and_image(): void
    {
        $product = $this->card('T5912200', 'Półbuty elektroizolacyjne 30 kV T5912200', 'SECURA');
        $shop = 'https://mistralbhp.pl/polbuty-elektroizolacyjne-secura-20-kv-t5912100.html';
        $mfr = 'https://www.securabc.com/57-polbuty-elektroizolacyjne.html';

        $error = $this->runEnrichment(
            $product,
            [
                ['url' => $shop, 'title' => 'Półbuty elektroizolacyjne SECURA 20 kV T5912100', 'snippet' => 'T5912200'],
                ['url' => $mfr, 'title' => 'Półbuty elektroizolacyjne - SECURA', 'snippet' => 'Indeks: T5912200'],
            ],
            [
                $shop => $this->html('Półbuty elektroizolacyjne SECURA 20 kV T5912100', 'Półbuty elektroizolacyjne SECURA T5912100 (zamiennik T5912200) do 20 kV. '.str_repeat('Obuwie dielektryczne z gumy, podeszwa antypoślizgowa. ', 8)),
                $mfr => $this->html(
                    'Półbuty elektroizolacyjne - SECURA',
                    'Indeks: T5912200. Półbuty elektroizolacyjne SECURA do pracy pod napięciem 30 kV. '.str_repeat('Obuwie dielektryczne z gumy naturalnej, badane napięciem probierczym, podeszwa antypoślizgowa. ', 5),
                    'https://www.securabc.com/img/polbuty-elektroizolacyjne-30kv.jpg',
                    ['https://www.securabc.com/img/polbuty-elektroizolacyjne-20kv.jpg'],
                ),
            ],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString($mfr, $extraction);
        $this->assertStringNotContainsString('mistralbhp', $extraction);
        $trace = $this->trace($product);
        $this->assertStringContainsString('sprzeczna cecha', $trace);
        $this->assertStringContainsString('polbuty-elektroizolacyjne-20kv.jpg', $trace);
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '-20kv.jpg'));
    }

    /** (7) Bez reguł „tylko producent” sklep z „20 kV” przy karcie 30 kV i tak odpada — sama bramka cech (W1). */
    public function test_conflicting_voltage_drops_shop_page_for_any_brand(): void
    {
        config(['enrichment.manufacturer_only_sources' => [], 'enrichment.manufacturer_first_every_brand' => false]);
        $product = $this->card('T5912200', 'Półbuty elektroizolacyjne 30 kV T5912200', 'SECURA');
        $shop = 'https://mistralbhp.pl/polbuty-elektroizolacyjne-secura-20-kv-t5912200.html';
        $other = 'https://sklep-bhp.example/polbuty-elektroizolacyjne-secura-t5912200.html';
        $text = static fn (string $kv): string => 'Półbuty elektroizolacyjne SECURA T5912200 do '.$kv.'. '
            .str_repeat('Obuwie dielektryczne z gumy naturalnej, badane napięciem probierczym, podeszwa antypoślizgowa. ', 6);

        $error = $this->runEnrichment(
            $product,
            [
                ['url' => $shop, 'title' => 'Półbuty elektroizolacyjne SECURA 20 kV T5912200', 'snippet' => 'T5912200'],
                ['url' => $other, 'title' => 'Półbuty elektroizolacyjne SECURA T5912200', 'snippet' => 'T5912200'],
            ],
            [
                $shop => $this->html('Półbuty elektroizolacyjne SECURA 20 kV T5912200', $text('20 kV')),
                $other => $this->html('Półbuty elektroizolacyjne SECURA T5912200', $text('30 kV')),
            ],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString($other, $extraction, 'strona bez cechy w adresie i tytule przechodzi');
        $this->assertStringNotContainsString('mistralbhp', $extraction, 'strona 20 kV przy karcie 30 kV odpada');

        // cecha tylko w tytule (adres bez kV) też się liczy — suma cech adresu i tytułu, nie unia kluczy tablic
        $service = app(ProductEnrichmentService::class);
        $kept = (new ReflectionMethod($service, 'withoutForeignOrConflictingPages'))->invoke($service, $product, [
            ['url' => 'https://sklep.example/polbuty-secura-a.html', 'title' => 'Półbuty elektroizolacyjne SECURA 20 kV', 'text' => 'x'],
            ['url' => 'https://sklep.example/polbuty-secura-b.html', 'title' => 'Półbuty elektroizolacyjne SECURA 30 kV', 'text' => 'x'],
        ]);
        $this->assertSame(['https://sklep.example/polbuty-secura-b.html'], array_column($kept, 'url'));
    }

    /**
     * (8) Tekst przed zapisem: „<0,5%” w całości, zdanie powtarzające polecenie (Harpon 330) wycięte do
     * dropped_meta_sentences, urwany koniec obcięty; pole norm bez 13999 z literą, gauge, kategorii i typu 374-1
     * sprzecznego z literami.
     */
    public function test_description_text_and_norm_field_are_checked_before_saving(): void
    {
        $product = $this->card('TX4521', 'Rękawice montażowe Testex TX4521', 'Testex');
        $page = 'https://testex.example/produkty/rekawice-tx4521';
        $norms = ['EN ISO 13999 D', 'EN 388 15 gauge', 'Kategoria 2', 'EN ISO 374-1 Type B JKLOPT'];
        $description = 'Rękawice montażowe Testex TX4521 z dzianiny nylonowej powlekanej nitrylem. '
            .'Pozostałość rozpuszczalnika: <0,5% masy powłoki, co ogranicza zapach. '
            .'Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje właściwości rękawic, co jest zgodne z wymaganiami. '
            .'Rękawice chronią dłonie przy pracach montażowych i magazynowych.'
            ."\n\nWkładka z pianki chroni grzbiet dłoni. Pochłania energię (średnia siła uderzenia";

        $error = $this->runEnrichment(
            $product,
            [['url' => $page, 'title' => 'Rękawice montażowe Testex TX4521', 'snippet' => '']],
            [$page => $this->html(
                'Rękawice montażowe Testex TX4521',
                'Rękawice montażowe Testex TX4521. Pozostałość rozpuszczalnika: <0,5% masy powłoki. Normy: EN ISO 13999 D, EN 388 15 gauge, '
                    .'Kategoria 2, EN ISO 374-1 Type B JKLOPT. '.str_repeat('Dzianina nylonowa powlekana nitrylem, wkładka z pianki na grzbiecie dłoni. ', 6)
            )],
            extraction: ['description' => $description, 'norms' => $norms, 'attributes' => ['normy_en' => $norms]],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $saved = (string) $product->description;
        $this->assertStringContainsString('<0,5% masy powłoki, co ogranicza zapach', $saved, '„<” bez znacznika nie zjada tekstu');
        $this->assertStringNotContainsString('kategorii PPE', $saved);
        $this->assertStringNotContainsString('siła uderzenia', $saved, 'urwany koniec obcięty');
        $this->assertStringContainsString('Wkładka z pianki chroni grzbiet dłoni.', $saved);

        $payload = (array) $product->enrichment_payload;
        $this->assertStringContainsString('kategorii PPE', implode(' | ', (array) ($payload['dropped_meta_sentences'] ?? [])));
        $savedNorms = implode(' | ', (array) ($payload['norms'] ?? []));
        $this->assertContains('EN 388', (array) ($payload['norms'] ?? []), $savedNorms);
        foreach (['13999', 'gauge', 'Kategoria', 'JKLOPT'] as $gone) {
            $this->assertStringNotContainsString($gone, $savedNorms);
        }
        $dropped = implode(' | ', (array) ($payload['dropped_norm_claims'] ?? []));
        $this->assertStringContainsString('pole norm: ', $dropped);
        $this->assertStringContainsString('13999', $dropped);
        $this->assertStringContainsString('JKLOPT', $dropped);
    }

    /** (8) „Kategoria 2” z pola norm trafia do certyfikatów, echa polecenia z list do dropped_meta_sentences. */
    public function test_norm_list_sanity_moves_category_to_certificates(): void
    {
        $service = app(ProductEnrichmentService::class);
        $result = (new ReflectionMethod($service, 'withSaneNormLists'))->invoke($service, [
            'norms' => ['EN ISO 13999 D', 'EN 388 15 gauge', 'Kategoria 2', 'EN ISO 374-1 Type B JKLOPT'],
            'certificates' => ['Certyfikat badania typu UE'],
            'features' => ['Rękawice nitrylowe', 'Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje rękawice'],
            'attributes' => ['normy_en' => ['EN ISO 388', 'Kategoria 2']],
        ]);

        $this->assertSame(['EN 388'], $result['extracted']['norms']);
        $this->assertSame(['EN 388'], $result['extracted']['attributes']['normy_en']);
        $this->assertContains('Kategoria 2', $result['extracted']['certificates']);
        $this->assertContains('Certyfikat badania typu UE', $result['extracted']['certificates']);
        $this->assertSame(['Rękawice nitrylowe'], $result['extracted']['features']);
        $this->assertCount(1, $result['dropped_meta_sentences']);
        foreach ($result['dropped_norm_claims'] as $note) {
            $this->assertStringStartsWith('pole norm: ', $note);
        }
    }

    /** (8) „Pochłaniacz 3033 E2” z opisem tylko o klasie A2 — opis odrzucony, karta do ręki. */
    public function test_description_with_gas_class_contradicting_card_name_is_rejected(): void
    {
        $product = $this->card('3033', 'Pochłaniacz 3033 E2', 'Testex');
        $page = 'https://testex.example/produkty/pochlaniacz-3033';

        $error = $this->runEnrichment(
            $product,
            [['url' => $page, 'title' => 'Pochłaniacz Testex 3033', 'snippet' => '']],
            [$page => $this->html('Pochłaniacz Testex 3033', 'Pochłaniacz Testex 3033 do półmasek. '.str_repeat('Pochłaniacz chroni drogi oddechowe przed gazami, mocowanie bagnetowe, obudowa z tworzywa. ', 12))],
            extraction: ['description' => 'Pochłaniacz Testex 3033 do półmasek z mocowaniem bagnetowym. Klasa A2 chroni przed gazami i parami związków organicznych o temperaturze wrzenia powyżej 65°C. Obudowa z tworzywa sztucznego.'],
        );

        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status, (string) ($error?->getMessage() ?? $product->enrichment_error));
        $this->assertNull($product->description);
        $this->assertStringContainsString('opis sprzeczny z nazwą karty', $this->trace($product));
    }

    /**
     * Runda 2 (1): przy force brak strony producenta nie uruchamia starego czyszczenia „cudzego opisu” — opis z katalogu
     * PDF producenta, który nie nazywa marki ani kodu, zostaje razem z payloadem, normami, zdjęciem i plikiem.
     */
    public function test_force_without_manufacturer_page_keeps_catalog_description_and_files(): void
    {
        $catalog = 'https://www.mapa-pro.pl/media/katalog-mapa-2026.pdf';
        config()->set('enrichment.manufacturer_catalogs.mapa', [$catalog]);
        $text = 'Wyrób z nitrylu o długości 33 cm, wnętrze flokowane bawełną. Odporny na rozpuszczalniki i oleje w pracy warsztatowej.';
        $product = $this->card('987', 'Rękawice chemiczne MAPA SOLO 987', 'MAPA', [
            'description' => $text,
            'norms' => 'EN 374',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['norms' => ['EN 374'], 'primary_source_url' => $catalog, 'primary_source_kind' => 'manufacturer', 'source_urls' => [$catalog]],
        ]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $text,
            'primary_source_url' => $catalog,
            'identity_verdict' => 'hard',
            'evidence_count' => 1,
        ]);
        $product->refresh();
        $this->assertFalse(app(ProductEnrichmentService::class)->descriptionMentionsProduct($text, $product), 'warunek starego bloku: opis nie nazywa wyrobu');
        $image = $this->oldImage($product);
        Storage::disk('public')->put('products/'.$product->id.'/karta.pdf', '%PDF-1.4');
        $document = ProductDocument::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/karta.pdf',
            'source_url' => 'https://www.mapa-pro.pl/media/solo-987.pdf',
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
        ]);

        $error = $this->runSolo($product, force: true);

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error);
        $this->assertSame($text, $product->description);
        $this->assertSame('EN 374', $product->norms);
        $this->assertSame($catalog, $product->enrichment_payload['primary_source_url'] ?? null, 'payload zostaje');
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::query()->sole()->status);
        $this->assertSame([(int) $image->id], $product->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertTrue(ProductDocument::query()->whereKey($document->id)->exists(), 'plik zostaje');
        Storage::disk('public')->assertExists('products/'.$product->id.'/karta.pdf');
        Storage::disk('public')->assertExists('products/'.$product->id.'/stare.jpg');
        $this->assertSame(Product::REVIEW_MANUFACTURER_MISSING, $product->review_reason);
    }

    /**
     * Runda 2 (2): pierwsza pula ma tylko stronę wariantu 1011 R — W1 opróżnia ją jeszcze przed rundami szukania, więc
     * kolejna partia indeksu daje właściwą stronę modelu 1011 i opis powstaje z niej.
     */
    public function test_pool_emptied_by_foreign_page_runs_further_search_rounds(): void
    {
        $product = $this->card('1011', 'Płaszcz przeciwdeszczowy model 1011', 'AJ GROUP');
        $this->card('1011 R', 'Płaszcz przeciwdeszczowy model 1011 R', 'AJ GROUP');
        $text = static fn (string $model): string => 'Płaszcz przeciwdeszczowy PROS model '.$model.'. '
            .str_repeat('Płaszcz z tkaniny poliestrowej powlekanej PVC, szwy zgrzewane, kaptur chowany w kołnierzu, zapinany na napy. ', 5);

        $error = $this->runEnrichment(
            $product,
            [['url' => self::PROS_1011R, 'title' => 'Płaszcz przeciwdeszczowy model 1011 R', 'snippet' => '']],
            [
                self::PROS_1011 => $this->html('Płaszcz przeciwdeszczowy model 1011 - PROS', $text('1011')),
                self::PROS_1011R => $this->html('Płaszcz przeciwdeszczowy model 1011 R - PROS', $text('1011 R')),
            ],
            moreCatalogHits: [['url' => self::PROS_1011, 'title' => 'Płaszcz przeciwdeszczowy model 1011', 'snippet' => '']],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString('104-plaszcz-przeciwdeszczowy-model-1011.html', $extraction);
        $this->assertStringNotContainsString('105-plaszcz', $extraction);
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::PROS_1011, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertStringContainsString('indeks: partia 1', $this->trace($product));
    }

    /**
     * Runda 2 (3): SBA01B z opublikowanym opisem z roboczystyl (werdykt hard), druga próba daje pros.pl (soft) — opis
     * ze sklepu wraca do historii jako cofnięty przed decyzją, opis producenta jest publikowany (nie propozycją).
     */
    public function test_strict_brand_manufacturer_page_replaces_published_shop_description(): void
    {
        $old = 'Spodniobuty antystatyczne AJ GROUP SBA01B z PVC na podszewce poliestrowej — opis z poprzedniego pobrania ze sklepu roboczystyl.';
        $product = $this->card('SBA01B', 'Spodniobuty antystatyczne SBA01B', 'AJ GROUP', [
            'description' => $old,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['primary_source_url' => self::ROBOCZY, 'primary_source_kind' => 'shop', 'source_urls' => [self::ROBOCZY]],
        ]);
        $previous = app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $old,
            'primary_source_url' => self::ROBOCZY,
            'identity_verdict' => 'hard',
            'evidence_count' => 0,
        ]);
        $product->refresh();

        $error = $this->runEnrichment(
            $product,
            [['url' => self::ROBOCZY, 'title' => 'Spodniobuty antystatyczne AJ GROUP SBA01B', 'snippet' => 'SBA01B']],
            [
                self::ROBOCZY => $this->html('Spodniobuty antystatyczne AJ GROUP SBA01B', 'Spodniobuty antystatyczne AJ GROUP SBA01B. '.str_repeat('Spodniobuty z PVC na podszewce poliestrowej, antystatyczne, z szelkami. ', 8)),
                self::PROS_SBA01 => $this->html(
                    'Spodniobuty antystatyczne SBA01 - PROS',
                    'Spodniobuty antystatyczne SBA01 marki PROS. '.str_repeat('Spodniobuty wodoochronne z tkaniny PVC na podszewce poliestrowej, właściwości antystatyczne, regulowane szelki, buty z podnoskiem. ', 5)
                ),
            ],
            force: true,
            hostHits: ['SBA01' => [['url' => self::PROS_SBA01, 'title' => 'Spodniobuty antystatyczne SBA01', 'snippet' => '']]],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::PROS_SBA01, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertNotSame($old, $product->description);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $product->review_reason, 'opis producenta opublikowany z werdyktem soft, nie propozycja');
        $previous->refresh();
        $this->assertSame(ProductDescriptionVersion::STATUS_SUPERSEDED, $previous->status);
        $this->assertStringContainsString('opis ze sklepu zastąpiony stroną producenta', (string) $previous->reason);
        $meta = app(DescriptionVersionStore::class)->meta($previous);
        $this->assertSame('opis ze sklepu zastąpiony stroną producenta', $meta['withdrawn_reason'] ?? null);
        $this->assertNotEmpty($meta['withdrawn_at'] ?? null);
        $this->assertSame($old, $previous->description, 'tekst sklepu zostaje w historii');
        $this->assertSame(0, ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->count());
        $published = ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)->sole();
        $this->assertSame(self::PROS_SBA01, $published->primary_source_url);
    }

    /** Runda 2 (4): marka ścisła bierze wpis pamięci SKU tylko ze źródłem producenta — wpis ze sklepu jest pomijany. */
    public function test_strict_brand_takes_sku_cache_only_from_manufacturer_source(): void
    {
        $product = $this->card('987', 'Rękawice chemiczne MAPA SOLO 987', 'MAPA');
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('MAPA', '987'),
            'description' => self::SOLO_OLD,
            'enrichment_payload' => ['norms' => [], 'confidence' => 0.9, 'features' => [], 'specs' => [], 'primary_source_url' => self::BPBHP_SOLO],
            'image_urls' => [],
            'source_urls' => [self::BPBHP_SOLO],
        ]);

        $error = $this->runSolo($product);

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error, 'wpis ze sklepu nie omija braku strony producenta');
        $this->assertNull($product->description);
        $this->assertStringContainsString('pamięć SKU pominięta — marka tylko od producenta', $this->trace($product));

        // wpis ze strony producenta — brany jak dotąd
        $other = $this->card('988', 'Rękawice chemiczne MAPA SOLO 988', 'MAPA');
        $mapaPage = 'https://www.mapa-pro.pl/pl/rekawice/solo-988';
        ProductEnrichmentCache::query()->create([
            ...ProductEnrichmentCache::normalizeKey('MAPA', '988'),
            'description' => 'Rękawice chemiczne MAPA SOLO 988 z nitrylu, wnętrze flokowane bawełną. Chronią dłonie przed rozpuszczalnikami i olejami.',
            'enrichment_payload' => ['norms' => [], 'confidence' => 0.9, 'features' => [], 'specs' => [], 'primary_source_url' => $mapaPage],
            'image_urls' => [],
            'source_urls' => [$mapaPage],
        ]);

        $this->assertNull($this->runEnrichment($other, [], []));
        $this->assertSame(Product::ENRICHMENT_DONE, $other->enrichment_status, (string) $other->enrichment_error);
        $this->assertSame(ProductDescriptionVersion::ORIGIN_SKU_CACHE, ProductDescriptionVersion::query()->where('product_id', $other->id)->sole()->origin);
    }

    /**
     * Runda 2 (8): brak strony producenta nie nadpisuje powodu przeglądu karty z czekającą propozycją (worse_version).
     */
    public function test_manufacturer_missing_keeps_review_reason_of_waiting_proposal(): void
    {
        $this->assertWaitingProposalKept(force: false);
    }

    /** Runda 2 (8): to samo przy force, gdy opis ze sklepu wraca do historii (magazyn wersji ustawia manufacturer_missing). */
    public function test_manufacturer_missing_with_withdrawal_keeps_review_reason_of_waiting_proposal(): void
    {
        $this->assertWaitingProposalKept(force: true);
        $this->assertStringContainsString('cofnięty do historii wersji', $this->trace(Product::query()->where('sku', '987')->sole()));
    }

    private function assertWaitingProposalKept(bool $force): void
    {
        $product = $this->soloWithOldDescription(self::BPBHP_SOLO);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => 'Rękawice chemiczne MAPA SOLO 987 — propozycja opisu słabsza od obecnego, czeka na decyzję handlowca w przeglądzie.',
            'primary_source_url' => 'https://inny-sklep.example/mapa-solo-987',
            'identity_verdict' => 'soft',
            'review_reason' => Product::REVIEW_WORSE_VERSION,
            'reason' => 'tożsamość strony słabsza niż w obecnym opisie',
        ]);
        $since = now()->subDays(2)->startOfSecond();
        // bez force karta „gotowa” jest pomijana — w kolejce jak przy ponownym pobraniu z partii
        $product->update(['review_reason' => Product::REVIEW_WORSE_VERSION, 'review_since' => $since, 'enrichment_status' => $force ? Product::ENRICHMENT_DONE : Product::ENRICHMENT_QUEUED]);

        $error = $this->runSolo($product, force: $force);

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error, (string) $error?->getMessage());
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $product->review_reason);
        $this->assertSame($since->toDateTimeString(), $product->review_since?->toDateTimeString());
        $this->assertSame(1, ProductDescriptionVersion::query()->where('status', ProductDescriptionVersion::STATUS_PROPOSED)->count());
    }

    /**
     * Klucze stanu karty (DescriptionVersionStore::CARD_STATE_PAYLOAD_KEYS — merged_size_skus czyta ProductSizeMergeService)
     * zostają w payloadzie karty po zapisie nowego opisu; payload przebiegu budowany od zera ich nie kasuje, a klucz opisu
     * (stare źródło) jest zastępowany. Ślad opisu z B2B (b2b_sources) należy do starego opisu i znika — inaczej
     * AuditSourceIdentityCommand uznałby kartę za „opis z PDF B2B”.
     */
    public function test_card_state_payload_keys_survive_new_description(): void
    {
        $product = $this->card('TX4521', 'Rękawice montażowe Testex TX4521', 'Testex', [
            'enrichment_payload' => [
                'merged_size_skus' => ['TX4521-9', 'TX4521-10'],
                'b2b_sources' => ['konto' => 3],
                'primary_source_url' => 'https://stary-sklep.example/tx4521',
            ],
        ]);
        $page = 'https://testex.example/produkty/rekawice-tx4521';

        $error = $this->runEnrichment(
            $product,
            [['url' => $page, 'title' => 'Rękawice montażowe Testex TX4521', 'snippet' => '']],
            [$page => $this->html('Rękawice montażowe Testex TX4521', 'Rękawice montażowe Testex TX4521. '.str_repeat('Dzianina nylonowa powlekana nitrylem, wkładka z pianki na grzbiecie dłoni. ', 12))],
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $payload = (array) $product->enrichment_payload;
        $this->assertSame(['TX4521-9', 'TX4521-10'], $payload['merged_size_skus'] ?? null);
        $this->assertArrayNotHasKey('b2b_sources', $payload);
        $this->assertSame($page, $payload['primary_source_url'] ?? null, 'klucze opisu z nowego przebiegu');
        $this->assertSame((int) ProductDescriptionVersion::query()->sole()->id, $payload['description_version_id'] ?? null);
    }

    /**
     * Runda 3: wersja ze sklepu ustępuje dopiero w transakcji zapisu. Partia anulowana po znalezieniu strony producenta
     * (przy pobieraniu zdjęcia, przed zapisem opisu) — karta zostaje z opisem, normami i wersją jak przed przebiegiem.
     */
    public function test_cancel_after_manufacturer_page_found_keeps_shop_description(): void
    {
        $old = 'Spodniobuty antystatyczne AJ GROUP SBA01B z PVC na podszewce poliestrowej — opis z poprzedniego pobrania ze sklepu roboczystyl.';
        $product = $this->card('SBA01B', 'Spodniobuty antystatyczne SBA01B', 'AJ GROUP', [
            'description' => $old,
            'norms' => 'EN 13832-3',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['primary_source_url' => self::ROBOCZY, 'primary_source_kind' => 'shop', 'source_urls' => [self::ROBOCZY]],
        ]);
        $previous = app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => $old,
            'primary_source_url' => self::ROBOCZY,
            'identity_verdict' => 'hard',
            'evidence_count' => 0,
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => 14, 'total' => 1, 'done' => 0, 'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'created_by' => User::factory()->create()->id, 'force' => true,
        ]);
        // użytkownik anuluje partię, gdy przebieg pobiera już zdjęcie ze strony producenta
        ProductImage::created(static function () use ($batch): void {
            $batch->markCancelledFlag();
        });
        $image = 'https://pros.pl/1201-large_default/spodniobuty-antystatyczne-sba01.jpg';

        $error = $this->runEnrichment(
            $product->refresh(),
            [['url' => self::ROBOCZY, 'title' => 'Spodniobuty antystatyczne AJ GROUP SBA01B', 'snippet' => 'SBA01B']],
            [
                self::ROBOCZY => $this->html('Spodniobuty antystatyczne AJ GROUP SBA01B', 'Spodniobuty antystatyczne AJ GROUP SBA01B. '.str_repeat('Spodniobuty z PVC na podszewce poliestrowej, antystatyczne, z szelkami. ', 8)),
                self::PROS_SBA01 => $this->html(
                    'Spodniobuty antystatyczne SBA01 - PROS',
                    'Spodniobuty antystatyczne SBA01 marki PROS. '.str_repeat('Spodniobuty wodoochronne z tkaniny PVC na podszewce poliestrowej, właściwości antystatyczne, regulowane szelki, buty z podnoskiem. ', 5),
                    $image,
                ),
            ],
            force: true,
            hostHits: ['SBA01' => [['url' => self::PROS_SBA01, 'title' => 'Spodniobuty antystatyczne SBA01', 'snippet' => '']]],
            batchId: (int) $batch->id,
        );

        $this->assertInstanceOf(EnrichmentCancelledException::class, $error, (string) $error?->getMessage().' '.$this->trace($product));
        $this->assertSame($old, $product->description);
        $this->assertSame('EN 13832-3', $product->norms);
        $this->assertNull($product->review_reason, 'bez fałszywego manufacturer_missing');
        $this->assertSame(self::ROBOCZY, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $previous->fresh()->status);
        $this->assertSame(1, ProductDescriptionVersion::query()->count());
    }

    // ── pomocnicze ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Przegląd SECURA 08.10.2026 (partia #502): w wynikach tylko sklep, strona półmaski securabc.com nie ma kodu w adresie
     * („20-secura-3000.html”), a indeks dla „SECURA 3000” podawał zestawy. Druga próba bierze strony producenta z indeksu
     * po słowach nazwy; pole „Indeks S56T0SM0” potwierdza wyrób (hard), strona zestawu z innym indeksem odpada.
     */
    public function test_strict_brand_takes_manufacturer_page_without_code_in_url_from_index(): void
    {
        $product = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'SECURA');
        $this->indexSecuraPages();

        $error = $this->runEnrichment($product, [self::SECURA_SHOP_HIT], $this->securaPages());

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString(self::SECURA_3000, $extraction);
        $this->assertStringNotContainsString('centrumelektronarzedzi', $extraction, 'sklep nie idzie do modelu');
        $this->assertStringNotContainsString('67-zestaw', $extraction, 'strona zestawu nie idzie do modelu');
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(self::SECURA_3000, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame('hard', $product->enrichment_payload['identity']['verdict'] ?? null);
        $this->assertNull($product->review_reason);
        $this->assertStringContainsString('strony producenta z indeksu', $this->trace($product));
    }

    /**
     * Partia #502, S56T0SM0: wyszukiwarka dała stronę zestawu na securabc.com — liczyła się jako karta producenta w puli,
     * więc druga próba nie ruszała. Pole „Indeks” zestawu (kod spoza katalogu marki) odsiewa ją teraz jako stronę innego
     * wyrobu, a druga próba bierze stronę półmaski z indeksu.
     */
    public function test_manufacturer_kit_page_from_search_does_not_block_index_page(): void
    {
        $product = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'SECURA');
        $this->indexSecuraPages();

        $error = $this->runEnrichment(
            $product,
            [['url' => self::SECURA_3000_LAK, 'title' => 'Zestaw SECURA 3000 LAK blister', 'snippet' => 'SECURA 3000']],
            $this->securaPages()
        );

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString(self::SECURA_3000, $extraction);
        $this->assertStringNotContainsString('67-zestaw', $extraction, 'strona zestawu nie idzie do modelu');
        $this->assertSame(self::SECURA_3000, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame('hard', $product->enrichment_payload['identity']['verdict'] ?? null);
    }

    /** Ten sam model w innym rozmiarze (S56T0SS0 przy „Indeks S56T0SM0”): strona wchodzi jako soft, karta do przeglądu. */
    public function test_index_page_of_other_size_enters_as_soft(): void
    {
        $product = $this->card('S56T0SS0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'SECURA');
        $this->indexSecuraPages();

        $error = $this->runEnrichment($product, [self::SECURA_SHOP_HIT], $this->securaPages());

        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertSame(self::SECURA_3000, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame('soft', $product->enrichment_payload['identity']['verdict'] ?? null);
        $this->assertSame(Product::REVIEW_IDENTITY_SOFT, $product->review_reason);
    }

    /** W indeksie tylko strona zestawu (inny „Indeks”) — nie wchodzi, przebieg kończy się brakiem strony producenta. */
    public function test_index_page_of_another_product_is_not_taken(): void
    {
        $product = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'SECURA');
        $this->indexPages([self::SECURA_3000_LAK => 'Zestaw SECURA 3000 LAK blister']);

        $error = $this->runEnrichment($product, [], $this->securaPages());

        $this->assertInstanceOf(ManufacturerPageMissingException::class, $error);
        $this->assertSame(Product::REVIEW_MANUFACTURER_MISSING, $product->review_reason);
        $trace = $this->trace($product);
        $this->assertStringContainsString('strony bez potwierdzenia wyrobu', $trace);
        $this->assertStringContainsString('67-zestaw-secura-3000-lak-blister', $trace);
    }

    /**
     * Bez wyników wyszukiwarki, z blokiem katalogu PDF producenta (kilka wierszy pozycji katalogu): druga próba bierze stronę
     * producenta z indeksu i idzie ona do modelu obok bloku — półmaski SECURA dostawały same 120 znaków z katalogu
     * i odpadały jako „za krótki opis”.
     */
    public function test_catalog_block_does_not_stop_second_try_on_manufacturer_hosts(): void
    {
        Storage::fake('local');
        $catalog = 'https://www.securabc.com/img/cms/Katalog%202026%20PL_web.pdf';
        // blok pozycji z opisem ponad 400 znaków — dawniej liczył się jak karta producenta (officialCardInPool)
        Storage::disk('local')->put(
            ManufacturerCatalogPdf::CACHE_DIR.'/'.sha1(mb_strtolower($catalog)).'.txt',
            'PÓŁMASKI

S56T0SM0 Półmaska SECURA 3000 z silikonową częścią twarzową i jednoczęściowym nagłowiem, rozmiar M Szt
'
                .str_repeat('Półmaska wielokrotnego użytku chroni układ oddechowy przed aerozolami, parami i gazami po skompletowaniu z elementami oczyszczającymi. ', 4)
                .'

S56T0SL0 Półmaska SECURA 3000 rozmiar L Szt
'
        );
        $product = $this->card('S56T0SM0', 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'SECURA');
        $this->indexSecuraPages();

        $error = $this->runEnrichment($product, [], $this->securaPages());

        $this->assertNull($error, (string) $error?->getMessage());
        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString(self::SECURA_3000, $extraction, 'strona producenta z drugiej próby');
        $this->assertStringContainsString('strony producenta z indeksu', $this->trace($product));
        $this->assertSame('hard', $product->enrichment_payload['identity']['verdict'] ?? null);
    }

    private function indexSecuraPages(): void
    {
        $this->indexPages([
            self::SECURA_3000_LAK => 'Zestaw SECURA 3000 LAK blister',
            self::SECURA_3000 => 'SECURA 3000',
        ]);
    }

    /** @param  array<string, string>  $pages  adres => tytuł */
    private function indexPages(array $pages): void
    {
        $now = now();
        $rows = [];
        foreach ($pages as $url => $title) {
            $rows[] = [
                'host' => 'securabc.com', 'manufacturer' => null, 'url_hash' => CatalogPage::hashFor($url),
                'url' => $url, 'title' => $title, 'haystack' => mb_strtolower($url.' '.$title), 'last_seen_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('catalog_pages')->insert($rows);
        app(CatalogSitemapIndexer::class)->storeTokens(array_column($rows, 'url_hash'));
    }

    /** @return array<string, string> */
    private function securaPages(): array
    {
        $mask = 'Półmaska SECURA 3000 po skompletowaniu z odpowiednimi elementami oczyszczającymi stanowi sprzęt ochronny układu '
            .'oddechowego. '.str_repeat('Półmaska z silikonową częścią twarzową, dwoma zaworami wdechowymi ze złączami bagnetowymi, zaworem wydechowym i nagłowiem tekstylnym. ', 5);

        return [
            self::SECURA_SHOP => $this->html('Półmaska SECURA 3000 S56T0SM0', 'Półmaska SECURA 3000 S56T0SM0 w sklepie. '.str_repeat('Półmaska wielokrotnego użytku z silikonu, rozmiar M. ', 8)),
            self::SECURA_3000 => $this->securaPage('SECURA 3000', 'S56T0SM0', $mask),
            self::SECURA_3000_LAK => $this->securaPage('Zestaw SECURA 3000 LAK blister', 'S5703LAK0', 'Zestaw lakierniczy SECURA 3000 LAK w blistrze: półmaska, pochłaniacze i filtry. '.str_repeat('Zestaw do prac lakierniczych z półmaską i elementami oczyszczającymi. ', 6)),
        ];
    }

    /** Strona securabc.com (PrestaShop): kod wyrobu tylko w zakładce szczegółów „Indeks”. */
    private function securaPage(string $title, string $index, string $text): string
    {
        return str_replace(
            '</body>',
            '<div class="tab-pane" id="product-details" role="tabpanel"><div class="product-reference">'
                .'<label class="label">Indeks </label><span>'.$index.'</span></div></div></body>',
            $this->html($title, $text)
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function card(string $sku, string $name, string $manufacturer, array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 1,
            'description' => null,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            ...$attributes,
        ]);
    }

    private function soloWithOldDescription(string $source): Product
    {
        $product = $this->card('987', 'Rękawice chemiczne MAPA SOLO 987', 'MAPA', [
            'description' => self::SOLO_OLD,
            'norms' => 'EN 374',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['norms' => ['EN 374'], 'primary_source_url' => $source, 'primary_source_kind' => 'shop', 'source_urls' => [$source]],
        ]);
        app(DescriptionVersionStore::class)->record($product, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::SOLO_OLD,
            'primary_source_url' => $source,
            'identity_verdict' => 'soft',
            'evidence_count' => 0,
        ]);

        return $product->refresh();
    }

    private function oldImage(Product $product): ProductImage
    {
        Storage::disk('public')->put('products/'.$product->id.'/stare.jpg', 'x');

        return ProductImage::query()->create([
            'product_id' => $product->id, 'path' => 'products/'.$product->id.'/stare.jpg', 'source_url' => 'https://bpbhp.pl/img/solo-987.jpg',
            'is_primary' => true, 'sort_order' => 0, 'checksum' => str_repeat('b', 64),
        ]);
    }

    /** @param  list<string>  $searchErrors */
    private function runSolo(Product $product, bool $force = false, array $searchErrors = [], ?User $syncAs = null): ?Throwable
    {
        return $this->runEnrichment(
            $product,
            [
                ['url' => self::MAPA_SOLO, 'title' => 'MAPA SOLO 987', 'snippet' => 'Rękawice chemiczne SOLO 987'],
                ['url' => self::BPBHP_SOLO, 'title' => 'Rękawice chemiczne MAPA SOLO 987', 'snippet' => 'MAPA SOLO 987'],
            ],
            [
                self::BPBHP_SOLO => $this->html('Rękawice chemiczne MAPA SOLO 987', 'Rękawice chemiczne MAPA SOLO 987 z nitrylu. '.str_repeat('Rękawice odporne na rozpuszczalniki i oleje, wnętrze flokowane bawełną, długość 33 cm. ', 6)),
            ],
            force: $force,
            searchErrors: $searchErrors,
            syncAs: $syncAs,
        );
    }

    /**
     * Przebieg enrichProduct (albo enrichProductSync) na atrapach. Strony spoza $pages odpowiadają 404, zdjęcia (.jpg)
     * — prawdziwym JPEG-iem. Wyjątek przebiegu wraca jako wynik; karta jest odświeżona.
     *
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @param  array<string, string>  $pages  adres => HTML
     * @param  array<string, list<array{url: string, title: string, snippet: string}>>  $hostHits  inny zapis kodu => trafienia na hostach producenta
     * @param  list<string>  $searchErrors
     * @param  array<string, mixed>  $extraction  pola odpowiedzi modelu nadpisujące domyślne
     * @param  list<array{url: string, title: string, snippet: string}>  $moreCatalogHits  pierwsza dodatkowa partia indeksu (fetchMoreCatalogCards)
     */
    private function runEnrichment(
        Product $product,
        array $results,
        array $pages,
        bool $force = false,
        array $hostHits = [],
        array $searchErrors = [],
        array $extraction = [],
        ?User $syncAs = null,
        array $moreCatalogHits = [],
        ?int $batchId = null,
    ): ?Throwable {
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn(['results' => $results, 'errors' => $searchErrors]);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()->andReturnUsing(static fn (array $rows): array => $rows);
        $search->shouldReceive('moreCatalogHits')->zeroOrMoreTimes()->andReturnUsing(
            static function (Product $p, array $tried) use ($moreCatalogHits): array {
                return array_values(array_filter($moreCatalogHits, static fn (array $row): bool => ! in_array($row['url'], $tried, true)));
            }
        );
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->zeroOrMoreTimes()->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('shopCardsForImage')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('catalogHitsOnHosts')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchOnHosts')->zeroOrMoreTimes()->andReturnUsing(
            static fn (Product $clone): array => $hostHits[(string) $clone->sku] ?? []
        );
        $search->shouldReceive('lastHostSearchErrors')->zeroOrMoreTimes()->andReturn([]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $this->prompts = [];
        $this->modelCalls = 0;
        $handler = function (array $messages) use ($product, $extraction): array {
            $this->modelCalls++;
            $system = (string) ($messages[0]['content'] ?? '');
            $user = (string) ($messages[1]['content'] ?? '');
            $this->prompts[] = $user;
            if (str_contains($system, 'filtrem treści')) {
                $out = [];
                $at = strpos($user, "Strony:\n");
                foreach ((array) (json_decode($at === false ? '' : substr($user, $at + 8), true) ?? []) as $page) {
                    if (is_array($page)) {
                        $out[] = ['url' => $page['url'] ?? '', 'text' => $page['text'] ?? ''];
                    }
                }

                return ['pages' => $out];
            }
            preg_match_all('#https?://[^\s"\\\\]+#', $user, $m);

            return [
                'description' => $product->name.' — wyrób opisany na stronie źródłowej. Wykonanie i przeznaczenie jak na karcie wyrobu '
                    .$product->sku.', bez dodatkowych deklaracji. Produkt do pracy w warunkach przemysłowych.',
                'features' => [],
                'specs' => [],
                'norms' => [],
                'certificates' => [],
                'materials' => [],
                'use_cases' => [],
                'attributes' => [],
                'image_urls' => [],
                'source_urls' => array_values(array_unique($m[0] ?? [])),
                'confidence' => 0.9,
                ...$extraction,
            ];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $jpeg = $this->jpeg();
        Http::fake(static function (Request $request) use ($pages, $jpeg) {
            $url = $request->url();
            if (isset($pages[$url])) {
                return Http::response($pages[$url], 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            if (preg_match('/\.jpe?g(?:\?|$)/i', $url) === 1) {
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('', 404);
        });

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );
        $error = null;
        try {
            if ($syncAs !== null) {
                $service->enrichProductSync($product, $syncAs, $force);
            } else {
                $service->enrichProduct($product, $force, $batchId);
            }
        } catch (Throwable $e) {
            $error = $e;
        }
        $product->refresh();

        return $error;
    }

    /**
     * @param  list<string>  $gallery
     * @param  list<string>  $documents
     */
    private function html(string $title, string $text, ?string $ogImage = null, array $gallery = [], array $documents = []): string
    {
        $images = '';
        foreach (array_filter([$ogImage, ...$gallery]) as $src) {
            $images .= '<img src="'.$src.'" alt="'.htmlspecialchars($title).'" width="600" height="600">';
        }
        $links = '';
        foreach ($documents as $doc) {
            $links .= '<li><a href="'.htmlspecialchars($doc).'">Karta produktu PDF</a></li>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($title).'</title>'
            .($ogImage !== null ? '<meta property="og:image" content="'.$ogImage.'">' : '')
            .'</head><body><h1>'.htmlspecialchars($title).'</h1>'.$images
            .'<div class="product-description"><p>'.htmlspecialchars($text).'</p></div>'
            .($links !== '' ? '<ul class="attachments">'.$links.'</ul>' : '')
            .'</body></html>';
    }

    private function extractionPrompt(): string
    {
        foreach ($this->prompts as $prompt) {
            if (str_contains($prompt, 'Strony (po filtrze AI)')) {
                return $prompt;
            }
        }
        $this->fail('model nie dostał stron do opisu');
    }

    private function trace(Product $product): string
    {
        return (string) json_encode($product->enrichment_trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(600, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 120, 200));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
