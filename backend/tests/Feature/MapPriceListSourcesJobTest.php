<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\MapPriceListSourcesJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\PriceLists\PriceListIntakeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePriceListImporter;
use Tests\TestCase;

/**
 * Zadanie mapy kart cennika z importerem: tylko karty ze slotem pliku tego cennika, zapis tylko zmienionych pinów,
 * opisy tylko dla nowych w mapie, zmienionych i bez opisu, bez kart z opisem z B2B; wiersze z ponownie odczytanego pliku.
 */
final class MapPriceListSourcesJobTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Opis karty zapisany wcześniej, wystarczająco długi, żeby był opisem.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        FakePriceListImporter::install();
        $this->user = User::factory()->create();
    }

    public function test_maps_only_cards_with_file_slot_of_this_list_and_uses_file_rows(): void
    {
        $list = $this->list('Anro');
        $other = $this->list('Polstar', null);
        $own = $this->card('A-1', $list);
        $foreign = $this->card('P-1', $other);
        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1', 'P-1' => 'https://maker.test/p-1'];
        $this->importedFile($list, [['A-1', 'Kask z pliku', 10.0, '5900000000001']]);

        $result = (new MapPriceListSourcesJob((int) $list->id, false))->run(true);

        $this->assertSame(1, $result['cards']);
        $this->assertSame(['A-1'], FakePriceListImporter::$mapped);
        // wiersz z ponownie odczytanego pliku (nazwa i EAN z pliku, nie z karty)
        $this->assertCount(1, FakePriceListImporter::$rows[0]);
        $this->assertSame('Kask z pliku', FakePriceListImporter::$rows[0][0]->name);
        $this->assertSame('5900000000001', FakePriceListImporter::$rows[0][0]->ean);
        $this->assertSame('wiersz 2', FakePriceListImporter::$rows[0][0]->ref);
        $pin = ProductSourcePin::query()->where('product_id', $own->id)->sole();
        $this->assertSame('https://maker.test/a-1', $pin->url);
        $this->assertSame((int) $list->id, (int) $pin->price_list_id);
        $this->assertSame(FakePriceListImporter::KEY, $pin->importer_key);
        $this->assertSame(1, $pin->importer_version);
        $this->assertNotNull($pin->checked_at);
        $this->assertSame(0, ProductSourcePin::query()->where('product_id', $foreign->id)->count());
    }

    public function test_without_imported_file_rows_come_from_card_identifiers(): void
    {
        $list = $this->list('Anro');
        $this->card('A-1', $list);

        (new MapPriceListSourcesJob((int) $list->id, false))->run(true);

        $this->assertSame('A-1', FakePriceListImporter::$rows[0][0]->sku);
        $this->assertSame('Karta A-1', FakePriceListImporter::$rows[0][0]->name);
        $this->assertStringContainsString('bez wiersza w pliku', FakePriceListImporter::$rows[0][0]->ref);
        $this->assertSame('brak strony z kodem A-1', ProductSourcePin::query()->sole()->unresolved_reason);
    }

    public function test_unchanged_pin_is_not_written_again_and_version_bump_rewrites_it(): void
    {
        $list = $this->list('Anro');
        $card = $this->card('A-1', $list);
        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1'];
        (new MapPriceListSourcesJob((int) $list->id, false))->run(true);
        $pin = ProductSourcePin::query()->sole();
        $stamp = $pin->updated_at?->toDateTimeString();
        $checked = $pin->checked_at?->toDateTimeString();

        $this->travel(10)->minutes();
        $again = (new MapPriceListSourcesJob((int) $list->id, false))->run(true);

        $this->assertSame(['changed' => 0, 'unchanged' => 1, 'written' => 0], array_intersect_key($again, array_flip(['changed', 'unchanged', 'written'])));
        $pin->refresh();
        $this->assertSame($stamp, $pin->updated_at?->toDateTimeString());
        $this->assertSame($checked, $pin->checked_at?->toDateTimeString());

        FakePriceListImporter::$version = 2;
        $bumped = (new MapPriceListSourcesJob((int) $list->id, false))->run(true);
        $this->assertSame(1, $bumped['written']);
        $this->assertSame(2, $pin->fresh()?->importer_version);
        $this->assertSame((int) $card->id, (int) $pin->product_id);
    }

    public function test_preview_run_does_not_write_pins(): void
    {
        $list = $this->list('Anro');
        $this->card('A-1', $list);
        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1'];

        $result = (new MapPriceListSourcesJob((int) $list->id, false))->run(false);

        $this->assertSame(1, $result['changed']);
        $this->assertSame(0, $result['written']);
        $this->assertSame('https://maker.test/a-1', $result['changes'][0]['new_url']);
        $this->assertSame(0, ProductSourcePin::query()->count());
    }

    public function test_describe_queues_cards_without_description_from_this_pin_but_not_b2b_unresolved_or_human(): void
    {
        $list = $this->list('Anro');
        // opis z tego samego pinu (source_map: adres i wersja importera) — bez kolejki
        $this->card('U-1', $list, self::DESCRIPTION, ['url' => 'https://maker.test/u-1', 'importer_version' => 1]);
        // opis z innego adresu (stary sposób albo stary pin) — do kolejki
        $this->card('S-1', $list, self::DESCRIPTION, ['url' => 'https://maker.test/stary', 'importer_version' => 1]);
        $this->card('D-1', $list);
        $this->card('N-1', $list, self::DESCRIPTION);
        $b2b = $this->card('B-1', $list, self::DESCRIPTION);
        $this->card('X-1', $list);
        $human = $this->card('H-1', $list);
        $human->update(['shop_source_url' => 'https://sklep.test/h-1']);
        $account = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.pl'], 'connector' => 'anro']);
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => '1', 'product_id' => $b2b->id, 'description_hash' => sha1(self::DESCRIPTION)]);
        FakePriceListImporter::$pins = [
            'U-1' => 'https://maker.test/u-1', 'S-1' => 'https://maker.test/s-1', 'D-1' => 'https://maker.test/d-1',
            'N-1' => 'https://maker.test/n-1', 'B-1' => 'https://maker.test/b-1', 'H-1' => 'https://maker.test/h-1',
        ];
        // pierwszy przebieg zapisał piny i padł przed opisami — ponowienie nie może zgubić kart
        (new MapPriceListSourcesJob((int) $list->id, false))->run(true);

        $result = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame(0, $result['changed']);
        $this->assertSame(3, $result['described']);
        $batch = ProductEnrichmentBatch::query()->sole();
        $this->assertSame(ProductEnrichmentBatch::SCOPE_PRICE_LIST, $batch->scope);
        $this->assertSame((int) $list->id, (int) $batch->scope_id);
        $this->assertTrue((bool) $batch->force);
        $queued = Product::query()->where('enrichment_status', Product::ENRICHMENT_QUEUED)->orderBy('sku')->pluck('sku')->all();
        $this->assertSame(['D-1', 'N-1', 'S-1'], $queued);
        $this->assertSame(7, ProductSourcePin::query()->count());
    }

    public function test_describe_queues_all_cards_beyond_ai_batch_limit(): void
    {
        $list = $this->list('Anro');
        $count = app(AiSettingsService::class)->enrichmentBatchLimit() + 3;
        for ($i = 1; $i <= $count; $i++) {
            $this->card('L-'.$i, $list);
            FakePriceListImporter::$pins['L-'.$i] = 'https://maker.test/l-'.$i;
        }

        $result = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame($count, $result['described']);
        $this->assertSame($count, Product::query()->where('enrichment_status', Product::ENRICHMENT_QUEUED)->count());
    }

    public function test_describe_queues_card_whose_description_was_written_with_other_file_data(): void
    {
        $list = $this->list('Anro');
        // opis z tego samego adresu i wersji importera, ale z innymi danymi cennika (spec) — plik się zmienił
        $this->card('P-1', $list, self::DESCRIPTION, ['url' => 'https://maker.test/p-1', 'importer_version' => 1, 'spec' => ['Rozmiar: 8']]);
        // te same dane (w zapisie z odstępami i powtórzeniem) — bez kolejki
        $this->card('Q-1', $list, self::DESCRIPTION, ['url' => 'https://maker.test/q-1', 'importer_version' => 1, 'spec' => ['Rozmiar: 9']]);
        FakePriceListImporter::$pins = ['P-1' => 'https://maker.test/p-1', 'Q-1' => 'https://maker.test/q-1'];
        FakePriceListImporter::$specs = ['P-1' => ['Rozmiar: 9'], 'Q-1' => [' Rozmiar: 9 ', 'Rozmiar: 9']];

        $result = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame(1, $result['described']);
        $this->assertSame(['P-1'], Product::query()->where('enrichment_status', Product::ENRICHMENT_QUEUED)->pluck('sku')->all());
    }

    public function test_pin_change_of_spec_match_key_kind_or_title_is_written(): void
    {
        $list = $this->list('Anro');
        $this->card('A-1', $list);
        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1'];
        (new MapPriceListSourcesJob((int) $list->id, false))->run(true);
        $this->assertSame(0, (new MapPriceListSourcesJob((int) $list->id, false))->run(true)['written']);

        FakePriceListImporter::$specs = ['A-1' => ['Rozmiar: 9']];
        $result = (new MapPriceListSourcesJob((int) $list->id, false))->run(true);

        $this->assertSame(1, $result['written']);
        $this->assertSame(['Rozmiar: 9'], ProductSourcePin::query()->sole()->spec);
        foreach (['match_key' => 'INNY', 'source_kind' => ProductSourcePin::KIND_SHOP, 'page_title' => 'Inny tytuł'] as $field => $value) {
            ProductSourcePin::query()->update([$field => $value]);
            $this->assertSame(1, (new MapPriceListSourcesJob((int) $list->id, false))->run(true)['written'], $field);
        }
    }

    public function test_unresolved_pin_marks_review_reason_and_resolved_pin_clears_it(): void
    {
        $list = $this->list('Anro');
        $card = $this->card('A-1', $list, self::DESCRIPTION);
        $other = $this->card('O-1', $list);
        $other->update(['review_reason' => Product::REVIEW_WORSE_VERSION]);
        $human = $this->card('H-1', $list);
        $human->update(['shop_source_url' => 'https://sklep.test/h-1']);

        $first = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame(1, $first['review_marked']);
        $this->assertSame(0, $first['described']);
        $card->refresh();
        $this->assertSame(Product::REVIEW_SOURCE_UNMAPPED, $card->review_reason);
        $this->assertNotNull($card->review_since);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertSame(Product::REVIEW_WORSE_VERSION, $other->fresh()?->review_reason);
        $this->assertNull($human->fresh()?->review_reason);

        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1'];
        $second = (new MapPriceListSourcesJob((int) $list->id, false))->run(true);

        $this->assertSame(1, $second['review_cleared']);
        $card->refresh();
        $this->assertNull($card->review_reason);
        $this->assertNull($card->review_since);
        $this->assertSame(self::DESCRIPTION, $card->description);
    }

    public function test_human_chosen_description_is_not_queued_even_with_resolved_pin(): void
    {
        $list = $this->list('Anro');
        $store = app(DescriptionVersionStore::class);
        $restored = $this->card('R-1', $list);
        $approved = $this->card('A-1', $list);
        foreach ([[$restored, ProductDescriptionVersion::ORIGIN_RESTORE, true], [$approved, ProductDescriptionVersion::ORIGIN_REVIEW_APPROVE, false]] as [$card, $origin, $humanChoice]) {
            $version = $store->record($card, ProductDescriptionVersion::STATUS_PROPOSED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
                'description' => self::DESCRIPTION, 'primary_source_url' => 'https://inny.test/'.$card->sku,
            ]);
            $store->publish($version, $this->user, $origin, $humanChoice);
        }
        FakePriceListImporter::$pins = ['R-1' => 'https://maker.test/r-1', 'A-1' => 'https://maker.test/a-1'];

        $result = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame(2, $result['pinned']);
        $this->assertSame(0, $result['described']);
        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
    }

    public function test_map_page_rejected_in_review_keeps_card_unmapped_and_not_queued(): void
    {
        $list = $this->list('Anro');
        $card = $this->card('X-1', $list);
        $card->update(['review_reason' => Product::REVIEW_SOURCE_UNMAPPED, 'review_since' => now()]);
        $store = app(DescriptionVersionStore::class);
        $rejected = $store->record($card, ProductDescriptionVersion::STATUS_REJECTED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::DESCRIPTION, 'primary_source_url' => 'https://maker.test/x-1',
        ]);
        $store->blockSourceUrl($rejected);
        FakePriceListImporter::$pins = ['X-1' => 'https://maker.test/x-1'];

        $result = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame(1, $result['pinned']);
        $this->assertSame(0, $result['review_cleared']);
        $this->assertSame(0, $result['described']);
        $this->assertSame(Product::REVIEW_SOURCE_UNMAPPED, $card->fresh()?->review_reason);
    }

    public function test_second_run_does_not_queue_cards_already_in_queue(): void
    {
        $list = $this->list('Anro');
        $card = $this->card('A-1', $list);
        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1'];

        $first = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);
        $second = (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->run(true);

        $this->assertSame(1, $first['described']);
        $this->assertSame(0, $second['described']);
        $this->assertSame(1, ProductEnrichmentBatch::query()->count());
        $this->assertSame(1, DB::table('product_enrichment_batch_items')->where('product_id', $card->id)->count());
    }

    public function test_handle_continues_with_remaining_cards_after_budget(): void
    {
        $list = $this->list('Anro');
        $this->card('A-1', $list);

        $result = (new MapPriceListSourcesJob((int) $list->id, false))->run(true, -1.0);

        $this->assertSame(0, $result['cards']);
        $this->assertCount(1, $result['remaining']);
    }

    public function test_handle_maps_all_cards_within_budget_without_continuation(): void
    {
        $list = $this->list('Anro');
        $this->card('A-1', $list);

        (new MapPriceListSourcesJob((int) $list->id, true, null, (int) $this->user->id))->handle();

        $this->assertSame(1, ProductSourcePin::query()->count());
        Queue::assertNotPushed(MapPriceListSourcesJob::class);
    }

    private function list(string $manufacturer, ?string $importer = FakePriceListImporter::KEY): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer, 'manufacturer_key' => PriceList::manufacturerKey($manufacturer), 'version' => '2026',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
            'source_policy' => PriceList::POLICY_MAP_ONLY, 'importer_key' => $importer,
        ]);
    }

    /** @param  array<string, mixed>|null  $sourceMap  enrichment_payload.source_map zapisany razem z opisem */
    private function card(string $sku, PriceList $list, ?string $description = null, ?array $sourceMap = null): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => (string) $list->manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 10, 'currency' => 'PLN', 'description' => $description,
            'enrichment_status' => $description !== null ? Product::ENRICHMENT_DONE : Product::ENRICHMENT_NONE,
            'enrichment_payload' => $sourceMap !== null ? ['source_map' => $sourceMap] : null,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10, 'purchase_price' => 10, 'currency' => 'PLN',
        ]);

        return $card;
    }

    /** Plik zaimportowany wcześniej: identyfikatory wierszy przy kartach jak po importCollected. */
    private function importedFile(PriceList $list, array $rows): PriceListFile
    {
        $content = FakePriceListImporter::csv($rows);
        $sha = hash('sha256', $content);
        Storage::disk('local')->put('price-list-files/'.$sha.'.csv', $content);
        $read = app(PriceListIntakeRunner::class);
        $file = PriceListFile::query()->create([
            'price_list_id' => $list->id, 'sha256' => $sha, 'disk' => 'local', 'path' => 'price-list-files/'.$sha.'.csv',
            'original_name' => 'cennik.csv', 'size' => strlen($content), 'status' => PriceListFile::STATUS_IMPORTED, 'imported_at' => now(),
        ]);
        $byProduct = [];
        foreach ($read->read($list, $file, new FakePriceListImporter)->rows as $row) {
            $byProduct[(int) Product::query()->where('sku', $row->sku)->value('id')] = $row->identifiers();
        }
        app(ProductIdentifierStore::class)->recordFile($list, null, $byProduct);

        return $file;
    }
}
