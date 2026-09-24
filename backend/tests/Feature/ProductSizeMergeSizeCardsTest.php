<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ProductSizeMergeService::mergeSizeCards — łączenie rozmiarów z decyzji człowieka (plan łączenia kart, krok 6).
 * Przypadek z produkcji: 3M 6100 S #40819 (7000146845), 6200 M #40815 (7000146847), 6300 L #40814 (7000146849),
 * po 61,38 zł; zostaje karta S jako karta modelu „6X00 Półmaska 3M 6000”.
 */
final class ProductSizeMergeSizeCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $mmm;

    private B2bAccount $p4s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->mmm = $this->account('mmm', '3m');
        $this->p4s = $this->account('p4s', 'p4s');
    }

    public function test_merge_keeps_model_card_identity_and_moves_everything_with_keeper_first(): void
    {
        [$s, $m, $l] = $this->halfMasks();
        $old = now()->subDays(10);
        $new = now()->subDay();

        // sloty: 3M na każdej karcie (łączone nowsze — i tak wygrywa $keep); plik tylko na M (starszy) i L (nowszy)
        $this->slot($s, ProductSourcePrice::b2bKey($this->mmm->id), 61.38, $old, $this->mmm->id);
        $this->slot($m, ProductSourcePrice::b2bKey($this->mmm->id), 64.00, $new, $this->mmm->id);
        $this->slot($l, ProductSourcePrice::b2bKey($this->mmm->id), 65.00, $new, $this->mmm->id);
        $list = PriceList::query()->create(['name' => 'Cennik 3M', 'version' => 'v1', 'manufacturer' => 'Inna marka', 'uploaded_by' => $this->user->id]);
        $this->slot($m, ProductSourcePrice::SOURCE_FILE, 50.00, $old, null, $list->id);
        $this->slot($l, ProductSourcePrice::SOURCE_FILE, 51.00, $new, null, $list->id);

        // historia cen każdej karty
        foreach ([$s, $m, $l] as $card) {
            ProductPriceHistory::query()->create([
                'product_id' => $card->id, 'purchase_price' => 61.38, 'catalog_price_net' => 61.38, 'currency' => 'PLN', 'source' => 'b2b:3m',
            ]);
        }

        // tabelki: konto 3M (właściciel) na S i M; P4S (dystrybutor) tylko na M (starsza) i L (nowsza)
        $this->shopCard($s, $this->mmm, 'S', $old);
        $this->shopCard($m, $this->mmm, 'M', $new);
        $this->shopCard($m, $this->p4s, 'P4S M', $old);
        $this->shopCard($l, $this->p4s, 'P4S L', $new);

        // zdjęcia: S ma główne i drugie; M ma główne i duplikat zdjęcia S (ta sama suma); L — jedno
        $s1 = $this->image($s, 'fa-s1', 0, true);
        $s2 = $this->image($s, 'fa-s2', 1, false);
        $m1 = $this->image($m, 'fa-m1', 0, true);
        $this->image($m, 'fa-s1', 1, false);
        $l1 = $this->image($l, 'fa-l1', 0, true);

        // przetargi (pozycja główna i produkt dodatkowy), mapa połączeń, identyfikatory
        $tender = Tender::query()->create([
            'number' => 'PRZ/6', 'title' => 'Półmaski', 'client_id' => Client::query()->create(['name' => 'Szpital'])->id,
            'owner_id' => $this->user->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        $main = TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Półmaska M', 'main_product_id' => $m->id]);
        $companion = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 2, 'requirement' => 'Filtr z półmaską', 'main_product_id' => null, 'companion_product_id' => $l->id,
        ]);
        // półmaska M jako produkt główny, a L jako „drugi” — po scaleniu to ta sama karta, produkt dodatkowy znika
        $same = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 3, 'requirement' => 'Półmaska z zapasową', 'main_product_id' => $m->id, 'companion_product_id' => $l->id,
        ]);
        CardRedirect::query()->create([
            'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'position_key' => '1002', 'b2b_account_id' => $this->p4s->id,
            'product_id' => $m->id, 'reason' => CardRedirect::REASON_MERGE, 'target_snapshot' => CardRedirectStore::snapshot($m),
        ]);
        ProductIdentifier::query()->create([
            'product_id' => $l->id, 'type' => ProductIdentifier::TYPE_MANUFACTURER_CODE, 'value' => 'KOD-L', 'normalized_value' => 'KODL',
            'source_key' => ProductSourcePrice::b2bKey($this->mmm->id), 'position_key' => '7000146849',
        ]);

        $images = app(ProductSizeMergeService::class)->mergeSizeCards(
            $s,
            [$m, $l],
            '  6X00 Półmaska 3M 6000  ',
            'Rozmiary: S (mały) (7000146845); M (średni) (7000146847); L (duży) (7000146849)',
        );

        // karty łączone usunięte, karta modelu z tożsamością S
        $this->assertNull(Product::query()->find($m->id));
        $this->assertNull(Product::query()->find($l->id));
        $card = $s->fresh();
        $this->assertSame('7000146845', $card->sku);
        $this->assertSame('1 szt.', $card->packaging);
        $this->assertSame('Opis półmaski 6100 S z witryny 3M, pełny tekst.', $card->description);
        $this->assertSame('3M', $card->manufacturer);
        $this->assertSame('6X00 Półmaska 3M 6000', $card->name);
        $this->assertSame('Rozmiary: S (mały) (7000146845); M (średni) (7000146847); L (duży) (7000146849)', $card->variant_summary);
        $this->assertSame(6, (int) $card->stock);
        $this->assertSame('Półmaski', $card->category);
        $this->assertSame(['7000146847', '7000146849'], $card->enrichment_payload['merged_size_skus']);
        $this->assertSame(
            [['product_id' => $s->id, 'sku' => '7000146845'], ['product_id' => $m->id, 'sku' => '7000146847'], ['product_id' => $l->id, 'sku' => '7000146849']],
            $card->enrichment_payload['size_merge']['sizes'],
        );
        $this->assertNotEmpty($card->enrichment_payload['size_merge']['at']);

        // historia: tylko wiersz karty modelu
        $this->assertSame(1, ProductPriceHistory::query()->count());
        $this->assertSame($s->id, (int) ProductPriceHistory::query()->value('product_id'));

        // sloty: 3M z karty modelu mimo starszego checked_at; plik — nowszy z łączonych
        $slots = ProductSourcePrice::query()->where('product_id', $s->id)->get()->keyBy('source_key');
        $this->assertSame('61.38', $slots[ProductSourcePrice::b2bKey($this->mmm->id)]->purchase_price);
        $this->assertSame('51.00', $slots[ProductSourcePrice::SOURCE_FILE]->purchase_price);
        $this->assertSame(2, ProductSourcePrice::query()->count());

        // tabelki: właściciel — tylko S; dystrybutor — najnowsza z łączonych
        $cards = ProductShopCard::query()->where('product_id', $s->id)->get()->keyBy('b2b_account_id');
        $this->assertCount(2, $cards);
        $this->assertSame('S', $cards[$this->mmm->id]->fields[0]['rows'][0]['value']);
        $this->assertSame('P4S L', $cards[$this->p4s->id]->fields[0]['rows'][0]['value']);
        $this->assertSame(2, ProductShopCard::query()->count());

        // zdjęcia: kolejność S bez zmian, przeniesione od 1000 bez głównego, duplikat usunięty
        $this->assertSame(['image_ids_keep' => [$s1->id, $s2->id], 'image_ids_drops' => [$m1->id, $l1->id]], $images);
        $rows = ProductImage::query()->where('product_id', $s->id)->orderBy('sort_order')->get();
        $this->assertSame([[$s1->id, 0, true], [$s2->id, 1, false], [$m1->id, 1000, false], [$l1->id, 1001, false]], $this->imageRows($rows));
        $this->assertSame(4, ProductImage::query()->count());
        // resequence z kontem producenta (przebieg 3M) — główne dalej z karty modelu
        ProductImage::resequence($s->id, $this->mmm->id);
        $this->assertSame($s1->id, (int) ProductImage::query()->where('product_id', $s->id)->where('is_primary', true)->sole()->id);

        // powiązania, przetargi, mapa, identyfikatory
        $this->assertSame(
            ['7000146845' => $s->id, '7000146847' => $s->id, '7000146849' => $s->id],
            B2bProductLink::query()->where('b2b_account_id', $this->mmm->id)->orderBy('remote_id')->pluck('product_id', 'remote_id')
                ->map(static fn ($id): int => (int) $id)->all(),
        );
        $this->assertNotNull(B2bProductLink::query()->where('remote_id', '7000146847')->value('merged_at'));
        $this->assertSame($s->id, (int) $main->fresh()->main_product_id);
        $this->assertSame($s->id, (int) $companion->fresh()->companion_product_id);
        $this->assertSame($s->id, (int) $same->fresh()->main_product_id);
        $this->assertNull($same->fresh()->companion_product_id);
        $this->assertSame($s->id, (int) CardRedirect::query()->where('position_key', '1002')->value('product_id'));
        $this->assertSame($s->id, (int) ProductIdentifier::query()->where('value', 'KOD-L')->value('product_id'));
        // cena karty ze slotu konta producenta
        $this->assertEquals(61.38, (float) $card->purchase_price);
    }

    public function test_reindex_with_force_is_dispatched_after_commit(): void
    {
        // reindeks wektorów kolejkuje się tylko przy włączonym wyszukiwaniu wektorowym (ReindexProductEmbeddingJob::dispatch)
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        [$s, $m] = $this->halfMasks();

        DB::transaction(function () use ($s, $m): void {
            app(ProductSizeMergeService::class)->mergeSizeCards($s, [$m], '6X00 Półmaska 3M 6000', 'Rozmiary: S; M');
            // zlecenie z haka modelu (zapis nazwy) trzyma blokadę ShouldBeUnique — jak po jego przetworzeniu
            app(UniqueLock::class)->release(new ReindexProductEmbeddingJob($s->id));
            Queue::assertNotPushed(ReindexProductEmbeddingJob::class, static fn (ReindexProductEmbeddingJob $job): bool => $job->force);
        });

        Queue::assertPushed(
            ReindexProductEmbeddingJob::class,
            static fn (ReindexProductEmbeddingJob $job): bool => $job->force && $job->productId === $s->id,
        );
    }

    public function test_order_images_after_attaching_distributor_card(): void
    {
        [$s, $m] = $this->halfMasks();
        $s1 = $this->image($s, 'fb-s1', 0, true);
        $m1 = $this->image($m, 'fb-m1', 0, true);
        $service = app(ProductSizeMergeService::class);
        $images = $service->mergeSizeCards($s, [$m], '6X00 Półmaska 3M 6000', 'Rozmiary: S; M');
        // zdjęcie karty dystrybutora przeniesione przez dołączenie (mergeDuplicate) z własnym is_primary
        $p4s = $this->image($s, 'fb-p4s', 0, true, $this->p4s->id);

        $service->orderSizeMergeImages($s->fresh(), $images['image_ids_keep'], $images['image_ids_drops']);

        $rows = ProductImage::query()->where('product_id', $s->id)->orderBy('sort_order')->get();
        $this->assertSame([[$s1->id, 0, true], [$p4s->id, 1, false], [$m1->id, 1000, false]], $this->imageRows($rows));
    }

    public function test_keep_without_images_gets_primary_from_first_other_image(): void
    {
        [$s, $m] = $this->halfMasks();
        $m1 = $this->image($m, 'fc-m1', 0, true);
        $service = app(ProductSizeMergeService::class);
        $images = $service->mergeSizeCards($s, [$m], '6X00 Półmaska 3M 6000', 'Rozmiary: S; M');
        $this->assertSame([], $images['image_ids_keep']);

        $service->orderSizeMergeImages($s->fresh(), $images['image_ids_keep'], $images['image_ids_drops']);

        $this->assertSame([[$m1->id, 1000, true]], $this->imageRows(ProductImage::query()->where('product_id', $s->id)->get()));
    }

    /**
     * @return list<Product>
     */
    private function halfMasks(): array
    {
        $cards = [];
        foreach ([['7000146845', '6100 S', 'Półmaski'], ['7000146847', '6200 M', null], ['7000146849', '6300 L', null]] as $i => [$sku, $size, $category]) {
            $card = Product::query()->create([
                'sku' => $sku,
                'name' => 'Półmaska 3M '.$size,
                'manufacturer' => '3M',
                'packaging' => '1 szt.',
                'category' => $category,
                'description' => 'Opis półmaski '.$size.' z witryny 3M, pełny tekst.',
                'stock' => $i + 1,
                'catalog_price_net' => 61.38,
                'purchase_price' => 61.38,
                'currency' => 'PLN',
            ]);
            B2bProductLink::query()->create(['b2b_account_id' => $this->mmm->id, 'remote_id' => $sku, 'product_id' => $card->id]);
            $cards[] = $card;
        }

        return $cards;
    }

    private function slot(Product $card, string $key, float $price, mixed $checkedAt, ?int $accountId = null, ?int $listId = null): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => $key,
            'b2b_account_id' => $accountId,
            'price_list_id' => $listId,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'checked_at' => $checkedAt,
        ]);
    }

    private function shopCard(Product $card, B2bAccount $account, string $value, mixed $syncedAt): void
    {
        ProductShopCard::query()->create([
            'product_id' => $card->id,
            'b2b_account_id' => $account->id,
            'fields' => [['section' => '', 'rows' => [['name' => 'Pozycja', 'value' => $value]]]],
            'synced_at' => $syncedAt,
        ]);
    }

    private function image(Product $card, string $checksum, int $sortOrder, bool $primary, ?int $accountId = null): ProductImage
    {
        return ProductImage::query()->create([
            'product_id' => $card->id,
            'b2b_account_id' => $accountId ?? $this->mmm->id,
            'path' => 'remote',
            'source_url' => 'https://img.example.test/'.$checksum.'.png',
            'checksum' => $checksum,
            'sort_order' => $sortOrder,
            'is_primary' => $primary,
        ]);
    }

    /**
     * @param  iterable<ProductImage>  $rows
     * @return list<array{0: int, 1: int, 2: bool}>
     */
    private function imageRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [(int) $row->id, (int) $row->sort_order, (bool) $row->is_primary];
        }

        return $out;
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }
}
