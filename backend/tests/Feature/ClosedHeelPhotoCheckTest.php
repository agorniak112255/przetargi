<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVisualCheck;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Catalog\ProductVisualFeatureCheck;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zabudowana pięta ze zdjęcia karty (decyzja właściciela z 25.09.2026): tylko gdy karta nie mówi o pięcie słowami,
 * tylko zdjęcie dostawcy/producenta, zapis osobno jako wniosek ze zdjęcia; wyszukiwarka pokazuje go osobnym polem
 * i dopisuje pochodzenie do uzasadnienia.
 */
final class ClosedHeelPhotoCheckTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Sandały ochronne S1 P ESD, wymagane: zabudowana pięta, podeszwa FO';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_heel_wording_on_card_both_ways_and_not_energy_absorption(): void
    {
        $pa = app(PpeAssortment::class);

        $this->assertSame('closed', $pa->heelWording($this->sandal('A', 'Sandał bezpieczny z zabudowaną piętą.')));
        $this->assertSame('closed', $pa->heelWording($this->sandal('B', 'Sandał z wysokim zapiętkiem TPU.')));
        $this->assertSame('open', $pa->heelWording($this->sandal('C', 'Sandał z paskiem na piętę.')));
        $this->assertNull($pa->heelWording($this->sandal('D', 'Sandał S1 SRC, absorpcja energii w części piętowej.')));
        // przegląd kodu 25.09.2026: mianownik „zapiętek”, granice zdań, przeczenie
        $this->assertSame('closed', $pa->heelWording($this->sandal('E', 'Wzmocniony zapiętek TPU.')));
        $this->assertSame('open', $pa->heelWording($this->sandal('F', 'Sandał bez zapiętka, z paskiem.')));
        $this->assertNull($pa->heelWording($this->sandal('G', 'Absorpcja energii w części piętowej. Zakryte palce.')));
        $this->assertNull($pa->heelWording($this->sandal('H', 'Absorpcja energii w części piętowej, otwarta konstrukcja cholewki.')));
        $this->assertNull($pa->heelWording($this->sandal('I', 'Nosek zamknięty, tylko do wnętrz.')));
    }

    public function test_requirement_mentions_heel_but_not_buckles(): void
    {
        $pa = app(PpeAssortment::class);

        $this->assertTrue($pa->mentionsHeel(self::REQUIREMENT));
        $this->assertTrue($pa->mentionsHeel('obuwie z zakrytą piętą'));
        $this->assertFalse($pa->mentionsHeel('Sandały z przypiętym paskiem, zapięcie na rzep'));
        $this->assertFalse($pa->mentionsHeel('Obuwie S3 SRC, absorpcja energii w części piętowej, podnosek stalowy'));
        $this->assertFalse($pa->mentionsHeel('Obuwie zabudowane, tylko do wnętrz'));
        $this->assertTrue($pa->mentionsHeel('Sandały ze wzmocnionym zapiętkiem'));
        $this->assertTrue($pa->mentionsHeel('Sandały z zakrytymi piętami'));
    }

    public function test_sandal_named_only_in_supplier_table_is_a_sandal(): void
    {
        $card = $this->sandal('G3444', 'Obuwie ochronne z cholewką z siatki.', 'Obuwie ochronne ARDON®BRIMSAN S1PS ESD');
        $card->forceFill(['shop_fields_summary' => 'Rodzaj obuwia: Sandał'])->save();

        $this->assertTrue(app(PpeAssortment::class)->isSandalCard($card));
    }

    public function test_photo_is_checked_and_saved_as_inference(): void
    {
        $card = $this->sandal('S-1', 'Sandał ochronny S1 P ESD.');
        $image = $this->supplierImage($card);
        $this->stubVision([['back' => 'full', 'shoe_visible' => true, 'what_i_see' => 'cholewka zamyka tył wokół pięty'], ['back' => 'full', 'strap_behind_heel' => false, 'shoe_visible' => true, 'what_i_see' => 'zamknięty tył, brak paska']]);

        $result = app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $this->assertNull($result['skip']);
        $check = ProductVisualCheck::query()->sole();
        $this->assertSame('closed', $check->answer);
        $this->assertSame($image->id, $check->product_image_id);
        $this->assertSame(ProductVisualFeatureCheck::PROMPT_VERSION, $check->prompt_version);
    }

    public function test_card_with_heel_wording_is_not_sent_to_the_model(): void
    {
        $card = $this->sandal('S-2', 'Sandał z zabudowaną piętą.');
        $this->supplierImage($card);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $this->assertStringContainsString('słowami', (string) $result['skip']);
        $this->assertSame(0, ProductVisualCheck::query()->count());
    }

    public function test_web_image_is_skipped_without_switch(): void
    {
        $card = $this->sandal('S-3', 'Sandał ochronny S1 P ESD.');
        $this->supplierImage($card, web: true);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->assertStringContainsString('z sieci', (string) app(ProductVisualFeatureCheck::class)->checkClosedHeel($card)['skip']);
    }

    public function test_answer_from_main_configuration_fallback_is_not_saved(): void
    {
        $card = $this->sandal('S-4', 'Sandał ochronny S1 P ESD.');
        $this->supplierImage($card);
        $this->stubVision([['back' => 'full', 'shoe_visible' => true, 'what_i_see' => 'cholewka zamyka tył wokół pięty'], ['back' => 'full', 'strap_behind_heel' => false, 'shoe_visible' => true, 'what_i_see' => 'zamknięty tył, brak paska']], fallback: true);

        $result = app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $this->assertStringContainsString('konfiguracji głównej', (string) $result['skip']);
        $this->assertSame(0, ProductVisualCheck::query()->count());
    }

    public function test_answer_outside_the_set_is_saved_as_unclear(): void
    {
        $card = $this->sandal('S-5', 'Sandał ochronny S1 P ESD.');
        $this->supplierImage($card);
        $this->stubVision([['back' => 'not_visible', 'shoe_visible' => false], ['back' => 'full', 'strap_behind_heel' => false, 'shoe_visible' => true, 'what_i_see' => 'zamknięty tył, brak paska']]);

        app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $this->assertSame('unclear', ProductVisualCheck::query()->sole()->answer);
    }

    public function test_lenient_answer_spelling_is_accepted(): void
    {
        $card = $this->sandal('S-10', 'Sandał ochronny S1 P ESD.');
        $this->supplierImage($card);
        $this->stubVision([['back' => ' Full ', 'shoe_visible' => 'true'], ['back' => 'FULL', 'strap_behind_heel' => 'false', 'shoe_visible' => true]]);

        app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $this->assertSame('closed', ProductVisualCheck::query()->sole()->answer);
    }

    public function test_answer_outside_schema_is_not_saved(): void
    {
        $card = $this->sandal('S-11', 'Sandał ochronny S1 P ESD.');
        $this->supplierImage($card);
        $this->stubVision([['back' => 'maybe', 'shoe_visible' => true], ['back' => 'full', 'strap_behind_heel' => false, 'shoe_visible' => true, 'what_i_see' => 'zamknięty tył, brak paska']]);

        $result = app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $this->assertTrue($result['attempted']);
        $this->assertSame(0, ProductVisualCheck::query()->count());
    }

    public function test_check_of_an_image_moved_to_another_card_is_not_used(): void
    {
        $a = $this->sandal('S-12', 'Sandał ochronny ESD.', 'Sandał ochronny ESD S-12');
        $b = $this->sandal('S-13', 'Sandał ochronny ESD.', 'Sandał ochronny ESD S-13');
        $image = $this->supplierImage($b);
        $this->saveCheck($a, $image, 'closed');
        $service = app(ProductAiSearchService::class);

        $this->assertSame([], (new \ReflectionMethod($service, 'photoHeelInferences'))->invoke($service, self::REQUIREMENT, null, [], collect([$a, $b])));
    }

    /**
     * Pomiar 25.09.2026 (14 zdjęć z oceną wzorcową): każde z dwóch pytań pomyliło po jednym klapku z paskiem za piętą,
     * każde inny — „zabudowana” tylko przy zgodzie obu i bez paska za piętą.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function twoQuestionAnswers(): iterable
    {
        $full = ['back' => 'full', 'shoe_visible' => true];
        $fullNoStrap = ['back' => 'full', 'strap_behind_heel' => false, 'shoe_visible' => true];
        yield 'oba pełny tył' => [$full, $fullNoStrap, 'closed'];
        yield 'drugie widzi sam pasek' => [$full, ['back' => 'strap', 'strap_behind_heel' => true, 'shoe_visible' => true], 'unclear'];
        yield 'pierwsze widzi sam pasek' => [['back' => 'strap', 'shoe_visible' => true], $fullNoStrap, 'unclear'];
        yield 'pełny tył, ale pasek za piętą' => [$full, ['back' => 'full', 'strap_behind_heel' => true, 'shoe_visible' => true], 'unclear'];
        yield 'oba pasek albo nic' => [['back' => 'none', 'shoe_visible' => true], ['back' => 'strap', 'strap_behind_heel' => true, 'shoe_visible' => true], 'open'];
        yield 'tyłu nie widać' => [['back' => 'not_visible', 'shoe_visible' => true], $fullNoStrap, 'unclear'];
    }

    /**
     * @param  array<string, mixed>  $back
     * @param  array<string, mixed>  $strap
     */
    #[DataProvider('twoQuestionAnswers')]
    public function test_closed_only_when_both_questions_agree(array $back, array $strap, string $expected): void
    {
        $this->assertSame($expected, app(ProductVisualFeatureCheck::class)->decide($back, $strap));
    }

    public function test_check_made_with_older_question_is_redone_and_not_used_in_search(): void
    {
        $card = $this->sandal('S-14', 'Sandał ochronny ESD.', 'Sandał ochronny ESD S-14');
        $image = $this->supplierImage($card);
        ProductVisualCheck::query()->create([
            'product_id' => $card->id,
            'product_image_id' => $image->id,
            'feature' => ProductVisualCheck::FEATURE_CLOSED_HEEL,
            'answer' => 'closed',
            'prompt_version' => 'heel-2026-09-25',
        ]);
        $service = app(ProductAiSearchService::class);

        $this->assertSame([], (new \ReflectionMethod($service, 'photoHeelInferences'))->invoke($service, self::REQUIREMENT, null, [], collect([$card])));
        $this->assertNull(app(ProductVisualFeatureCheck::class)->plan($card)['skip'], 'stara wersja pytania — do oceny od nowa');

        $this->stubVision([['back' => 'strap', 'shoe_visible' => true], ['back' => 'strap', 'strap_behind_heel' => true, 'shoe_visible' => true]]);
        app(ProductVisualFeatureCheck::class)->checkClosedHeel($card);

        $check = ProductVisualCheck::query()->sole();
        $this->assertSame('open', $check->answer);
        $this->assertSame(ProductVisualCheck::CURRENT_PROMPT_VERSION, $check->prompt_version);
    }

    public function test_dry_run_does_not_call_the_model_or_save(): void
    {
        $card = $this->sandal('S-6', 'Sandał ochronny S1 P ESD.');
        $this->supplierImage($card);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->artisan('products:check-closed-heel', ['--dry-run' => true])
            ->expectsOutputToContain('do oceny')
            ->assertSuccessful();
        $this->assertSame(0, ProductVisualCheck::query()->count());
    }

    public function test_command_refuses_without_image_model_profile(): void
    {
        $this->artisan('products:check-closed-heel')->assertFailed();
    }

    public function test_search_shows_photo_inference_only_for_heel_requirement_and_current_image(): void
    {
        $card = $this->sandal('S-7', 'Sandał ochronny ESD, podeszwa olejoodporna FO.', 'Sandał ochronny SB ESD S-7');
        $image = $this->supplierImage($card);
        $this->saveCheck($card, $image, 'closed');
        $service = app(ProductAiSearchService::class);
        $photo = new \ReflectionMethod($service, 'photoHeelInferences');

        $this->assertSame([$card->id => 'closed'], $photo->invoke($service, self::REQUIREMENT, null, [], collect([$card])));
        $this->assertSame([], $photo->invoke($service, 'Sandały ochronne S1 P ESD', null, [], collect([$card])));

        // nowe zdjęcie główne — ocena starego zdjęcia nie trafia do modelu
        $image->forceFill(['is_primary' => false])->save();
        $this->supplierImage($card, name: 'nowe.png');
        $this->assertSame([], $photo->invoke($service, self::REQUIREMENT, null, [], collect([$card])));
    }

    public function test_open_heel_on_s1_card_is_not_sent_to_the_model(): void
    {
        $card = $this->sandal('S-8', 'Sandał ochronny ESD.', 'Sandał ochronny S1 P ESD S-8');
        $this->saveCheck($card, $this->supplierImage($card), 'open');
        $service = app(ProductAiSearchService::class);

        $this->assertSame([], (new \ReflectionMethod($service, 'photoHeelInferences'))->invoke($service, self::REQUIREMENT, null, [], collect([$card])));
    }

    public function test_rank_card_gets_separate_field_and_reason_gets_provenance(): void
    {
        $card = $this->sandal('S-9', 'Sandał ochronny ESD, podeszwa olejoodporna FO.', 'Sandał ochronny ESD S-9');
        $this->saveCheck($card, $this->supplierImage($card), 'closed');
        $service = app(ProductAiSearchService::class);

        $messages = (new \ReflectionMethod($service, 'rankMessages'))->invoke(
            $service, self::REQUIREMENT, collect([$card]), 10, 'sandały ochronne', ['zabudowana pięta'], AiTask::ProductSearch,
        );
        $this->assertStringContainsString('"photo_inference":"zdjęcie karty: pięta zabudowana"', $messages[1]['content']);
        $this->assertStringContainsString('Pole photo_inference to wniosek modelu ze zdjęcia', $messages[0]['content']);
        $this->assertStringContainsString('"constraint_evidence":[]', $messages[1]['content'], 'wniosek ze zdjęcia nie jest cytatem z karty');

        $rows = (new \ReflectionMethod($service, 'rowsFromLlmMatches'))->invoke(
            $service, 'Sandały ochronne ESD, wymagane: zabudowana pięta', collect([$card]),
            ['matches' => [['id' => $card->id, 'score' => 92, 'reason' => 'Sandał S1 P ESD, FO.', 'missing_key' => []]]],
            10, 'sandały ochronne', ['needed' => 'sandały ochronne', 'constraints' => ['zabudowana pięta']],
        );
        $this->assertStringContainsString('Pięta zabudowana — wniosek modelu ze zdjęcia karty', (string) $rows[0]['ai_match_reason']);
    }

    private function sandal(string $sku, string $description, ?string $name = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name ?? 'Sandał ochronny '.$sku,
            'manufacturer' => 'ARTRA',
            'description' => $description,
            'catalog_price_net' => 100,
            'purchase_price' => 60,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }

    private function supplierImage(Product $product, bool $web = false, string $name = 'sandal.png'): ProductImage
    {
        $path = 'products/'.$product->id.'/'.$name;
        $png = imagecreatetruecolor(300, 300);
        ob_start();
        imagepng($png);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        $account = $web ? null : B2bAccount::query()->firstOrCreate(['username' => 'ARTRA'], ['sites' => ['artra.pl'], 'connector' => 'artra']);

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $account?->id,
            'path' => $path,
            'source_url' => 'https://artra.pl/img/'.$name,
            'is_primary' => true,
            'sort_order' => 0,
        ]);
    }

    private function saveCheck(Product $product, ProductImage $image, string $answer): void
    {
        ProductVisualCheck::query()->create([
            'product_id' => $product->id,
            'product_image_id' => $image->id,
            'feature' => ProductVisualCheck::FEATURE_CLOSED_HEEL,
            'answer' => $answer,
            'prompt_version' => ProductVisualFeatureCheck::PROMPT_VERSION,
        ]);
    }

    /**
     * Kolejne odpowiedzi modelu obrazu: pytanie o budowę tyłu, potem o tył i pasek za piętą.
     *
     * @param  list<array<string, mixed>>  $answers
     */
    private function stubVision(array $answers, bool $fallback = false): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function () use (&$answers, $fallback): array {
            app(AiServedProviderTally::class)->recordJsonOrigin(['model' => 'vision-test', 'provider' => null, 'profile' => 'Obraz', 'fallback' => $fallback]);

            return (array) array_shift($answers);
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
