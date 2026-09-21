<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Enrichment\HybridWebSearchService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Opis karty Tegro z dwóch źródeł — opisu ze sklepu i karty katalogowej PDF (decyzja użytkownika 21.09.2026).
 * Sklep, PDF i odpowiedzi modelu są SYNTETYCZNE; kolejka w testach jest synchroniczna, więc job zleceony przez
 * synchronizację wykonuje się od razu.
 */
final class TegroDatasheetDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'RĘKAWICE G-REX F09 PLUS';

    private const SHOP_TEXT = 'Rękawica ochronna kat. II, wykonana z wkładki nylonowej powleczonej pianką nitrylową. Zakończona ściągaczem.';

    private const PDF_URL = 'https://b2b.tegro.pl/zasoby/import/g/g-rex-f09-plus-karta-katalogowa-pl.pdf';

    private const PAGE_URL = 'https://b2b.tegro.pl/pl/rekawice-g-rex-f09-plus-6';

    private const AI_TEXT = 'Rękawica ochronna kat. II z wkładki nylonowej 15 gg powleczonej spienionym nitrylem F-NITREAL, zakończona '
        .'ściągaczem. Mikroporowata powłoka zapewnia oddychalność, a rękawica umożliwia pracę z ekranami dotykowymi. '
        .'Spełnia EN 388:2016+A1:2018 (4131A) i EN 407:2020 (X1XXXX). Przeznaczona do przemysłu metalowego, motoryzacyjnego '
        .'i prac montażowych.';

    private string $shopText = self::SHOP_TEXT;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $prompts;

    /** @var array<string, mixed> */
    private array $answer = [];

    /** Wywołane w trakcie odpowiedzi modelu (np. ręczna edycja karty). */
    private ?\Closure $duringModel = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->prompts = new \ArrayObject;
        $this->answer = [
            'description' => self::AI_TEXT,
            'features' => ['obsługa ekranów dotykowych', 'poziom odporności 4131A'],
            'specs' => ['Wkładka: nylon 15 gg'],
            'norms' => ['EN 388:2016+A1:2018', 'EN 407:2020'],
            'certificates' => [],
            'materials' => ['nylon', 'nitryl'],
            'use_cases' => ['przemysł metalowy'],
            'source_urls' => [self::PDF_URL],
            'confidence' => 0.9,
        ];
        $this->fakeModel();
        // opis z dwóch źródeł nie szuka niczego w internecie
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldNotReceive('searchBothPhases');
        $search->shouldNotReceive('searchWebWithoutLocalIndex');
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    public function test_sync_describes_the_card_from_the_shop_text_and_the_datasheet_only_with_provenance(): void
    {
        $this->fakeSite();

        $this->sync();

        $this->assertCount(1, $this->prompts);
        $prompt = $this->prompts[0];
        $this->assertStringContainsString('ściągaczem', $prompt);
        $this->assertStringContainsString('F-NITREAL', $prompt);
        $this->assertStringContainsString('wyłącznie te dwa teksty', $prompt);
        $this->assertStringNotContainsString('Wyniki wyszukiwania', $prompt);

        $card = $this->card();
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame([self::PDF_URL, self::PAGE_URL], $payload['source_urls']);
        $this->assertSame(self::PDF_URL, $payload['primary_source_url']);
        $this->assertSame(DescribeB2bProductFromDatasheetJob::PRIMARY_SOURCE_KIND, $payload['primary_source_kind']);
        $this->assertSame(self::SHOP_TEXT, $payload['b2b_sources']['shop_text']);
        $this->assertSame(self::PDF_URL, $payload['b2b_sources']['datasheet_url']);
        $this->assertContains('poziom odporności 4131A', $payload['features']);

        foreach (B2bProductLink::query()->where('product_id', $card->id)->get() as $link) {
            $this->assertSame(sha1(self::AI_TEXT), $link->description_hash);
            $this->assertSame(sha1(self::SHOP_TEXT), $link->source_description_hash);
        }
        // opis wciąż liczy się jako „z B2B” — zbiorcze uzupełnianie z internetu kartę omija
        $this->assertArrayHasKey((int) $card->id, app(B2bDescriptionSource::class)->productIds([(int) $card->id]));
    }

    public function test_next_sync_with_the_same_sources_keeps_the_description_and_asks_nothing(): void
    {
        $this->fakeSite();
        $this->sync();

        $this->sync();

        $this->assertCount(1, $this->prompts);
        $this->assertSame(self::AI_TEXT, $this->card()->description);
    }

    public function test_new_shop_text_comes_back_to_the_card_and_is_described_again(): void
    {
        $this->fakeSite();
        $this->sync();
        $this->shopText = 'Rękawica ochronna kat. II z powłoką nitrylową. Nowy opis ze sklepu Tegro.';

        $this->sync();

        $this->assertCount(2, $this->prompts);
        $this->assertStringContainsString('Nowy opis ze sklepu Tegro', $this->prompts[1]);
        $card = $this->card();
        $this->assertSame(self::AI_TEXT, $card->description);
        $this->assertSame($this->shopText, $card->enrichment_payload['b2b_sources']['shop_text']);
        // opis sprzed zmiany u dostawcy nie przepadł po cichu
        $this->assertSame(self::AI_TEXT, $card->enrichment_payload['replaced_description']);
        $this->assertSame(sha1($this->shopText), B2bProductLink::query()->where('product_id', $card->id)->value('source_description_hash'));
    }

    public function test_description_with_a_protection_level_absent_from_the_sources_is_rejected(): void
    {
        $this->answer['description'] = str_replace('4131A', '4544C', self::AI_TEXT);
        $this->fakeSite();

        $this->sync();

        $this->assertCount(1, $this->prompts);
        $card = $this->card();
        $this->assertSame(self::SHOP_TEXT, $card->description);
        $this->assertNotSame(Product::ENRICHMENT_DONE, $card->enrichment_status);
    }

    public function test_list_items_with_a_level_absent_from_the_sources_are_dropped(): void
    {
        $this->answer['features'] = ['obsługa ekranów dotykowych', 'odporność na przecięcie 4X44F'];
        $this->fakeSite();

        $this->sync();

        $payload = (array) $this->card()->enrichment_payload;
        $this->assertSame(['obsługa ekranów dotykowych'], $payload['features']);
        $this->assertSame(['odporność na przecięcie 4X44F'], $payload['b2b_sources']['dropped_levels']);
    }

    public function test_manual_edit_while_the_model_answers_wins(): void
    {
        $this->duringModel = function (): void {
            Product::query()->where('sku', 'F09 PLUS 6')->update(['description' => 'Opis poprawiony ręcznie przez handlowca w trakcie.']);
        };
        $this->fakeSite();

        $this->sync();

        $this->assertSame('Opis poprawiony ręcznie przez handlowca w trakcie.', $this->card()->description);
        $this->assertNull(B2bProductLink::query()->where('product_id', $this->card()->id)->value('source_description_hash'));
    }

    public function test_dry_run_asks_nothing(): void
    {
        $this->fakeSite();

        app(B2bAccountSyncRunner::class)->run($this->account(), dryRun: true, delayMs: 0);

        $this->assertCount(0, $this->prompts);
    }

    private function sync(): void
    {
        app(B2bAccountSyncRunner::class)->run($this->account(), delayMs: 0);
    }

    private function card(): Product
    {
        return Product::query()->where('sku', 'F09 PLUS 6')->sole();
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(
            ['username' => 'jan@example.com'],
            ['password' => 'dobre-haslo', 'sites' => ['https://b2b.tegro.pl/'], 'connector' => 'tegro', 'sync_images' => false],
        )->fresh();
    }

    private function fakeModel(): void
    {
        $handler = function (array $messages): array {
            $this->prompts->append((string) ($messages[1]['content'] ?? ''));
            if ($this->duringModel !== null) {
                ($this->duringModel)();
            }

            return $this->answer;
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /** Karta katalogowa w układzie PDF-ów Tegro: kolumny rozbite na krótkie wiersze. */
    private function datasheetPdf(): string
    {
        return Pdf::loadHTML('<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";font-size:11px}</style></head><body>'
            .'<p>WKŁADKA</p><p>POWLECZENIE</p><p>F-NITREAL to innowacyjna technologia na czystym, lekko spienionym nitrylu,</p>'
            .'<p>który zapewnia wysoką przyczepność oraz oddychalność rękawicy.</p><p>ROZMIAR</p><p>ŚRODOWISKO: suche / mokre / zaolejone</p>'
            .'<p>BRANŻE:</p><p>Przemysł metalowy</p><p>Przemysł motoryzacyjny (automotive)</p><p>Prace montażowe</p><p>F 09 PLUS</p>'
            .'<p>EN ISO 21420:2020,</p><p>EN 388:2016+A1:2018 (4131A),</p><p>EN 407:2020 (X1XXXX)</p>'
            .'<p>Rękawica Touchscreen umożliwia pracę z ekranami dotykowymi. Wkładka o uigleniu 15 gg gwarantuje wysoką elastyczność.</p>'
            .'</body></html>')->output();
    }

    private function fakeSite(): void
    {
        $session = false;
        Http::fake(function (Request $request) use (&$session) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $item = fn (int $id, string $size): array => [
                'Id' => $id, 'Name' => self::MODEL.' '.$size, 'Ean' => '590000000000'.$size, 'Sku' => 'F09 PLUS '.$size,
                'Description' => $this->shopText, 'Model' => self::MODEL, 'Brand' => 'G-REX', 'Unit' => 'para',
                'RetailPriceNet' => ['Value' => 6.0, 'Currency' => 'PLN'], 'PriceAfterDiscountNet' => ['Value' => 5.1, 'Currency' => 'PLN'],
                'Attributes' => [], 'Categories' => [['Id' => '1', 'Name' => 'RĘKAWICE DZIANE POWLEKANE PIANĄ']], 'Photo' => '',
            ];

            return match (true) {
                $path === '/pl/logowanie' => Http::response('<form></form>'),
                $path === '/logowanie' => (function () use (&$session) {
                    $session = true;

                    return Http::response('', 200, ['Set-Cookie' => '.ASPXAUTH=auth1; path=/']);
                })(),
                $path === '/pl/home' => Http::response('<script id="s-a-p">{"isLoggedIn":'.($session ? 'true' : 'false').'}</script>'),
                $path === '/api3/product/findProduct' => Http::response([
                    'Count' => 2, 'HasMore' => false, 'Items' => [$item(4634, '6'), $item(4635, '7')],
                ]),
                $path === '/pl/xmlapi/1/3/UTF8' => Http::response(
                    '<?xml version="1.0" encoding="utf-8"?><products>'
                    .'<product><id>4634</id><url><![CDATA['.self::PAGE_URL.']]></url></product>'
                    .'<product><id>4635</id><url><![CDATA[https://b2b.tegro.pl/pl/rekawice-g-rex-f09-plus-7]]></url></product></products>',
                    200,
                    ['Content-Type' => 'text/xml'],
                ),
                str_starts_with($path, '/pl/rekawice-') => Http::response(
                    '<div class="kontrolka-ZalacznikiDoPropduktu"><div class="pliki-do-produktu">'
                    .'<a href="'.parse_url(self::PDF_URL, PHP_URL_PATH).'">'
                    .'g-rex-f09-plus-karta-katalogowa-pl.pdf</a></div></div>',
                ),
                str_ends_with($path, '.pdf') => Http::response($this->datasheetPdf(), 200, ['Content-Type' => 'application/pdf']),
                default => Http::response('nieznany adres w teście', 404),
            };
        });
    }
}
