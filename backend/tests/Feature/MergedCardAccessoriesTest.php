<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Catalog\CardMatchFinder;
use App\Services\Catalog\CardMatchMerger;
use App\Services\Presta\PrestaExportGateway;
use App\Services\Presta\PrestaProductExportService;
use App\Services\PriceListImportService;
use App\Services\ProductKitService;
use App\Services\ProductSizeMergeService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePrestaExportGateway;
use Tests\TestCase;

/**
 * Akcesoria innych kart wskazujące kartę usuwaną przy scalaniu (plan łączenia kart, etap C2 pkt 5): wskazanie
 * przechodzi na kartę, która zostaje — „Połącz” (mergeDuplicate), products:merge-duplicate, automatyczne łączenie
 * rozmiarów (merge) i łączenie rozmiarów z ekranu (mergeSizeCards). Dotąd usunięcie karty zerowało related_product_id
 * po cichu (nullOnDelete). Akcesorium samej siebie i powtórzone ręczne akcesorium znikają, numer Presty podpięty
 * w sklepie zostaje (eksport akcesoriów tylko dopisuje — nowy numer zdublowałby odnośnik w sklepie).
 */
final class MergedCardAccessoriesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> karta dystrybutora => karta producenta, którą potwierdza atrapa evaluate() */
    private array $targets = [];

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // kopia zapasowa „Połącz” w katalogu tego testu — testy równoległe mają te same numery propozycji
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'merged-card-accessories-'.uniqid('', true);
        mkdir($this->storage.DIRECTORY_SEPARATOR.'app', 0775, true);
        $this->app->useStoragePath($this->storage);

        $test = $this;
        $this->app->instance(CardMatchFinder::class, new class($test)
        {
            public function __construct(private readonly MergedCardAccessoriesTest $test) {}

            /** @return array<string, mixed>|null */
            public function evaluate(Product $source): ?array
            {
                $target = $this->test->targetFor((int) $source->id);

                return $target === null ? null : ['status' => 'pending', 'kind' => 'merge', 'target_product_id' => $target];
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function targetFor(int $sourceId): ?int
    {
        return $this->targets[$sourceId] ?? null;
    }

    public function test_card_match_merge_repoints_accessories_of_other_cards_and_backs_them_up(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);
        $mask = Product::query()->create(['sku' => 'IF/017', 'name' => 'Półmaska ANRO IF/017', 'manufacturer' => 'ANRO']);
        $kit = Product::query()->create(['sku' => 'ZEST-1', 'name' => 'Zestaw ochrony dróg oddechowych', 'manufacturer' => 'SUPON']);
        // z opisu karty: kod P4S trafił w kartę dystrybutora, eksport podpiął w sklepie produkt Presty 501
        $fromPage = $this->accessory($mask, $source, ProductAccessory::SOURCE_ENRICHMENT, 's:zppv99c', [
            'related_sku' => 'ZPPV99C', 'method' => 'sku', 'score' => 96, 'presta_related_id' => 501,
        ]);
        // relacja z Presty: numer dziecka to numer z samej Presty
        $fromPresta = $this->accessory($kit, $source, ProductAccessory::SOURCE_PRESTA, 'p:777', [
            'presta_parent_id' => 12, 'presta_related_id' => 777, 'method' => 'presta_id', 'score' => 99,
        ]);
        // ta sama karta wskazywała już kartę producenta (po EAN) — inny dowód, zostaje obok
        $byEan = $this->accessory($mask, $target, ProductAccessory::SOURCE_ENRICHMENT, 'e:5901234567890', [
            'related_ean' => '5901234567890', 'method' => 'ean', 'score' => 98,
        ]);
        $tender = $this->tender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Filtr do półmaski', 'companion_product_id' => $source->id,
        ]);

        app(CardMatchMerger::class)->merge($candidate, $user);

        $this->assertNull(Product::query()->find($source->id));
        $page = $fromPage->fresh();
        $this->assertSame((int) $target->id, $page->related_product_id);
        $this->assertSame('s:zppv99c', $page->link_key);
        $this->assertSame('sku', $page->method);
        $this->assertSame('ZPPV99C', $page->related_sku);
        // produkt Presty, który sklep ma już podpięty, zostaje
        $this->assertSame(501, $page->presta_related_id);
        $presta = $fromPresta->fresh();
        $this->assertSame((int) $target->id, $presta->related_product_id);
        $this->assertSame(777, $presta->presta_related_id);
        $this->assertSame('p:777', $presta->link_key);
        $this->assertSame((int) $target->id, $byEan->fresh()->related_product_id);
        $this->assertSame(3, ProductAccessory::query()->count());
        $this->assertSame((int) $target->id, (int) $item->fresh()->companion_product_id);

        // kopia zapasowa: wiersze akcesoriów i produkt dodatkowy sprzed scalenia
        $backup = json_decode((string) file_get_contents((string) $candidate->fresh()->backup_path), true, 512, JSON_THROW_ON_ERROR);
        $saved = array_column($backup['product_accessories'], null, 'id');
        $this->assertSame([$fromPage->id, $fromPresta->id, $byEan->id], array_keys($saved));
        $this->assertSame($source->id, $saved[$fromPage->id]['related_product_id']);
        $this->assertSame(501, $saved[$fromPage->id]['presta_related_id']);
        $this->assertSame($source->id, $saved[$fromPresta->id]['related_product_id']);
        $this->assertSame([$item->id], array_column($backup['tender_items_companion'], 'id'));
        $this->assertSame($source->id, $backup['tender_items_companion'][0]['companion_product_id']);
    }

    public function test_card_match_merge_drops_self_accessory_and_repeated_manual_link(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);
        // karta producenta miała kartę dystrybutora (ten sam wyrób) za akcesorium — po scaleniu wskazywałaby samą siebie
        $self = $this->accessory($target, $source, ProductAccessory::SOURCE_ENRICHMENT, 's:zppv99c', ['related_sku' => 'ZPPV99C', 'method' => 'sku']);
        $harness = Product::query()->create(['sku' => 'SZ-1', 'name' => 'Szelki', 'manufacturer' => 'ANRO']);
        $manual = $this->manual($harness, $source);
        $helmet = Product::query()->create(['sku' => 'H-1', 'name' => 'Hełm', 'manufacturer' => 'ANRO']);
        $kept = $this->manual($helmet, $target);
        $repeated = $this->manual($helmet, $source);

        app(CardMatchMerger::class)->merge($candidate, $user);

        $this->assertNull($self->fresh());
        $this->assertSame(0, ProductAccessory::query()->where('product_id', $target->id)->count());
        $moved = $manual->fresh();
        $this->assertSame((int) $target->id, $moved->related_product_id);
        $this->assertSame('m:'.$target->id, $moved->link_key);
        $this->assertSame(ProductAccessory::SOURCE_MANUAL, $moved->source);
        // hełm miał już ręcznie dodaną kartę producenta — przepięty wiersz byłby jej powtórzeniem
        $this->assertNull($repeated->fresh());
        $this->assertNotNull($kept->fresh());
        $this->assertSame([(int) $target->id], ProductAccessory::query()->where('product_id', $helmet->id)->pluck('related_product_id')->all());
    }

    public function test_presta_export_after_merge_keeps_linked_shop_product_and_does_not_export_kept_card(): void
    {
        $presta = new FakePrestaExportGateway;
        $this->app->instance(PrestaExportGateway::class, $presta);
        $user = User::factory()->create();
        // karta producenta bez dopasowania do Presty — nowy numer akcesorium oznaczałby jej eksport do sklepu
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);
        $mask = Product::query()->create([
            'sku' => 'IF/017', 'name' => 'Półmaska ANRO IF/017', 'manufacturer' => 'ANRO', 'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 1,
        ]);
        // 501 = produkt Presty podpięty w sklepie przy wcześniejszym eksporcie, znaleziony po kodzie karty dystrybutora
        $row = $this->accessory($mask, $source, ProductAccessory::SOURCE_ENRICHMENT, 's:zppv99c', [
            'related_sku' => 'ZPPV99C', 'method' => 'sku', 'score' => 96, 'presta_related_id' => 501,
        ]);

        app(CardMatchMerger::class)->merge($candidate, $user);
        app(PrestaProductExportService::class)->export($mask->fresh());

        // sklep: ten sam odnośnik akcesorium, bez zakładania ani nadpisywania produktu karty producenta
        $this->assertSame([501], $presta->accessories[0]['items']);
        $this->assertSame(['IF/017'], array_column($presta->created, 'reference'));
        $this->assertSame([], $presta->updated);
        $this->assertSame(501, $row->fresh()->presta_related_id);
        // zestaw na karcie: pozycja prowadzi do karty producenta i dalej wie, że jest w Preście
        $kit = app(ProductKitService::class)->present($mask->fresh());
        $this->assertCount(1, $kit);
        $this->assertSame((int) $target->id, $kit[0]['related_product_id']);
        $this->assertSame('IF/016/F/PS', $kit[0]['sku']);
        $this->assertSame(501, $kit[0]['presta_id']);
        $this->assertTrue($kit[0]['matched']);
    }

    public function test_automatic_size_merge_repoints_accessories_to_kept_size_card(): void
    {
        [$kept, $small, $large] = $this->ansellSizes();
        $dispenser = Product::query()->create(['sku' => 'DOZ-1', 'name' => 'Dozownik rękawic', 'manufacturer' => 'Canis']);
        $fromPresta = $this->accessory($dispenser, $small, ProductAccessory::SOURCE_PRESTA, 'p:10', ['presta_related_id' => 10, 'method' => 'presta_id']);
        $manualKept = $this->manual($dispenser, $kept);
        $manualLarge = $this->manual($dispenser, $large);
        $station = Product::query()->create(['sku' => 'ST-1', 'name' => 'Stacja rękawic', 'manufacturer' => 'Canis']);
        $manualSmall = $this->manual($station, $small);
        $manualLarge2 = $this->manual($station, $large);
        // karta, która zostaje, wskazywała inny rozmiar — po scaleniu to ona sama
        $self = $this->accessory($kept, $small, ProductAccessory::SOURCE_ENRICHMENT, 's:37695vp070', ['related_sku' => '37695VP070', 'method' => 'sku']);

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(2, $result['deleted']);
        $this->assertSame([], $result['errors']);
        $this->assertNull($small->fresh());
        $this->assertNull($large->fresh());
        $presta = $fromPresta->fresh();
        $this->assertSame((int) $kept->id, $presta->related_product_id);
        $this->assertSame(10, $presta->presta_related_id);
        // dozownik miał ręcznie oba rozmiary — zostaje jedno ręczne wskazanie karty modelu
        $this->assertNotNull($manualKept->fresh());
        $this->assertNull($manualLarge->fresh());
        $this->assertSame(['m:'.$kept->id, 'p:10'], ProductAccessory::query()->where('product_id', $dispenser->id)->orderBy('link_key')->pluck('link_key')->all());
        // stacja miała ręcznie dwa usunięte rozmiary — pierwszy przechodzi na kartę modelu, drugi byłby powtórzeniem
        $this->assertSame('m:'.$kept->id, $manualSmall->fresh()->link_key);
        $this->assertSame((int) $kept->id, $manualSmall->fresh()->related_product_id);
        $this->assertNull($manualLarge2->fresh());
        $this->assertNull($self->fresh());
    }

    public function test_size_cards_merge_repoints_accessories_to_model_card(): void
    {
        [$s, $m, $l] = $this->halfMasks();
        $filter = Product::query()->create(['sku' => '6035', 'name' => 'Filtr 3M 6035 P3', 'manufacturer' => '3M']);
        $row = $this->accessory($filter, $m, ProductAccessory::SOURCE_ENRICHMENT, 's:7000146847', [
            'related_sku' => '7000146847', 'method' => 'sku', 'presta_related_id' => 44,
        ]);

        app(ProductSizeMergeService::class)->mergeSizeCards($s, [$m, $l], '6X00 Półmaska 3M 6000', 'Rozmiary: S; M; L');

        $fresh = $row->fresh();
        $this->assertSame((int) $s->id, $fresh->related_product_id);
        $this->assertSame('s:7000146847', $fresh->link_key);
        $this->assertSame('7000146847', $fresh->related_sku);
        $this->assertSame(44, $fresh->presta_related_id);
    }

    public function test_merge_duplicate_command_previews_and_repoints_accessory(): void
    {
        $keep = Product::query()->create(['sku' => '9169.5', 'name' => 'Okulary UVEX super f OTG 9169.541', 'manufacturer' => 'UVEX']);
        $drop = Product::query()->create(['sku' => '9169.541', 'name' => 'Okulary UVEX SUPER f OTG 9169', 'manufacturer' => 'UVEX']);
        $case = Product::query()->create(['sku' => '9954.500', 'name' => 'Etui na okulary UVEX', 'manufacturer' => 'UVEX']);
        $row = $this->accessory($case, $drop, ProductAccessory::SOURCE_ENRICHMENT, 's:9169541', ['related_sku' => '9169.541', 'method' => 'sku']);
        $pair = [$keep->id.':'.$drop->id];

        $this->artisan('products:merge-duplicate', ['--pair' => $pair])
            ->expectsOutputToContain('jako akcesorium innych kart: 1')
            ->expectsOutputToContain('Podgląd: 1 par do scalenia.')
            ->assertSuccessful();
        $this->assertSame((int) $drop->id, $row->fresh()->related_product_id);

        $this->artisan('products:merge-duplicate', ['--pair' => $pair, '--apply' => true])
            ->expectsOutputToContain('Scalono 1 par.')
            ->assertSuccessful();

        $this->assertNull($drop->fresh());
        $this->assertSame((int) $keep->id, $row->fresh()->related_product_id);
    }

    /**
     * Własne akcesoria karty dystrybutora (product_id) przechodzą na kartę producenta zamiast zniknąć kaskadą —
     * decyzja właściciela z 25.09.2026 („tak, przenoś”); „Połącz” już przy nich nie odmawia.
     */
    public function test_card_match_merge_moves_own_accessories_of_distributor_card(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);
        $filter = Product::query()->create(['sku' => 'FP3', 'name' => 'Filtr ANRO P3', 'manufacturer' => 'ANRO']);
        $bag = Product::query()->create(['sku' => 'TOR-1', 'name' => 'Torba na półmaskę', 'manufacturer' => 'ANRO']);
        $strap = Product::query()->create(['sku' => 'PAS-1', 'name' => 'Pasek nagłowia', 'manufacturer' => 'ANRO']);
        // z opisu karty dystrybutora: filtr po kodzie, z numerem Presty podpiętym w sklepie
        $fromPage = $this->accessory($source, $filter, ProductAccessory::SOURCE_ENRICHMENT, 's:fp3', [
            'related_sku' => 'FP3', 'method' => 'sku', 'score' => 96, 'presta_related_id' => 601,
        ]);
        // relacja z Presty: numery rodzica i dziecka to numery sklepu — zostają jako ślad źródła
        $fromPresta = $this->accessory($source, $bag, ProductAccessory::SOURCE_PRESTA, 'p:602', [
            'presta_parent_id' => 13, 'presta_related_id' => 602, 'method' => 'presta_id', 'score' => 99,
        ]);
        $manual = $this->manual($source, $strap);
        // niedopasowane (bez karty) — dowód ze strony też przechodzi
        $pending = ProductAccessory::query()->create([
            'product_id' => $source->id, 'related_product_id' => null, 'source' => ProductAccessory::SOURCE_ENRICHMENT,
            'link_key' => 's:zzz900', 'related_sku' => 'ZZZ900', 'method' => 'pending',
        ]);
        // karta dystrybutora miała kartę producenta za akcesorium — po scaleniu byłoby to samo-powiązanie
        $self = $this->accessory($source, $target, ProductAccessory::SOURCE_ENRICHMENT, 's:iff016fps', ['related_sku' => 'IF/016/F/PS', 'method' => 'sku']);

        app(CardMatchMerger::class)->merge($candidate, $user);

        $this->assertNull(Product::query()->find($source->id));
        $this->assertSame(CardMatchCandidate::STATUS_MERGED, $candidate->fresh()->status);
        foreach ([$fromPage, $fromPresta, $manual, $pending] as $row) {
            $this->assertSame((int) $target->id, $row->fresh()?->product_id, 'wiersz #'.$row->id.' został na karcie producenta');
        }
        $page = $fromPage->fresh();
        $this->assertSame((int) $filter->id, $page->related_product_id);
        $this->assertSame('s:fp3', $page->link_key);
        $this->assertSame(601, $page->presta_related_id);
        $presta = $fromPresta->fresh();
        $this->assertSame(13, $presta->presta_parent_id);
        $this->assertSame(602, $presta->presta_related_id);
        $this->assertSame('p:602', $presta->link_key);
        $this->assertSame('m:'.$strap->id, $manual->fresh()->link_key);
        $this->assertNull($pending->fresh()->related_product_id);
        $this->assertNull($self->fresh());
        $this->assertSame(4, ProductAccessory::query()->count());
    }

    /**
     * Ta sama para karta → akcesorium nie dubluje się: zostaje jeden wiersz — ręczny przed Prestą przed wzbogacaniem
     * (reset wzbogacania kasuje wiersze „enrichment”, więc ręczna decyzja nie może ustąpić wierszowi z sieci), przy
     * remisie wiersz karty, która zostaje. Ten sam dowód (link_key, klucz UNIQUE) — zostaje wiersz z dopasowaną kartą.
     */
    public function test_card_match_merge_does_not_duplicate_own_accessories(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);
        $filter = Product::query()->create(['sku' => 'FP3', 'name' => 'Filtr ANRO P3', 'manufacturer' => 'ANRO']);
        $bag = Product::query()->create(['sku' => 'TOR-1', 'name' => 'Torba na półmaskę', 'manufacturer' => 'ANRO']);
        $strap = Product::query()->create(['sku' => 'PAS-1', 'name' => 'Pasek nagłowia', 'manufacturer' => 'ANRO']);
        $visor = Product::query()->create(['sku' => 'OSL-1', 'name' => 'Osłona filtra', 'manufacturer' => 'ANRO']);
        // filtr: obie karty z sieci (inne dowody) — zostaje wiersz karty producenta
        $keptFilter = $this->accessory($target, $filter, ProductAccessory::SOURCE_ENRICHMENT, 'e:5900000000011', ['method' => 'ean']);
        $dupFilter = $this->accessory($source, $filter, ProductAccessory::SOURCE_ENRICHMENT, 's:fp3', ['method' => 'sku']);
        // torba: karta producenta z sieci, dystrybutora ręcznie — zostaje ręczny
        $weakBag = $this->accessory($target, $bag, ProductAccessory::SOURCE_ENRICHMENT, 's:tor1', ['method' => 'sku']);
        $manualBag = $this->manual($source, $bag);
        // ten sam numer Presty: na karcie producenta bez karty, na karcie dystrybutora dopasowany — zostaje dopasowany
        $unmatched = ProductAccessory::query()->create([
            'product_id' => $target->id, 'related_product_id' => null, 'source' => ProductAccessory::SOURCE_PRESTA,
            'link_key' => 'p:700', 'presta_related_id' => 700,
        ]);
        $matched = $this->accessory($source, $visor, ProductAccessory::SOURCE_PRESTA, 'p:700', ['presta_related_id' => 700, 'method' => 'presta_id']);
        // ten sam numer Presty dopasowany na obu kartach — zostaje wiersz karty producenta
        $keptPresta = $this->accessory($target, $strap, ProductAccessory::SOURCE_PRESTA, 'p:701', ['presta_related_id' => 701]);
        $dupPresta = $this->accessory($source, $strap, ProductAccessory::SOURCE_PRESTA, 'p:701', ['presta_related_id' => 701]);

        app(CardMatchMerger::class)->merge($candidate, $user);

        $this->assertNotNull($keptFilter->fresh());
        $this->assertNull($dupFilter->fresh());
        $this->assertNull($weakBag->fresh());
        $this->assertSame((int) $target->id, $manualBag->fresh()->product_id);
        $this->assertNull($unmatched->fresh());
        $this->assertSame((int) $target->id, $matched->fresh()->product_id);
        $this->assertSame((int) $visor->id, $matched->fresh()->related_product_id);
        $this->assertNotNull($keptPresta->fresh());
        $this->assertNull($dupPresta->fresh());
        $pairs = ProductAccessory::query()->where('product_id', $target->id)->pluck('related_product_id')->all();
        sort($pairs);
        $this->assertSame([(int) $filter->id, (int) $bag->id, (int) $strap->id, (int) $visor->id], $pairs);
        $this->assertSame(4, ProductAccessory::query()->count());
    }

    /**
     * Cofnięcie „Połącz” z kopii zapasowej: wiersze akcesoriów obu kart w kopii to stan sprzed scalenia (przepięte
     * i skasowane), a scalenie nie zakłada nowych wierszy — przywrócenie karty i wierszy po id odtwarza stan.
     */
    public function test_card_match_backup_restores_own_accessories(): void
    {
        $user = User::factory()->create();
        [$target, $source] = $this->cards();
        $candidate = $this->candidate($source, $target);
        $filter = Product::query()->create(['sku' => 'FP3', 'name' => 'Filtr ANRO P3', 'manufacturer' => 'ANRO']);
        $mask = Product::query()->create(['sku' => 'IF/017', 'name' => 'Półmaska ANRO IF/017', 'manufacturer' => 'ANRO']);
        $this->accessory($source, $filter, ProductAccessory::SOURCE_ENRICHMENT, 's:fp3', ['method' => 'sku']);
        $this->accessory($target, $filter, ProductAccessory::SOURCE_ENRICHMENT, 'e:5900000000011', ['method' => 'ean']);
        $this->accessory($source, $target, ProductAccessory::SOURCE_ENRICHMENT, 's:iff016fps', ['method' => 'sku']);
        $this->manual($source, $mask);
        $this->manual($mask, $source);
        $before = $this->accessoryRows();

        app(CardMatchMerger::class)->merge($candidate, $user);
        $this->assertNotSame($before, $this->accessoryRows());

        $backup = json_decode((string) file_get_contents((string) $candidate->fresh()->backup_path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($before, $backup['product_accessories']);
        $ids = array_column($backup['product_accessories'], 'id');
        // po scaleniu nie ma wierszy spoza kopii — przywrócenie po id jest pełne
        $this->assertSame([], array_diff(ProductAccessory::query()->pluck('id')->all(), $ids));

        DB::table('products')->insert($backup['cards']['source']['product']);
        DB::table('product_accessories')->whereIn('id', $ids)->delete();
        DB::table('product_accessories')->insert($backup['product_accessories']);

        $this->assertSame($before, $this->accessoryRows());
    }

    public function test_automatic_size_merge_on_price_list_import_keeps_own_accessories(): void
    {
        // scenariusz Bollé 10254/10256: import cennika uruchamia merge() producenta, a karta rozmiaru, która znika,
        // ma własne akcesoria — dotąd kasowała je kaskada
        $t8 = Product::query()->create([
            'sku' => 'CRIOT08', 'name' => 'CRYOGENIC GLOVES T8 -196°C LEATHER  40CM', 'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99, 'purchase_price' => 37.51, 'stock' => 1,
        ]);
        $t9 = Product::query()->create([
            'sku' => 'CRIOT09', 'name' => 'CRYOGENIC GLOVES T9 -196°C LEATHER  40 CM', 'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99, 'purchase_price' => 37.51, 'stock' => 1,
        ]);
        $liner = Product::query()->create(['sku' => 'LINER-1', 'name' => 'Wkładka do rękawic', 'manufacturer' => 'Rostaing']);
        $clip = Product::query()->create(['sku' => 'CLIP-1', 'name' => 'Klips do rękawic', 'manufacturer' => 'Rostaing']);
        $rows = [
            $this->accessory($t8, $liner, ProductAccessory::SOURCE_PRESTA, 'p:31', ['presta_related_id' => 31, 'method' => 'presta_id']),
            $this->accessory($t9, $liner, ProductAccessory::SOURCE_PRESTA, 'p:31', ['presta_related_id' => 31, 'method' => 'presta_id']),
            $this->manual($t9, $clip),
            // rozmiar wskazywał drugi rozmiar — po scaleniu to ta sama karta
            $this->accessory($t9, $t8, ProductAccessory::SOURCE_ENRICHMENT, 's:criot08', ['method' => 'sku']),
        ];

        $path = tempnam(sys_get_temp_dir(), 'criotacc').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        try {
            app(PriceListImportService::class)->importFromProducts(
                new UploadedFile($path, 'rostaing.pdf', 'application/pdf', null, true),
                'Rostaing',
                '2026',
                User::factory()->create(),
                [
                    ['sku' => 'CRIOT08', 'name' => 'CRYOGENIC GLOVES T8 -196°C LEATHER  40CM', 'catalog_price_net' => 82.99, 'purchase_price' => 37.51],
                    ['sku' => 'CRIOT09', 'name' => 'CRYOGENIC GLOVES T9 -196°C LEATHER  40 CM', 'catalog_price_net' => 82.99, 'purchase_price' => 37.51],
                ],
            );
        } finally {
            @unlink($path);
        }

        $kept = Product::query()->where('manufacturer', 'Rostaing')->where('sku', 'CRIOT')->sole();
        $this->assertSame(1, Product::query()->whereIn('id', [$t8->id, $t9->id])->count());
        $this->assertSame(
            [(int) $liner->id, (int) $clip->id],
            ProductAccessory::query()->where('product_id', $kept->id)->orderBy('related_product_id')->pluck('related_product_id')->all()
        );
        $this->assertSame(['m:'.$clip->id, 'p:31'], ProductAccessory::query()->where('product_id', $kept->id)->orderBy('link_key')->pluck('link_key')->all());
        $this->assertNull($rows[3]->fresh());
        $this->assertSame(2, ProductAccessory::query()->count());
    }

    public function test_size_cards_merge_moves_own_accessories_to_model_card(): void
    {
        [$s, $m, $l] = $this->halfMasks();
        $filter = Product::query()->create(['sku' => '6035', 'name' => 'Filtr 3M 6035 P3', 'manufacturer' => '3M']);
        $cartridge = Product::query()->create(['sku' => '6055', 'name' => 'Pochłaniacz 3M 6055', 'manufacturer' => '3M']);
        $keptFilter = $this->accessory($s, $filter, ProductAccessory::SOURCE_ENRICHMENT, 's:6035', ['method' => 'sku']);
        $dupFilter = $this->accessory($m, $filter, ProductAccessory::SOURCE_ENRICHMENT, 's:6035', ['method' => 'sku']);
        $moved = $this->accessory($l, $cartridge, ProductAccessory::SOURCE_ENRICHMENT, 's:6055', ['method' => 'sku', 'presta_related_id' => 45]);
        $sibling = $this->accessory($m, $l, ProductAccessory::SOURCE_ENRICHMENT, 's:7000146849', ['method' => 'sku']);

        app(ProductSizeMergeService::class)->mergeSizeCards($s, [$m, $l], '6X00 Półmaska 3M 6000', 'Rozmiary: S; M; L');

        $this->assertNotNull($keptFilter->fresh());
        $this->assertNull($dupFilter->fresh());
        $this->assertSame((int) $s->id, $moved->fresh()->product_id);
        $this->assertSame(45, $moved->fresh()->presta_related_id);
        $this->assertNull($sibling->fresh());
        $this->assertSame(2, ProductAccessory::query()->count());
    }

    public function test_merge_duplicate_command_moves_own_accessories(): void
    {
        $keep = Product::query()->create(['sku' => '9169.5', 'name' => 'Okulary UVEX super f OTG 9169.541', 'manufacturer' => 'UVEX']);
        $drop = Product::query()->create(['sku' => '9169.541', 'name' => 'Okulary UVEX SUPER f OTG 9169', 'manufacturer' => 'UVEX']);
        $case = Product::query()->create(['sku' => '9954.500', 'name' => 'Etui na okulary UVEX', 'manufacturer' => 'UVEX']);
        $row = $this->accessory($drop, $case, ProductAccessory::SOURCE_ENRICHMENT, 's:9954500', ['related_sku' => '9954.500', 'method' => 'sku']);
        $pair = [$keep->id.':'.$drop->id];
        $backup = $this->storage.DIRECTORY_SEPARATOR.'merge-duplicate.json';

        $this->artisan('products:merge-duplicate', ['--pair' => $pair])
            ->expectsOutputToContain('akcesoria: 1')
            ->expectsOutputToContain('Podgląd: 1 par do scalenia.')
            ->assertSuccessful();
        $this->assertSame((int) $drop->id, $row->fresh()->product_id);

        $this->artisan('products:merge-duplicate', ['--pair' => $pair, '--apply' => true, '--backup' => $backup])
            ->expectsOutputToContain('Scalono 1 par.')
            ->assertSuccessful();

        $this->assertNull($drop->fresh());
        $this->assertSame((int) $keep->id, $row->fresh()->product_id);
        $saved = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([(int) $row->id], array_column($saved[0]['product_accessories'], 'id'));
        $this->assertSame((int) $drop->id, $saved[0]['product_accessories'][0]['product_id']);
    }

    /** @return list<array<string, mixed>> */
    private function accessoryRows(): array
    {
        return DB::table('product_accessories')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function accessory(Product $parent, Product $related, string $source, string $linkKey, array $attributes = []): ProductAccessory
    {
        return ProductAccessory::query()->create([
            'product_id' => $parent->id,
            'related_product_id' => $related->id,
            'source' => $source,
            'link_key' => $linkKey,
        ] + $attributes);
    }

    /** Ręczne akcesorium jak z ProductKitService::attach (klucz m:{karta}). */
    private function manual(Product $parent, Product $related): ProductAccessory
    {
        return $this->accessory($parent, $related, ProductAccessory::SOURCE_MANUAL, 'm:'.$related->id, [
            'related_sku' => (string) $related->sku, 'related_name' => (string) $related->name, 'score' => 100, 'method' => 'manual',
        ]);
    }

    private function tender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/5', 'title' => 'Półmaski', 'client_id' => Client::query()->create(['name' => 'Szpital'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
    }

    /**
     * Karta producenta ANRO (powiązanie konta Anro — właściciel) i karta dystrybutora P4S tego samego wyrobu.
     *
     * @return array{0: Product, 1: Product}
     */
    private function cards(): array
    {
        $anro = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        $p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
        $target = Product::query()->create([
            'sku' => 'IF/016/F/PS', 'name' => 'Półmaska ANRO IF/016/F/PS', 'manufacturer' => 'ANRO',
            'catalog_price_net' => 8.69, 'purchase_price' => 8.69, 'currency' => 'PLN',
        ]);
        $source = Product::query()->create([
            'sku' => 'ZPPV99C', 'name' => 'Półmaska P4S IF/016/F/PS', 'manufacturer' => 'ANRO',
            'catalog_price_net' => 7.90, 'purchase_price' => 7.90, 'currency' => 'PLN',
        ]);
        B2bProductLink::query()->create(['b2b_account_id' => $anro->id, 'remote_id' => 'IF/016/F/PS', 'product_id' => $target->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $p4s->id, 'remote_id' => '99254', 'product_id' => $source->id]);

        return [$target, $source];
    }

    private function candidate(Product $source, Product $target): CardMatchCandidate
    {
        $this->targets[$source->id] = (int) $target->id;

        return CardMatchCandidate::query()->create([
            'source_product_id' => $source->id, 'target_product_id' => $target->id,
            'status' => CardMatchCandidate::STATUS_PENDING, 'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE,
            'matched_value' => 'IF016FPS', 'brand' => 'anro', 'hits' => 1, 'positions' => 1,
        ]);
    }

    /**
     * Trzy rozmiary rękawic Ansell w jednej cenie — zostaje karta z opisem (preferredKeeper), dwie pozostałe znikają.
     *
     * @return array{0: Product, 1: Product, 2: Product} karta, która zostaje, i dwie scalane
     */
    private function ansellSizes(): array
    {
        $cards = [];
        foreach ([['37695VP090', '9.0', true], ['37695VP070', '7.0', false], ['37695VP100', '10.0', false]] as [$sku, $size, $described]) {
            $cards[] = Product::query()->create([
                'sku' => $sku,
                'name' => 'AlphaTec 37695VP Size '.$size,
                'manufacturer' => 'Ansell',
                'catalog_price_net' => 2.85,
                'purchase_price' => 2.85,
            ] + ($described ? [
                'description' => str_repeat('Rękawice chemiczne Ansell AlphaTec. ', 3),
                'enrichment_status' => Product::ENRICHMENT_DONE,
            ] : []));
        }

        return [$cards[0], $cards[1], $cards[2]];
    }

    /**
     * Karty rozmiarów 3M (6100 S, 6200 M, 6300 L) w jednej cenie.
     *
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function halfMasks(): array
    {
        $cards = [];
        foreach ([['7000146845', '6100 S'], ['7000146847', '6200 M'], ['7000146849', '6300 L']] as [$sku, $size]) {
            $cards[] = Product::query()->create([
                'sku' => $sku, 'name' => 'Półmaska 3M '.$size, 'manufacturer' => '3M',
                'catalog_price_net' => 61.38, 'purchase_price' => 61.38, 'currency' => 'PLN',
            ]);
        }

        return [$cards[0], $cards[1], $cards[2]];
    }
}
