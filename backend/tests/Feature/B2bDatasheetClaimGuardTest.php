<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Twierdzenia spoza źródeł w opisie z karty katalogowej PDF (SourceClaimGuard w describeFromB2bSources) i ponowny
 * opis kart opisanych wcześniej z PDF (redo, b2b:redescribe-from-datasheets). Teksty PDF jak w kartach ARTRY
 * z produkcji 22.09.2026 (9495, 9524), odpowiedzi modelu — ze zmyśleniami, które wtedy padły.
 */
final class B2bDatasheetClaimGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ARCASIO_PDF = "KARTA PRODUKTU\nARCASIO 732 616560 S1 P ESD\nCholewka: wegańska mikrofibra\n"
        ."Norma: EN ISO 20345:2011 S1 P SRC\nESD według EN IEC 61340-4-3:2018\nPodnosek: stalowy\nWkładka: FLEXYUM\n"
        ."Podeszwa: RAPTOR PU.2D\nRozmiar: 36-48";

    private const ARMEN_PDF = "KARTA PRODUKTU\nARMEN 9003 2360 S1\nsandały bezpieczne Metal free\n"
        ."Norma: EN ISO 20345:2022 S1 FO SR\nPodnosek: kompozytowy\nCholewka: skóra licowa\nRozmiar: 36-48";

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $prompts;

    /** @var array<string, mixed> */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prompts = new \ArrayObject;
        $handler = function (array $messages): array {
            $this->prompts->append((string) ($messages[1]['content'] ?? ''));

            return $this->answer;
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldNotReceive('searchBothPhases');
        $search->shouldNotReceive('searchWebWithoutLocalIndex');
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    public function test_arcasio_water_resistance_sentence_and_missing_data_items_are_dropped(): void
    {
        $card = $this->card('ARCASIO 732 616560 S1 P ESD', self::ARCASIO_PDF);
        $this->answer = $this->answerWith(
            'Półbuty bezpieczne ARCASIO 732 z cholewką z wegańskiej mikrofibry i stalowym podnoskiem. Odporność na '
            ."przenikanie płynów dzięki wodoodpornej cholewce.\n\nPodeszwa RAPTOR PU.2D odporna na poślizg, wkładka "
            .'FLEXYUM. Obuwie antyelektrostatyczne ESD, zgodne z EN ISO 20345:2011 S1 P SRC.',
            specs: ['Podnosek: stalowy', 'Typ zapięcia: brak danych w źródle', 'Cholewka: wodoodporna'],
        );

        $this->describe($card);

        $card->refresh();
        $description = (string) $card->description;
        $this->assertStringNotContainsString('wodoodporn', $description);
        $this->assertStringNotContainsString('przenikanie', $description);
        $this->assertStringContainsString('stalowym podnoskiem.', $description);
        $this->assertStringContainsString('antyelektrostatyczne ESD', $description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(['Podnosek: stalowy'], $payload['specs']);
        $dropped = $payload['b2b_sources']['dropped_claims'];
        $this->assertCount(3, $dropped);
        $this->assertStringStartsWith('wodoodporność: Odporność na przenikanie płynów', $dropped[0]);
        $this->assertStringContainsString('brak danych: Typ zapięcia', $dropped[1]);
        $this->assertStringContainsString('wodoodporność: Cholewka: wodoodporna', $dropped[2]);
        // polecenie trybu B2B — tylko fakty ze źródeł, bez „brak danych w źródle”
        $this->assertStringContainsString('Brak informacji = pomiń', $this->prompts[0]);
    }

    public function test_armen_heat_purpose_class_explanation_and_test_details_are_dropped(): void
    {
        $card = $this->card('ARMEN 9003 2360 S1', self::ARMEN_PDF);
        $this->answer = $this->answerWith(
            'Sandały bezpieczne ARMEN 9003 w wersji Metal free, z cholewką ze skóry licowej i podnoskiem kompozytowym. '
            ."Przeznaczenie: praca w wysokich temperaturach.\n\nKlasa S1 gwarantuje ochronę palców przy uderzeniu o "
            .'energii 200 J i zgnieceniu siłą 15 kN. Oznaczenie SR potwierdza badanie na podłożu z roztworem '
            .'laurylosiarczanu sodu oraz gliceryną. Podeszwa odporna na oleje (FO), norma EN ISO 20345:2022 S1 FO SR.',
            useCases: ['praca w wysokich temperaturach', 'magazyny'],
        );

        $this->describe($card);

        $card->refresh();
        $description = (string) $card->description;
        foreach (['temperatur', '200 J', '15 kN', 'laurylosiarczan', 'gliceryn'] as $fabricated) {
            $this->assertStringNotContainsString($fabricated, $description);
        }
        $this->assertStringContainsString('Metal free', $description);
        $this->assertStringContainsString('Podeszwa odporna na oleje (FO)', $description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(['magazyny'], $payload['use_cases']);
        $this->assertCount(4, $payload['b2b_sources']['dropped_claims']);
    }

    public function test_water_resistance_stays_when_the_datasheet_gives_s3(): void
    {
        $card = $this->card('ARAGON 920 6060 S3', "KARTA PRODUKTU\nARAGON 920 6060 S3\nCholewka: skóra\nEN ISO 20345:2011 S3 SRC");
        $text = 'Trzewiki bezpieczne ARAGON 920 z wodoodporną cholewką ze skóry, zgodne z EN ISO 20345:2011 S3 SRC.';
        $this->answer = $this->answerWith($text);

        $this->describe($card);

        $this->assertSame($text, $card->refresh()->description);
        $this->assertSame([], ((array) $card->enrichment_payload)['b2b_sources']['dropped_claims']);
    }

    public function test_no_toecap_statement_stays_for_occupational_footwear(): void
    {
        $card = $this->card('ARMEN 900 6060 O1 FO', "KARTA PRODUKTU\nARMEN 900 6060 O1 FO\nObuwie zawodowe bez podnoska.\n"
            ."Cholewka: wegańska mikrofibra\nEN ISO 20347:2012 O1 FO SRC");
        $text = 'Półbuty zawodowe ARMEN 900 bez podnoska i bez wkładki antyprzebiciowej, z cholewką z wegańskiej '
            .'mikrofibry, zgodne z EN ISO 20347:2012 O1 FO SRC.';
        $this->answer = $this->answerWith($text);

        $this->describe($card);

        $this->assertSame($text, $card->refresh()->description);
    }

    public function test_description_made_only_of_fabrications_is_rejected_and_retried(): void
    {
        $card = $this->card('ARMEN 9003 2360 S1', self::ARMEN_PDF, description: '');
        $this->answer = $this->answerWith('Sandały przeznaczone do pracy w wysokich temperaturach. Wodoodporna cholewka '
            .'chroni przed przemakaniem.');

        $this->describe($card);

        $card->refresh();
        $this->assertSame('', (string) $card->description);
        $rejected = ((array) $card->enrichment_payload)['b2b_sources_rejected'];
        $this->assertStringContainsString('opis za krótki', $rejected['reason']);
        $this->assertFalse($rejected['permanent']);
        $this->assertSame(1, $rejected['attempts']);
    }

    public function test_redo_describes_again_a_card_described_from_the_datasheet(): void
    {
        $card = $this->card('ARCASIO 732 616560 S1 P ESD', self::ARCASIO_PDF);
        $first = 'Półbuty bezpieczne ARCASIO 732 z wodoodporną cholewką i stalowym podnoskiem, podeszwa RAPTOR PU.2D.';
        $this->describedEarlier($card, $first);
        $this->answer = $this->answerWith('Półbuty bezpieczne ARCASIO 732 z cholewką z wegańskiej mikrofibry i stalowym '
            .'podnoskiem, podeszwa RAPTOR PU.2D, wkładka FLEXYUM, EN ISO 20345:2011 S1 P SRC.');

        // bez redo karta opisana z PDF nie jest zlecana ponownie
        $this->describe($card);
        $this->assertCount(0, $this->prompts);

        $this->describe($card, redo: true);

        $this->assertCount(1, $this->prompts);
        $card->refresh();
        $this->assertStringContainsString('wkładka FLEXYUM', (string) $card->description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame($first, $payload['b2b_sources']['previous_description']);
        $this->assertSame('Konstrukcja obuwia ARELAX®.', $payload['b2b_sources']['replaced_text']);
        $link = $this->linkOf($card);
        $this->assertSame(sha1((string) $card->description), $link->description_hash);
        $this->assertSame(sha1(''), $link->source_description_hash);
    }

    /** Job zapisany w kolejce przed dodaniem pola redo (produkcja 22.09.2026) odtwarza się z redo = false. */
    public function test_job_queued_before_the_redo_field_restores_without_it(): void
    {
        $data = (new DescribeB2bProductFromDatasheetJob(5, 7, true))->__serialize();
        unset($data['redo'], $data["\0*\0redo"]);
        $this->assertArrayNotHasKey('redo', $data);

        $restored = (new \ReflectionClass(DescribeB2bProductFromDatasheetJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($data);

        $this->assertFalse($restored->redo);
        $this->assertTrue($restored->datasheetOnly);
        $this->assertTrue((new DescribeB2bProductFromDatasheetJob(5, 7, true, true))->redo);
    }

    public function test_redo_leaves_a_restored_or_manual_description(): void
    {
        $card = $this->card('ARCASIO 732 616560 S1 P ESD', self::ARCASIO_PDF);
        $this->describedEarlier($card, 'Opis napisany z PDF przez job, później poprawiony.');
        // poprawka człowieka (albo przywrócenie) po opisie z PDF — odcisk powiązania już nie pasuje
        $card->update(['description' => 'Opis poprawiony ręcznie przez handlowca, dłuższy niż minimum.']);
        $this->answer = $this->answerWith('Półbuty bezpieczne ARCASIO 732 ze stalowym podnoskiem i podeszwą RAPTOR PU.2D.');

        $this->describe($card, redo: true);

        $this->assertCount(0, $this->prompts);
        $this->assertSame('Opis poprawiony ręcznie przez handlowca, dłuższy niż minimum.', $card->refresh()->description);
    }

    /** Łącznik z tekstem sklepu (Tegro, Polstar): ponowny opis bierze tekst sklepu ze śladu, gdy zgadza się odcisk źródła. */
    public function test_redo_with_shop_text_uses_the_recorded_shop_text(): void
    {
        $card = $this->card('RĘKAWICE G-REX F09 PLUS', "KARTA\nEN 388:2016+A1:2018 (4131A)");
        $shop = 'Rękawica ochronna kat. II z powłoką nitrylową.';
        $card->update([
            'description' => 'Rękawica ochronna z opisu joba, dłuższa niż tekst sklepu.',
            'enrichment_payload' => ['b2b_sources' => ['b2b_account_id' => $this->account()->id, 'shop_text' => $shop,
                'described_at' => now()->toIso8601String()]],
        ]);
        $link = $this->linkOf($card);
        $link->update(['description_hash' => sha1((string) $card->description), 'source_description_hash' => sha1($shop)]);
        $sheet = DescribeB2bProductFromDatasheetJob::datasheet((int) $card->id, (int) $this->account()->id);

        $this->assertNull(DescribeB2bProductFromDatasheetJob::sources($card, $link, $sheet));
        $start = DescribeB2bProductFromDatasheetJob::sources($card, $link, $sheet, false, true);
        $this->assertSame($shop, $start['shop_text'] ?? null);
        $this->assertSame(sha1($shop), $start['source_description_hash'] ?? null);

        $link->update(['source_description_hash' => sha1('inny tekst sklepu')]);
        $this->assertNull(DescribeB2bProductFromDatasheetJob::sources($card, $link->refresh(), $sheet, false, true));
    }

    public function test_command_preview_dispatches_nothing_and_apply_dispatches_redo(): void
    {
        $described = $this->card('ARCASIO 732 616560 S1 P ESD', self::ARCASIO_PDF);
        $this->describedEarlier($described, 'Półbuty bezpieczne ARCASIO 732 z wodoodporną cholewką i stalowym podnoskiem.');
        $manual = $this->card('ARMEN 9003 2360 S1', self::ARMEN_PDF, remoteId: 'armen-9003');
        $this->describedEarlier($manual, 'Opis z PDF przez job.');
        $manual->update(['description' => 'Opis poprawiony ręcznie przez handlowca, dłuższy niż minimum.']);
        Queue::fake();

        $this->artisan('b2b:redescribe-from-datasheets', ['--account' => $this->account()->id])
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('b2b:redescribe-from-datasheets', ['--account' => $this->account()->id, '--apply' => true])
            ->expectsOutputToContain('Zlecono ponowny opis z karty katalogowej PDF: 1 kart')
            ->assertSuccessful();
        Queue::assertPushed(DescribeB2bProductFromDatasheetJob::class, 1);
        Queue::assertPushed(DescribeB2bProductFromDatasheetJob::class, fn (DescribeB2bProductFromDatasheetJob $job): bool => $job->productId === (int) $described->id && $job->redo && $job->datasheetOnly);
    }

    public function test_command_refuses_an_account_without_a_datasheet_connector(): void
    {
        $account = B2bAccount::query()->create(['username' => 'inny', 'password' => 'haslo', 'sites' => ['example.test'], 'connector' => 'uvex', 'sync_images' => false]);
        Queue::fake();

        $this->artisan('b2b:redescribe-from-datasheets', ['--account' => $account->id, '--apply' => true])->assertFailed();
        Queue::assertNothingPushed();
    }

    /**
     * @param  list<string>  $specs
     * @param  list<string>  $useCases
     * @return array<string, mixed>
     */
    private function answerWith(string $description, array $specs = [], array $useCases = []): array
    {
        return [
            'description' => $description,
            'features' => [],
            'specs' => $specs,
            'norms' => [],
            'certificates' => [],
            'materials' => [],
            'use_cases' => $useCases,
            'source_urls' => [],
            'confidence' => 0.9,
        ];
    }

    /** Stan karty po wcześniejszym opisie z PDF przez job (przed zaostrzeniem kontroli). */
    private function describedEarlier(Product $card, string $description): void
    {
        $card->update([
            'description' => $description,
            'enrichment_payload' => ['b2b_sources' => [
                'b2b_account_id' => $this->account()->id,
                'shop_text' => '',
                'replaced_text' => 'Konstrukcja obuwia ARELAX®.',
                'described_at' => now()->subDay()->toIso8601String(),
            ]],
        ]);
        B2bProductLink::query()->where('product_id', $card->id)->update([
            'description_hash' => sha1($description),
            'source_description_hash' => sha1(''),
        ]);
    }

    private function describe(Product $card, bool $redo = false): void
    {
        DescribeB2bProductFromDatasheetJob::dispatchSync((int) $card->id, (int) $this->account()->id, true, $redo);
    }

    private function card(string $name, string $pdf, ?string $description = null, ?string $remoteId = null): Product
    {
        $card = Product::query()->create([
            'sku' => $name,
            'name' => $name,
            'manufacturer' => 'ARTRA',
            'description' => $description,
            'catalog_price_net' => 400,
            'purchase_price' => 250,
            'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account()->id,
            'remote_id' => $remoteId ?? str_replace(' ', '-', mb_strtolower($name)),
            'product_id' => $card->id,
            'remote_sku' => $name,
            'remote_name' => $name,
        ]);
        ProductDocument::query()->create([
            'product_id' => $card->id,
            'b2b_account_id' => $this->account()->id,
            'path' => 'products/'.$card->id.'/karta.pdf',
            'source_url' => 'https://artra.pl/cdn/shop/files/PL-KP-'.str_replace(' ', '_', $name).'.pdf',
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
            'text' => $pdf,
        ]);

        return $card;
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
}
