<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\ArtraB2bClient;
use App\Services\B2b\ArtraB2bConnector;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Enrichment\HybridWebSearchService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Opis kart ARTRA wyłącznie z karty produktu PDF i tabelki ze strony (decyzja użytkownika 22.09.2026). Blok opisowy
 * artra.pl to na każdej karcie ten sam slogan „Konstrukcja obuwia ARELAX®…” — nie jest opisem ani źródłem dla
 * modelu, a pusty opis ze sklepu nie może skasować opisu karty (ownDescriptionIsGone; wypadek UVEX 20.09.2026).
 * Sklep, PDF i odpowiedzi modelu są SYNTETYCZNE; kolejka w testach jest synchroniczna (poza testami z Queue::fake).
 */
final class ArtraDatasheetDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'ARCASIO 732 616560 S1 P ESD';

    private const HANDLE = '3813781-arcasio-732-616560-s1-p-esd';

    private const SLOGAN = 'Konstrukcja obuwia ARELAX® zapewnia przestrzeń dla wszystkich palców i swobodę ruchu przez cały dzień.';

    private const OLD_AI_TEXT = 'Półbuty bezpieczne ARCASIO 732 w klasie S1 P ESD z podnoskiem stalowym i wkładką antyprzebiciową, '
        .'odprowadzające ładunki elektrostatyczne.';

    private const AI_TEXT = 'Półbuty bezpieczne ARCASIO 732 z cholewką z weganskiej mikrofibry i stalowym podnoskiem. Wkładka '
        .'antyprzebiciowa, podeszwa PU/TPU odporna na poślizg, właściwości antyelektrostatyczne ESD. Obuwie spełnia '
        .'EN ISO 20345:2011.';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $prompts;

    /** @var array<string, mixed> odpowiedź modelu — test może ją podmienić */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->prompts = new \ArrayObject;
        $this->answer = [
            'description' => self::AI_TEXT,
            'features' => ['podnosek stalowy', 'ESD'],
            'specs' => ['Podeszwa: PU/TPU'],
            'norms' => ['EN ISO 20345:2011'],
            'certificates' => [],
            'materials' => ['mikrofibra'],
            'use_cases' => ['magazyny'],
            'source_urls' => [],
            'confidence' => 0.9,
        ];
        $handler = function (array $messages): array {
            $this->prompts->append((string) ($messages[1]['content'] ?? ''));

            return $this->answer;
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        // opis z karty katalogowej nie szuka niczego w internecie
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldNotReceive('searchBothPhases');
        $search->shouldNotReceive('searchWebWithoutLocalIndex');
        $this->app->instance(HybridWebSearchService::class, $search);
        $this->fakeShop();
    }

    public function test_page_with_the_slogan_gives_an_empty_shop_description(): void
    {
        $connector = new ArtraB2bConnector(new ArtraB2bClient(0));
        $remote = iterator_to_array($connector->products(), false);

        $this->assertCount(1, $remote);
        $this->assertSame('', $connector->description($remote[0]));
    }

    public function test_sync_without_shop_description_keeps_the_slogan_card_as_it_is(): void
    {
        Queue::fake();
        $card = $this->card(self::SLOGAN);
        $this->link($card, sha1(self::SLOGAN));

        $this->sync();

        $this->assertSame(self::SLOGAN, $card->refresh()->description);
        $this->assertSame(sha1(self::SLOGAN), $this->linkOf($card)->description_hash);
    }

    public function test_sync_without_shop_description_keeps_a_restored_description(): void
    {
        Queue::fake();
        $card = $this->card(self::OLD_AI_TEXT);
        // po b2b:restore-replaced-descriptions odcisk wciąż jest odciskiem sloganu
        $this->link($card, sha1(self::SLOGAN));

        $this->sync();

        $this->assertSame(self::OLD_AI_TEXT, $card->refresh()->description);
        Queue::assertNotPushed(DescribeB2bProductFromDatasheetJob::class);
    }

    public function test_slogan_card_is_described_from_the_datasheet_and_the_table_only(): void
    {
        $card = $this->card(self::SLOGAN, ['replaced_description' => self::OLD_AI_TEXT]);
        $this->link($card, sha1(self::SLOGAN));

        $this->sync();

        $this->assertCount(1, $this->prompts);
        $prompt = $this->prompts[0];
        $this->assertStringContainsString('PU/TPU', $prompt);
        $this->assertStringContainsString('EN ISO 20345:2011 S1 P SRC', $prompt);
        $this->assertStringNotContainsString('ARELAX', $prompt);
        $this->assertStringNotContainsString('Wyniki wyszukiwania', $prompt);

        $card->refresh();
        $this->assertSame(self::AI_TEXT, $card->description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(DescribeB2bProductFromDatasheetJob::PRIMARY_SOURCE_KIND, $payload['primary_source_kind']);
        $this->assertSame('', $payload['b2b_sources']['shop_text']);
        // opis sprzed sloganu jest cenniejszy niż slogan — zostaje w replaced_description, slogan w b2b_sources
        $this->assertSame(self::OLD_AI_TEXT, $payload['replaced_description']);
        $this->assertSame(self::SLOGAN, $payload['b2b_sources']['replaced_text']);

        $link = $this->linkOf($card);
        $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
        $this->assertSame(sha1(''), $link->source_description_hash);
        // zbiorcze uzupełnianie z internetu kartę omija
        $this->assertArrayHasKey((int) $card->id, app(B2bDescriptionSource::class)->productIds([(int) $card->id]));
    }

    public function test_replaced_slogan_goes_to_replaced_description_when_the_slot_is_free(): void
    {
        $card = $this->card(self::SLOGAN);
        $this->link($card, sha1(self::SLOGAN));

        $this->sync();

        $payload = (array) $card->refresh()->enrichment_payload;
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame(self::SLOGAN, $payload['replaced_description']);
    }

    public function test_card_without_description_is_described_and_the_next_sync_asks_nothing(): void
    {
        $card = $this->card(null);

        $this->sync();
        $this->sync();

        $this->assertCount(1, $this->prompts);
        $this->assertSame(self::AI_TEXT, $card->refresh()->description);
    }

    /**
     * Karta produktu zapisana starszą wersją łącznika jako „inny dokument” (produkcja 22.09.2026: 9476
     * „PL-KP-ARISAKA_333_631460_S2_ESD.pdf”, bez konta B2B) — synchronizacja przejmuje plik, poprawia nazwę i rodzaj,
     * a karta dostaje opis z PDF.
     */
    public function test_legacy_product_sheet_stored_as_other_document_becomes_a_datasheet(): void
    {
        Queue::fake();
        $card = $this->card(self::SLOGAN);
        $this->link($card, sha1(self::SLOGAN));
        $this->sync();
        $sheet = $this->datasheetOf($card);
        $legacyTitle = 'PL-KP-'.str_replace(' ', '_', self::SKU).'.pdf';
        // plik zapisało wcześniej wzbogacanie ze strony producenta — bez konta B2B (produkcja: b2b_account_id = null)
        $sheet->forceFill(['kind' => ProductDocument::KIND_OTHER, 'title' => $legacyTitle, 'b2b_account_id' => null])->save();
        $foreign = ProductDocument::query()->create([
            'product_id' => $card->id,
            'path' => 'products/'.$card->id.'/reczny.pdf',
            'source_url' => 'https://example.test/reczny.pdf',
            'title' => 'Plik dodany ręcznie',
            'kind' => ProductDocument::KIND_OTHER,
        ]);
        Queue::fake();

        $this->sync();

        $sheet->refresh();
        $this->assertSame(ProductDocument::KIND_DATASHEET, $sheet->kind);
        $this->assertSame('Karta produktu', $sheet->title);
        $this->assertSame((int) $this->account()->id, $sheet->b2b_account_id);
        $this->assertSame('Plik dodany ręcznie', $foreign->refresh()->title);
        $this->assertNull($foreign->b2b_account_id);
        Queue::assertPushed(DescribeB2bProductFromDatasheetJob::class, 1);
    }

    public function test_restored_description_is_not_replaced_by_the_datasheet_description(): void
    {
        $card = $this->card(self::OLD_AI_TEXT);
        $this->link($card, sha1(self::SLOGAN));

        $this->sync();

        $this->assertCount(0, $this->prompts);
        $this->assertSame(self::OLD_AI_TEXT, $card->refresh()->description);
    }

    public function test_swapped_supplier_table_rows_do_not_reach_the_model(): void
    {
        // karta 9577 z produkcji: nazwa O1 FO, tabelka artra.pl wariantu S1 P, PDF poprawny
        $card = $this->armenO1Card();
        $this->answer = [...$this->answer,
            'description' => 'Półbuty zawodowe ARMEN 900 bez podnoska, klasa O1 FO według EN ISO 20347:2012, z cholewką '
                .'z wegańskiej mikrofibry i podeszwą GRIPPER PU odporną na poślizg.',
            'norms' => ['EN ISO 20347:2012'],
        ];

        DescribeB2bProductFromDatasheetJob::dispatchSync((int) $card->id, (int) $this->account()->id, true);

        $this->assertCount(1, $this->prompts);
        $this->assertStringNotContainsString('20345', $this->prompts[0]);
        $this->assertStringNotContainsString('stalowy', $this->prompts[0]);
        $this->assertStringContainsString('GRIPPER', $this->prompts[0]);
        $this->assertStringContainsString('20347:2012 O1 FO', $this->prompts[0]);

        $card->refresh();
        $this->assertStringStartsWith('Półbuty zawodowe ARMEN 900', (string) $card->description);
        $dropped = ((array) $card->enrichment_payload)['b2b_sources']['shop_fields_dropped'];
        $this->assertSame(['podnosek: stalowy LIBERYUM', 'norma: EN ISO 20345:2011 S1 P SRC'], $dropped);
    }

    /** Zdanie objaśniające klasę nie jest twierdzeniem o innym wariancie — liczy się zapis normy z klasą. */
    public function test_description_explaining_the_class_is_not_a_foreign_variant(): void
    {
        $card = new Product(['sku' => 'ARYA 300 671460 S1 PL', 'name' => 'ARYA 300 671460 S1 PL']);
        $result = fn (string $description, array $norms = []): array => [
            'description' => $description,
            'payload' => ['norms' => $norms, 'attributes' => ['klasa_ochrony' => 'S1PL']],
        ];

        $this->assertSame([], DescribeB2bProductFromDatasheetJob::foreignFootwearClasses($card, $result(
            'Sandały w klasie S1 PL: spełniają wymagania SB, a wkładka PL (S1 + wkładka) chroni przed przebiciem. '
            .'EN ISO 20345:2022 S1 PL FO SR.',
            ['EN ISO 20345:2022 S1PL FO SR'],
        )));
        $this->assertSame(['S3'], DescribeB2bProductFromDatasheetJob::foreignFootwearClasses($card, $result(
            'Sandały ochronne zgodne z EN ISO 20345:2011 S3 SRC.',
        )));
        $this->assertSame(['O1'], DescribeB2bProductFromDatasheetJob::foreignFootwearClasses($card, $result(
            'Sandały ochronne.', ['EN ISO 20347:2012 O1 FO'],
        )));

        $occupational = new Product(['sku' => 'ARMEN 900 6060 O1 FO', 'name' => 'ARMEN 900 6060 O1 FO']);
        $o1 = fn (string $description): array => [
            'description' => $description,
            'payload' => ['norms' => ['EN ISO 20347:2012 O1 FO SRC'], 'attributes' => ['klasa_ochrony' => 'O1']],
        ];
        $this->assertSame([], DescribeB2bProductFromDatasheetJob::foreignFootwearClasses($occupational, $o1(
            'Sandały zawodowe O1 FO bez podnoska — to nie jest obuwie bezpieczne S1.',
        )));
        $this->assertSame(['S1P'], DescribeB2bProductFromDatasheetJob::foreignFootwearClasses($occupational, $o1(
            'Sandały ARMEN 900 w klasie S1 P z podnoskiem stalowym.',
        )));
    }

    /** Numer EAN z cyframi 20345 to nie norma, a „podnosek: nie” przy obuwiu zawodowym to prawdziwa informacja. */
    public function test_table_filter_keeps_ean_and_a_plain_no_toecap_row(): void
    {
        $card = new Product([
            'sku' => 'ARMEN 900 6060 O1 FO',
            'name' => 'ARMEN 900 6060 O1 FO',
            'shop_fields_summary' => "EAN: 8583020345123\npodnosek: nie\nnorma: EN ISO 20347:2012 O1 FO SRC",
        ]);

        $filtered = DescribeB2bProductFromDatasheetJob::shopFieldsForModel($card);

        $this->assertSame([], $filtered['dropped']);
        $this->assertStringContainsString('EAN: 8583020345123', $filtered['text']);
        $this->assertStringContainsString('podnosek: nie', $filtered['text']);
    }

    public function test_description_of_another_variant_class_from_a_clean_datasheet_is_retried_up_to_the_limit(): void
    {
        $card = $this->armenO1Card();
        // model skleił klasę wariantu S1 P, choć PDF i przesiana tabelka podają tylko O1 FO — losowość modelu
        $this->answer = $this->s1pAnswer();

        $this->describe($card);

        $card->refresh();
        $this->assertSame(self::SLOGAN, $card->description);
        $rejected = $this->rejection($card);
        $this->assertStringContainsString('klasa obuwia spoza wariantu karty', $rejected['reason']);
        $this->assertStringContainsString('S1P', $rejected['reason']);
        $this->assertSame(1, $rejected['attempts']);
        $this->assertFalse($rejected['permanent']);
        $this->assertNotNull($this->sourcesOf($card));

        $this->describe($card);
        $this->describe($card);
        $this->assertCount(3, $this->prompts);
        $this->assertSame(3, $this->rejection($card)['attempts']);
        // limit prób dla tych samych źródeł — ani import, ani ponowne zlecenie nie pytają modelu
        $this->assertNull($this->sourcesOf($card));
        $this->describe($card);
        $this->assertCount(3, $this->prompts);
    }

    public function test_model_without_description_is_retried_and_a_new_datasheet_resets_the_counter(): void
    {
        $card = $this->armenO1Card();
        // pusty albo ucięty JSON — odpowiedź modelu, nie wada źródeł
        $this->answer = [...$this->answer, 'description' => ''];

        $this->describe($card);
        $this->describe($card);

        $this->assertCount(2, $this->prompts);
        $this->assertSame('model nie zwrócił opisu', $this->rejection($card)['reason']);
        $this->assertSame(2, $this->rejection($card)['attempts']);
        $this->assertNotNull($this->sourcesOf($card));

        $this->describe($card);

        $this->assertSame(3, $this->rejection($card)['attempts']);
        $this->assertNull($this->sourcesOf($card));

        // nowy PDF (inny sha1) po blokadzie — licznik od nowa
        $sheet = DescribeB2bProductFromDatasheetJob::datasheet((int) $card->id, (int) $this->account()->id);
        $sheet?->update(['text' => $sheet->text."\nWydanie 2."]);
        $this->assertNotNull($this->sourcesOf($card));
        $this->describe($card);
        $this->assertCount(4, $this->prompts);
        $this->assertSame(1, $this->rejection($card)['attempts']);
        $this->assertNotNull($this->sourcesOf($card));
    }

    public function test_datasheet_of_another_variant_only_blocks_after_the_first_rejection(): void
    {
        $card = $this->armenO1Card();
        // PDF przypięty do karty O1 FO opisuje wyłącznie wariant S1 P — ten sam PDF da ten sam wynik
        $this->datasheetOf($card)->update(['text' => "KARTA PRODUKTU\nARMEN 900 6060\nPółbuty bezpieczne z podnoskiem "
            ."stalowym. Cholewka: wegańska mikrofibra.\nPodeszwa: GRIPPER PU, odporna na poślizg.\nEN ISO 20345:2011 S1 P SRC"]);
        $this->answer = $this->s1pAnswer();

        $this->describe($card);

        $rejected = $this->rejection($card);
        $this->assertTrue($rejected['permanent']);
        $this->assertSame(1, $rejected['attempts']);
        $this->assertNull($this->sourcesOf($card));
        $this->describe($card);
        $this->assertCount(1, $this->prompts);
        $this->assertSame(self::SLOGAN, $card->refresh()->description);
    }

    public function test_datasheet_listing_the_card_variant_among_others_keeps_the_counter(): void
    {
        $card = $this->armenO1Card();
        // PDF serii: lista wariantów z klasą karty — obca klasa w opisie to wybór modelu, nie jedyne, co PDF podaje
        $this->datasheetOf($card)->update(['text' => "KARTA PRODUKTU\nARMEN 900\nCholewka: wegańska mikrofibra.\n"
            ."Podeszwa: GRIPPER PU, odporna na poślizg.\nWarianty: EN ISO 20347:2012 O1 FO SRC; EN ISO 20345:2011 S1 P SRC"]);
        $this->answer = $this->s1pAnswer();

        $this->describe($card);

        $rejected = $this->rejection($card);
        $this->assertStringContainsString('klasa obuwia spoza wariantu karty', $rejected['reason']);
        $this->assertFalse($rejected['permanent']);
        $this->assertSame(1, $rejected['attempts']);
        $this->assertNotNull($this->sourcesOf($card));
    }

    public function test_successful_description_after_a_rejection_removes_the_trace(): void
    {
        $card = $this->armenO1Card();
        $this->answer = [...$this->answer, 'description' => ''];
        $this->describe($card);
        $this->assertSame(1, $this->rejection($card)['attempts']);

        $this->answer = [...$this->answer,
            'description' => 'Półbuty zawodowe ARMEN 900 bez podnoska, klasa O1 FO według EN ISO 20347:2012, z cholewką '
                .'z wegańskiej mikrofibry i podeszwą GRIPPER PU odporną na poślizg.',
            'norms' => ['EN ISO 20347:2012'],
        ];
        $this->describe($card);

        $card->refresh();
        $this->assertStringStartsWith('Półbuty zawodowe ARMEN 900', (string) $card->description);
        $this->assertArrayNotHasKey('b2b_sources_rejected', (array) $card->enrichment_payload);
    }

    /** @return array<string, mixed> */
    private function s1pAnswer(): array
    {
        return [...$this->answer,
            'description' => 'Półbuty ARMEN 900 w klasie S1 P, z cholewką z wegańskiej mikrofibry i podeszwą GRIPPER PU '
                .'odporną na poślizg, wygodne na całą zmianę.',
            'norms' => [],
        ];
    }

    private function describe(Product $card): void
    {
        DescribeB2bProductFromDatasheetJob::dispatchSync((int) $card->id, (int) $this->account()->id, true);
    }

    /** @return array<string, mixed>|null */
    private function sourcesOf(Product $card): ?array
    {
        $card->refresh();
        $accountId = (int) $this->account()->id;

        return DescribeB2bProductFromDatasheetJob::sources(
            $card,
            $this->linkOf($card),
            DescribeB2bProductFromDatasheetJob::datasheet((int) $card->id, $accountId),
            true,
        );
    }

    /** @return array<string, mixed> */
    private function rejection(Product $card): array
    {
        return ((array) $card->refresh()->enrichment_payload)['b2b_sources_rejected'];
    }

    private function datasheetOf(Product $card): ProductDocument
    {
        return ProductDocument::query()->where('product_id', $card->id)->sole();
    }

    private function armenO1Card(): Product
    {
        $card = Product::query()->create([
            'sku' => 'ARMEN 900 6060 O1 FO',
            'name' => 'ARMEN 900 6060 O1 FO',
            'manufacturer' => 'ARTRA',
            'description' => self::SLOGAN,
            'shop_fields_summary' => "Parametry\ncholewka: wegańska RACYA SKINYUM\npodnosek: stalowy LIBERYUM\n"
                ."podeszwa: GRIPPER PU.2D\nnorma: EN ISO 20345:2011 S1 P SRC\nWaga: 430 gramów dla rozmiaru 42",
            'catalog_price_net' => 400,
            'purchase_price' => 250,
            'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account()->id,
            'remote_id' => '3813088-armen-900-6060-o1-fo',
            'product_id' => $card->id,
            'remote_sku' => $card->sku,
            'remote_name' => $card->name,
            'description_hash' => sha1(self::SLOGAN),
        ]);
        ProductDocument::query()->create([
            'product_id' => $card->id,
            'b2b_account_id' => $this->account()->id,
            'path' => 'products/'.$card->id.'/karta.pdf',
            'source_url' => 'https://artra.pl/cdn/shop/files/PL-KP-ARMEN_900_6060_O1_FO.pdf',
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
            'text' => "KARTA PRODUKTU\nARMEN 900 6060 O1 FO\nObuwie zawodowe bez podnoska. Cholewka: wegańska mikrofibra.\n"
                ."Podeszwa: GRIPPER PU, odporna na poślizg.\nEN ISO 20347:2012 O1 FO SRC",
        ]);

        return $card;
    }

    private function sync(): void
    {
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0, withImages: false);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function card(?string $description, ?array $payload = null): Product
    {
        return Product::query()->create([
            'sku' => self::SKU,
            'name' => self::SKU,
            'manufacturer' => 'ARTRA',
            'description' => $description,
            'enrichment_payload' => $payload,
            'catalog_price_net' => 519.00,
            'purchase_price' => 300.00,
            'currency' => 'PLN',
        ]);
    }

    private function link(Product $card, string $descriptionHash): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account()->id,
            'remote_id' => self::HANDLE,
            'product_id' => $card->id,
            'remote_sku' => self::SKU,
            'remote_name' => self::SKU,
            'description_hash' => $descriptionHash,
        ]);
    }

    private function linkOf(Product $card): B2bProductLink
    {
        return B2bProductLink::query()->where('product_id', $card->id)->sole();
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'ARTRA'],
            ['sites' => ['artra.pl'], 'connector' => 'artra', 'sync_images' => false],
        )->fresh();
    }

    /** Karta produktu ARTRY (pl-kp-…) — bez sloganu, z tym, czego strona sklepu nie podaje. */
    private static function datasheetPdf(): string
    {
        return Pdf::loadHTML('<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";font-size:11px}</style></head><body>'
            .'<p>KARTA PRODUKTU</p><p>'.self::SKU.'</p>'
            .'<p>Cholewka: wegańska mikrofibra. Podszewka: tkanina oddychająca.</p>'
            .'<p>Podeszwa: PU/TPU, odporna na poślizg. Podnosek: stalowy. Wkładka antyprzebiciowa: tekstylna.</p>'
            .'<p>Obuwie antyelektrostatyczne ESD.</p>'
            .'</body></html>')->output();
    }

    private function fakeShop(): void
    {
        $file = str_replace(' ', '_', self::SKU);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><h1>'.self::SKU.'</h1>'
            .'<div class="product-specs">'
            .'<div class="product-specs__row"><span class="product-specs__key"> podnosek </span>'
            .'<span class="product-specs__value">stalowy LIBERYUM™</span></div>'
            .'<div class="product-specs__row"><span class="product-specs__key"> norma </span>'
            .'<span class="product-specs__value"> EN ISO 20345:2011 S1 P SRC <br> ESD według EN IEC 61340-4-3:2018 </span></div>'
            .'</div>'
            .'<div class="product-description-text"> '.self::SLOGAN.' </div>'
            .'<div class="accordion__content"><div class="prose">'
            .'<p><a href="//artra.pl/cdn/shop/files/PL-KP-'.$file.'.pdf?v=1">Karta produktu</a></p>'
            .'</div></div></body></html>';
        $json = (string) json_encode(['product' => [
            'id' => 1, 'title' => self::SKU, 'handle' => self::HANDLE, 'vendor' => 'Artra', 'body_html' => null,
            'options' => [['name' => 'Size', 'values' => ['EU 41']]],
            'variants' => [['title' => 'EU 41', 'option1' => 'EU 41', 'sku' => null, 'price' => '519.00']],
            'images' => [],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Http::fake(function (Request $request) use ($html, $json) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>'
                    .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                    .'<sitemap><loc>https://artra.pl/sitemap_products_1.xml?from=1&amp;to=2</loc></sitemap></sitemapindex>'),
                $path === '/sitemap_products_1.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?>'
                    .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                    .'<url><loc>https://artra.pl/products/'.self::HANDLE.'</loc></url></urlset>'),
                $path === '/products/'.self::HANDLE.'.json' => Http::response($json),
                $path === '/products/'.self::HANDLE => Http::response($html),
                str_ends_with($path, '.pdf') => Http::response(self::datasheetPdf(), 200, ['Content-Type' => 'application/pdf']),
                default => Http::response('Nie znaleziono', 404),
            };
        });
    }
}
