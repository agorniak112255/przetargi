<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * b2b:restore-replaced-descriptions — przywracanie opisów kart zastąpionych sloganem ARTRY („Konstrukcja obuwia
 * ARELAX®…” na każdej karcie sklepu). Dane SYNTETYCZNE, w układzie z produkcji 22.09.2026: slogan w opisie, odcisk
 * powiązania = sha1(sloganu), poprzedni opis AI w enrichment_payload.replaced_description.
 */
final class B2bRestoreReplacedDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SLOGAN = 'Konstrukcja obuwia ARELAX® zapewnia przestrzeń dla wszystkich palców i swobodę ruchu przez cały dzień.';

    private B2bAccount $account;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->account = B2bAccount::query()->create(['username' => 'ARTRA', 'sites' => ['artra.pl'], 'connector' => 'artra']);
        $this->backup = storage_path('framework/testing/restore-replaced-'.uniqid().'.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->backup);
        parent::tearDown();
    }

    public function test_preview_changes_nothing(): void
    {
        $cards = $this->sloganCards();

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id])
            ->expectsOutputToContain('Podgląd')
            ->assertSuccessful();

        foreach ($cards as $card) {
            $this->assertSame(self::SLOGAN, $card->refresh()->description);
        }
        $this->assertFileDoesNotExist($this->backup);
    }

    public function test_apply_restores_the_previous_description_and_leaves_the_link_hash_foreign(): void
    {
        $cards = $this->sloganCards();

        $this->apply();

        $card = $cards[0]->refresh();
        $this->assertSame($this->aiText('ARMEN 900 6060 S1 P', 'S1 P'), $card->description);
        $payload = (array) $card->enrichment_payload;
        $this->assertSame(self::SLOGAN, $payload['replaced_description']);
        $this->assertSame('b2b:restore-replaced-descriptions', $payload['replaced_description_by']);
        // synchronizacja ani job opisu z PDF nie uznają przywróconego opisu za swój
        $hash = B2bProductLink::query()->where('product_id', $card->id)->value('description_hash');
        $this->assertNotSame(sha1((string) $card->description), $hash);
        $this->assertStringContainsString('armen', mb_strtolower((string) $card->search_blob));
        $this->assertFileExists($this->backup);
    }

    public function test_previous_description_with_a_class_of_another_base_is_not_restored(): void
    {
        $cards = $this->sloganCards();
        // opis AI ze strony wariantu O1 FO tego samego modelu; właściwa klasa w nazwie, cudza w zdaniu o normie
        $conflict = $this->sloganCard('ARMEN 900 6060 S1 P ESD', 'Półbuty ARMEN 900 S1 P ESD z wygodną cholewką i miękką '
            .'wyściółką. Spełniają normę EN ISO 20347:2012 O1 FO, bez podnoska ochronnego.');

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id])
            ->expectsOutputToContain('poprzedni opis podaje klasę O1')
            ->assertSuccessful();
        $this->apply();

        $this->assertSame(self::SLOGAN, $conflict->refresh()->description);
        $this->assertNotSame(self::SLOGAN, $cards[0]->refresh()->description);
    }

    public function test_same_base_class_spelled_differently_is_not_a_conflict(): void
    {
        $this->sloganCards();
        $card = $this->sloganCard('ARYEL 320 618080 S1 PL ESD', 'Półbuty ARYEL 320 w klasie S1P ESD z podnoskiem kompozytowym, '
            .'wkładką antyprzebiciową i podeszwą odporną na poślizg.');

        $this->apply();

        $this->assertStringStartsWith('Półbuty ARYEL 320', (string) $card->refresh()->description);
    }

    public function test_card_without_previous_description_is_listed_for_the_datasheet(): void
    {
        $this->sloganCards();
        $bare = $this->sloganCard('ARAGON 920 6060 S2', null);

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id])
            ->expectsOutputToContain('Do opisu z PDF / ponownego wzbogacenia')
            ->assertSuccessful();
        $this->apply();

        $this->assertSame(self::SLOGAN, $bare->refresh()->description);
    }

    public function test_text_shared_by_fewer_than_five_models_is_left_alone(): void
    {
        // warianty rozmiaru jednego modelu (Anro, Ardon) dzielą prawdziwy opis — nie w takiej liczbie
        $cards = [];
        foreach (['S1 P', 'S1 P 38', 'S1 P 39', 'S1 P 40'] as $i => $suffix) {
            $cards[] = $this->sloganCard('ARMEN 900 6060 '.$suffix.' #'.$i, $this->aiText('ARMEN 900 6060 '.$suffix, 'S1 P'));
        }

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Brak kart')
            ->assertSuccessful();

        foreach ($cards as $card) {
            $this->assertSame(self::SLOGAN, $card->refresh()->description);
        }
    }

    public function test_description_changed_by_a_human_is_not_a_candidate(): void
    {
        $cards = $this->sloganCards();
        $cards[0]->update(['description' => self::SLOGAN.' Poprawione ręcznie.']);

        $this->apply();

        $this->assertSame(self::SLOGAN.' Poprawione ręcznie.', $cards[0]->refresh()->description);
    }

    public function test_restore_undoes_the_apply(): void
    {
        $cards = $this->sloganCards();
        $payloadBefore = $cards[1]->refresh()->enrichment_payload;
        $this->apply();
        // karta zmieniona po przywróceniu (np. opis z PDF) zostaje, jak jest
        $cards[2]->refresh()->update(['description' => 'Opis napisany później z karty produktu PDF, dłuższy niż minimum.']);

        $this->artisan('b2b:restore-replaced-descriptions', ['--restore' => $this->backup])
            ->expectsOutputToContain('Cofnięto zmiany: 5 kart.')
            ->assertSuccessful();

        $this->assertSame(self::SLOGAN, $cards[0]->refresh()->description);
        $this->assertSame(self::SLOGAN, $cards[1]->refresh()->description);
        $this->assertEquals($payloadBefore, $cards[1]->enrichment_payload);
        $this->assertSame('Opis napisany później z karty produktu PDF, dłuższy niż minimum.', $cards[2]->refresh()->description);
    }

    public function test_card_with_a_datasheet_gets_a_datasheet_description_instead_of_the_old_ai_text(): void
    {
        $cards = $this->sloganCards();
        ProductDocument::query()->create([
            'product_id' => $cards[0]->id,
            'b2b_account_id' => $this->account->id,
            'path' => 'products/'.$cards[0]->id.'/karta.pdf',
            'source_url' => 'https://artra.pl/cdn/shop/files/pl-kp-armen-900-6060-s1-p.pdf',
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
            'text' => "ARMEN 900 6060 S1 P\nSandały ochronne z podnoskiem stalowym.\nEN ISO 20345:2011 S1 P SRC",
        ]);

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--show' => $cards[0]->id])
            ->expectsOutputToContain('zlecenie opisu z karty katalogowej PDF')
            ->assertSuccessful();
        $this->apply();

        // decyzja użytkownika 22.09.2026: opis ARTRY wyłącznie z PDF — slogan czeka na zapis joba, opis AI nie wraca
        $this->assertSame(self::SLOGAN, $cards[0]->refresh()->description);
        Queue::assertPushed(
            DescribeB2bProductFromDatasheetJob::class,
            static fn (DescribeB2bProductFromDatasheetJob $job): bool => $job->productId === $cards[0]->id,
        );
        Queue::assertPushed(DescribeB2bProductFromDatasheetJob::class, 1);
        // karta bez PDF-u wraca do poprzedniego opisu
        $this->assertSame($this->aiText('ARCASIO 732 616560 S1 P ESD', 'S1 P'), $cards[1]->refresh()->description);
    }

    public function test_slogan_with_appended_datasheet_text_is_not_a_previous_description(): void
    {
        $this->sloganCards();
        // 16 kart ARTRY z produkcji: w replaced_description slogan + surowy tekst PDF doklejony przez synchronizację
        $glued = $this->sloganCard('ARDEA 310 618080 S1 PL ESD', self::SLOGAN."\n\nZ karty technicznej (Karta produktu):\n"
            .'KARTA PRODUKTU ARDEA 310 618080 S1 PL ESD cholewka wegańska podnosek kompozytowy EN ISO 20345:2022 S1 PL FO SR');
        $markOnly = $this->sloganCard('ARGON 330 618080 S3', "Z karty technicznej (Karta produktu):\nKARTA PRODUKTU ARGON 330 "
            .'618080 S3 cholewka wegańska podnosek kompozytowy wkładka antyprzebiciowa EN ISO 20345:2022 S3 SRC');

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--show' => $glued->id])
            ->expectsOutputToContain('tekst wspólny sklepu albo tekst karty technicznej')
            ->assertSuccessful();
        $this->apply();

        $this->assertSame(self::SLOGAN, $glued->refresh()->description);
        $this->assertSame(self::SLOGAN, $markOnly->refresh()->description);
    }

    public function test_variants_of_one_model_do_not_reach_the_threshold(): void
    {
        // sześć kart, ale jeden model (rozmiary i klasy ARMEN 900) — wspólny opis wariantów, nie slogan sklepu
        $cards = [];
        foreach (['ARMEN 900 6060 S1 P', 'ARMEN 900 6060 O1 FO', 'ARMEN 900 6060 S1 P 41', 'ARMEN 900 6060 S1 P 42',
            'ARMEN 900 6060 S1 P 43', 'ARMEN 900 6060 S1 P 44'] as $sku) {
            $cards[] = $this->sloganCard($sku, $this->aiText($sku, 'S1 P'));
        }

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('Brak kart')
            ->assertSuccessful();

        foreach ($cards as $card) {
            $this->assertSame(self::SLOGAN, $card->refresh()->description);
        }
    }

    public function test_account_of_another_connector_needs_an_explicit_flag(): void
    {
        $this->account->update(['connector' => 'tegro', 'sites' => ['tegro.pl']]);
        $cards = $this->sloganCards();

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--apply' => true, '--backup' => $this->backup])
            ->expectsOutputToContain('--any-connector')
            ->assertFailed();
        $this->assertSame(self::SLOGAN, $cards[0]->refresh()->description);

        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--apply' => true,
            '--backup' => $this->backup, '--any-connector' => true])
            ->assertSuccessful();
        $this->assertSame($this->aiText('ARMEN 900 6060 S1 P', 'S1 P'), $cards[0]->refresh()->description);
    }

    public function test_card_whose_datasheet_description_was_rejected_gets_no_new_order(): void
    {
        $cards = $this->sloganCards();
        $document = ProductDocument::query()->create([
            'product_id' => $cards[0]->id,
            'b2b_account_id' => $this->account->id,
            'path' => 'products/'.$cards[0]->id.'/karta.pdf',
            'source_url' => 'https://artra.pl/cdn/shop/files/pl-kp-armen-900-6060-s1-p.pdf',
            'title' => 'Karta produktu',
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 1,
            'text' => "ARMEN 900 6060 S1 P\nSandały ochronne z podnoskiem stalowym.\nEN ISO 20345:2011 S1 P SRC",
        ]);
        // job opisu z PDF już próbował do limitu: model nie zwrócił opisu, ślad odrzucenia dla tych samych źródeł
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->times(DescribeB2bProductFromDatasheetJob::MAX_REJECTED_ATTEMPTS)
            ->andReturn(['description' => '']);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        for ($i = 0; $i < DescribeB2bProductFromDatasheetJob::MAX_REJECTED_ATTEMPTS; $i++) {
            (new DescribeB2bProductFromDatasheetJob((int) $cards[0]->id, (int) $this->account->id, true))
                ->handle(app(ProductEnrichmentService::class), app(EnrichmentSlots::class));
        }
        $this->assertSame(
            DescribeB2bProductFromDatasheetJob::MAX_REJECTED_ATTEMPTS,
            ((array) $cards[0]->refresh()->enrichment_payload)['b2b_sources_rejected']['attempts'],
        );
        $this->assertNotNull($document->refresh()->text);

        $this->apply();

        Queue::assertNotPushed(DescribeB2bProductFromDatasheetJob::class);
        $this->assertSame($this->aiText('ARMEN 900 6060 S1 P', 'S1 P'), $cards[0]->refresh()->description);
    }

    private function apply(): void
    {
        $this->artisan('b2b:restore-replaced-descriptions', ['--account' => $this->account->id, '--apply' => true, '--backup' => $this->backup])
            ->assertSuccessful();
    }

    /**
     * Sześć kart ze sloganem i poprawnym poprzednim opisem.
     *
     * @return list<Product>
     */
    private function sloganCards(): array
    {
        $cards = [];
        foreach (['ARMEN 900 6060 S1 P' => 'S1 P', 'ARCASIO 732 616560 S1 P ESD' => 'S1 P', 'ARAGON 920 6060 S3' => 'S3',
            'ARAUKAN 940 1010 O2 FO' => 'O2', 'ARDEUS 350 618080 S3L' => 'S3', 'ARYA 300 671460 S1 PL' => 'S1PL'] as $sku => $class) {
            $cards[] = $this->sloganCard($sku, $this->aiText($sku, $class));
        }

        return $cards;
    }

    private function sloganCard(string $sku, ?string $replaced): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
            'description' => self::SLOGAN,
            'enrichment_payload' => $replaced === null ? ['features' => []] : [
                'features' => ['podnosek'],
                'replaced_description' => $replaced,
                'replaced_description_at' => '2026-09-20T10:00:00+02:00',
                'replaced_description_hash' => sha1(self::SLOGAN),
            ],
            'catalog_price_net' => 400,
            'purchase_price' => 250,
            'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id,
            'remote_id' => 'handle-'.$card->id,
            'product_id' => $card->id,
            'remote_sku' => $sku,
            'remote_name' => $sku,
            'description_hash' => sha1(self::SLOGAN),
        ]);

        return $card;
    }

    private function aiText(string $sku, string $class): string
    {
        return 'Obuwie bezpieczne '.$sku.' w klasie '.$class.' według EN ISO 20345, z wygodną cholewką, podeszwą odporną '
            .'na poślizg i wyściółką pochłaniającą wilgoć.';
    }
}
