<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\ProductSpecialPrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Ręczne łączenie kart z listy produktów (28.09.2026): podgląd z podpowiedzią karty, która zostaje, blokadami
 * i ostrzeżeniami, połączenie kilku kart w jedną (mapa połączeń, propozycje „połączone ręcznie”, kopia zapasowa)
 * i odmowy (inny skrót podglądu 409, blokada i marka bez potwierdzenia 422, uprawnienie 403).
 */
final class ManualCardMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private B2bAccount $anro;

    private B2bAccount $p4s;

    private B2bAccount $rawpol;

    private B2bAccount $mascot;

    /** @var list<string> kopie zapasowe sprzed testu */
    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->anro = $this->account('anro');
        $this->p4s = $this->account('p4s');
        $this->rawpol = $this->account('rawpol');
        $this->mascot = $this->account('mascot');
        $this->backupsBefore = self::backups();
    }

    protected function tearDown(): void
    {
        // kopie zapasowe powstają w prawdziwym storage/app/repair-backups — sprzątamy też po teście, który padł
        foreach (array_diff(self::backups(), $this->backupsBefore) as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_preview_suggests_manufacturer_card_and_locks_it(): void
    {
        $this->actingAsRole('admin');
        [$owner, $p4s, $rawpol] = $this->trio();

        $preview = $this->preview([$rawpol->id, $owner->id, $p4s->id]);

        $this->assertSame([$owner->id, $p4s->id, $rawpol->id], array_column($preview['cards'], 'id'));
        $this->assertSame($owner->id, $preview['suggested_keep_id']);
        $this->assertSame($owner->id, $preview['keep_product_id']);
        $this->assertTrue($preview['keep_locked']);
        $this->assertStringContainsString('#'.$owner->id, $preview['suggestion_reason']);
        $this->assertSame([], $preview['blockers']);
        // pozycja przetargu karty Raw-Pol — tylko informacja
        $this->assertSame(['tender_items'], array_column($preview['warnings'], 'code'));
        $this->assertTrue($preview['can_merge']);
        $this->assertSame(40, strlen($preview['plan_hash']));

        $card = $preview['cards'][0];
        $this->assertTrue($card['is_owner']);
        $this->assertSame('konto B2B Anro', $card['owner_label']);
        $this->assertTrue($card['has_description']);
        $this->assertSame(1, $card['images']);
        $this->assertSame('10.00', $card['purchase_price']);
        $this->assertSame('PLN', $card['currency']);
        $this->assertSame([['source_key' => 'b2b:'.$this->anro->id, 'label' => 'B2B Anro', 'purchase_price' => '10.00', 'currency' => 'PLN']], $card['sources']);
        $this->assertFalse($preview['cards'][1]['is_owner']);
        $this->assertNull($preview['cards'][1]['owner_label']);
        $this->assertSame(1, $preview['cards'][2]['size_rows']);
        $this->assertSame(1, $preview['cards'][2]['tender_items']);

        $this->assertSame([
            'source_prices' => 2, 'b2b_links' => 2, 'images' => 2, 'identifiers' => 1, 'tender_items' => 1,
            'size_rows' => 1, 'accessories' => 0,
        ], $preview['moves']);

        // ten sam podgląd z jawnie wybraną kartą producenta — ten sam skrót
        $this->assertSame($preview['plan_hash'], $this->preview([$owner->id, $p4s->id, $rawpol->id], $owner->id)['plan_hash']);

        // inna karta niż karta producenta — blokada, a połączenie odmawia (422) i nic nie zmienia
        $other = $this->preview([$owner->id, $p4s->id, $rawpol->id], $p4s->id);
        $this->assertFalse($other['can_merge']);
        $this->assertSame($p4s->id, $other['keep_product_id']);
        $this->assertTrue($this->hasBlocker($other, 'Zostaje karta producenta #'.$owner->id.' — ma cennik producenta'));
        $this->mergeRequest([$owner->id, $p4s->id, $rawpol->id], $p4s->id, $other['plan_hash'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Zostaje karta producenta'));
        $this->assertSame(3, Product::query()->whereIn('id', [$owner->id, $p4s->id, $rawpol->id])->count());
        $this->assertSame(0, CardMatchCandidate::query()->count());
    }

    public function test_preview_without_owner_suggests_card_with_description_then_more_sources(): void
    {
        $this->actingAsRole('admin');
        $bare = $this->card('P4S-1', 'Rękawice nitrylowe Grip', 'ANRO', 5.00);
        $this->slot($bare, $this->p4s, 5.00);
        $described = $this->card('RP-1', 'Rękawice nitrylowe Grip', 'ANRO', 5.20, description: 'Rękawice nitrylowe z chwytem, EN 388 4121X.');
        $this->slot($described, $this->rawpol, 5.20);

        $preview = $this->preview([$bare->id, $described->id]);
        $this->assertSame($described->id, $preview['suggested_keep_id']);
        $this->assertFalse($preview['keep_locked']);
        $this->assertStringContainsString('Żadna karta nie ma cennika producenta', $preview['suggestion_reason']);
        $this->assertTrue($preview['can_merge']);

        // wybór drugiej karty dozwolony
        $chosen = $this->preview([$bare->id, $described->id], $bare->id);
        $this->assertSame($bare->id, $chosen['keep_product_id']);
        $this->assertSame($described->id, $chosen['suggested_keep_id']);
        $this->assertTrue($chosen['can_merge']);

        // obie z opisem — więcej źródeł cen wygrywa
        $bare->forceFill(['description' => 'Rękawice nitrylowe z chwytem, EN 388 4121X, rozmiary 7–11.'])->save();
        ProductSourcePrice::query()->create([
            'product_id' => $bare->id, 'source_key' => ProductSourcePrice::b2bKey($this->mascot->id), 'b2b_account_id' => $this->mascot->id,
            'catalog_price_net' => 5.10, 'purchase_price' => 5.10, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
        $this->assertSame($bare->id, $this->preview([$bare->id, $described->id])['suggested_keep_id']);
    }

    public function test_preview_blocks_two_manufacturer_cards_and_warns_about_brand(): void
    {
        $this->actingAsRole('admin');
        $anroCard = $this->card('IF/016', 'Półmaska ANRO', 'ANRO', 10.00);
        $this->link($anroCard, $this->anro, 'IF/016');
        $mascotCard = $this->card('18001', 'Kurtka Mascot', 'Mascot', 10.50);
        $this->link($mascotCard, $this->mascot, '18001');

        $preview = $this->preview([$anroCard->id, $mascotCard->id]);

        $this->assertNull($preview['suggested_keep_id']);
        $this->assertNull($preview['keep_product_id']);
        $this->assertFalse($preview['keep_locked']);
        $this->assertFalse($preview['can_merge']);
        $this->assertTrue($this->hasBlocker($preview, 'Kilka kart producenta (#'.$anroCard->id.', #'.$mascotCard->id.')'));
        $brand = collect($preview['warnings'])->firstWhere('code', 'brand');
        $this->assertNotNull($brand);
        $this->assertTrue($brand['requires_confirm']);
    }

    public function test_preview_lists_blockers_of_cards(): void
    {
        $this->actingAsRole('admin');
        $keep = $this->card('IF/016', 'Półmaska ANRO', 'ANRO', 10.00);
        $this->link($keep, $this->anro, 'IF/016');
        DB::table('presta_product_matches')->insert(['product_id' => $keep->id, 'presta_id' => 100, 'method' => 'sku', 'score' => 100, 'status' => 'matched', 'created_at' => now(), 'updated_at' => now()]);
        $this->variant($keep, ProductVariant::KIND_VERSION, 'b2b:signproject', 'V-KEEP');

        // wersje (także wycofane) i ceny specjalne
        $versions = $this->card('D-1', 'Półmaska 1', 'ANRO', 10.00);
        $this->link($versions, $this->p4s, 'p1');
        $this->variant($versions, ProductVariant::KIND_VERSION, 'b2b:signproject', 'V1', removed: true);
        ProductSpecialPrice::query()->create(['product_id' => $versions->id, 'client_name' => 'Klient', 'price' => 1]);
        // to samo konto na drugiej karcie
        $sameAccount = $this->card('D-2', 'Półmaska 2', 'ANRO', 10.00);
        $this->link($sameAccount, $this->p4s, 'p2');
        // cena z pliku dystrybutora (nie właściciel karty ANRO) bez kodów pozycji i druga cena z pliku (z kodami)
        $list = $this->priceList('P4S');
        $fileNoCodes = $this->card('D-3', 'Półmaska 3', 'ANRO', 10.00);
        $this->fileSlot($fileNoCodes, $list);
        $fileWithCodes = $this->card('D-4', 'Półmaska 4', 'ANRO', 10.00);
        $this->fileSlot($fileWithCodes, $list);
        ProductIdentifier::query()->create([
            'product_id' => $fileWithCodes->id, 'type' => 'manufacturer_code', 'value' => 'D-4', 'normalized' => 'D4', 'brand_key' => 'anro',
            'source_key' => 'file:'.$list->id, 'position_key' => 'D-4', 'price_list_id' => $list->id,
        ]);
        // karta modelu po łączeniu rozmiarów, partia opisów i inny produkt Presty
        $model = $this->card('D-5', 'Półmaska 5', 'ANRO', 10.00);
        CardRedirect::query()->create([
            'source_key' => 'b2b:'.$this->rawpol->id, 'position_key' => 'r5', 'b2b_account_id' => $this->rawpol->id, 'product_id' => $model->id,
            'reason' => CardRedirect::REASON_SIZE_MERGE, 'is_anchor' => false, 'created_at' => now(),
        ]);
        $enriching = $this->card('D-6', 'Półmaska 6', 'ANRO', 10.00);
        $batch = DB::table('product_enrichment_batches')->insertGetId(['scope' => 'manual', 'scope_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_enrichment_batch_items')->insert(['batch_id' => $batch, 'product_id' => $enriching->id, 'sku' => 'D-6', 'name' => 'x', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('presta_product_matches')->insert(['product_id' => $enriching->id, 'presta_id' => 200, 'method' => 'sku', 'score' => 100, 'status' => 'matched', 'created_at' => now(), 'updated_at' => now()]);

        $ids = [$keep->id, $versions->id, $sameAccount->id, $fileNoCodes->id, $fileWithCodes->id, $model->id, $enriching->id];
        $preview = $this->preview($ids);

        $this->assertSame($keep->id, $preview['keep_product_id']);
        $this->assertFalse($preview['can_merge']);
        foreach ([
            'Karta #'.$keep->id.', która zostaje, ma wersje Sign Project (1)',
            'Karta #'.$versions->id.' ma wersje Sign Project (1)',
            'Karta #'.$versions->id.' ma ceny specjalne (1)',
            'Karty #'.$versions->id.', #'.$sameAccount->id.' mają pozycje tego samego konta B2B P4S',
            'Kilka kart ma cenę z cennika z pliku (#'.$fileNoCodes->id.', #'.$fileWithCodes->id.')',
            'Karta #'.$fileNoCodes->id.' ma cenę z cennika z pliku bez kodów pozycji',
            'Karta #'.$model->id.' to karta modelu',
            'Karta #'.$enriching->id.' czeka na opis w partii',
            'są powiązane z różnymi produktami PrestaShop',
        ] as $expected) {
            $this->assertTrue($this->hasBlocker($preview, $expected), 'Brak blokady: '.$expected."\n".implode("\n", $preview['blockers']));
        }
        $this->assertFalse($this->hasBlocker($preview, 'Karta #'.$fileWithCodes->id.' ma cenę z cennika z pliku bez kodów pozycji'));
        // karta modelu z enrichment_payload.size_merge — też blokada
        $model2 = $this->card('D-7', 'Półmaska 7', 'ANRO', 10.00);
        $model2->forceFill(['enrichment_payload' => ['size_merge' => ['at' => '2026-09-27T10:00:00+00:00', 'sizes' => []]]])->save();
        $this->assertTrue($this->hasBlocker($this->preview([$keep->id, $model2->id]), 'Karta #'.$model2->id.' to karta modelu'));

        // połączenie z blokadą: 422, nic nie zmienione
        $this->mergeRequest($ids, $keep->id, $preview['plan_hash'])->assertStatus(422);
        $this->assertSame(count($ids), Product::query()->whereIn('id', $ids)->count());
    }

    public function test_preview_blocks_card_of_product_split_by_size_price_until_size_merge(): void
    {
        $this->actingAsRole('admin');
        $keep = $this->card('IF/016', 'Półmaska ANRO', 'ANRO', 10.00);
        $this->link($keep, $this->anro, 'IF/016');
        $small = $this->card('RP-S', 'Kurtka Raw-Pol XS–XL', 'ANRO', 50.00);
        $this->link($small, $this->rawpol, 'r-s');
        $large = $this->card('RP-L', 'Kurtka Raw-Pol 3XL', 'ANRO', 60.00);
        $this->link($large, $this->rawpol, 'r-l');
        B2bSyncRun::query()->create([
            'b2b_account_id' => $this->rawpol->id, 'status' => B2bSyncRun::STATUS_OK, 'trigger' => 'manual',
            'started_at' => now()->subHour(), 'finished_at' => now(),
            'size_spread' => ['total' => 1, 'truncated' => false, 'groups' => [[
                'sku' => 'RP', 'name' => 'Kurtka Raw-Pol', 'remote_id' => 'r-s', 'cards' => [$small->id, $large->id],
                'members' => [
                    ['remote_id' => 'r-s', 'sku' => 'RP-S', 'size' => 'XS', 'card_id' => $small->id, 'net' => 50.0, 'currency' => 'PLN'],
                    ['remote_id' => 'r-l', 'sku' => 'RP-L', 'size' => '3XL', 'card_id' => $large->id, 'net' => 60.0, 'currency' => 'PLN'],
                ],
            ]]],
        ]);

        $preview = $this->preview([$keep->id, $small->id]);
        $this->assertTrue($this->hasBlocker($preview, 'najpierw „Scal rozmiary” konta B2B Raw-Pol'), implode("\n", $preview['blockers']));

        // po „Scal rozmiary” pozycje wyrobu są na jednej karcie — lista przebiegu jest starsza, ale blokady już nie ma
        B2bProductLink::query()->where('remote_id', 'r-l')->update(['product_id' => $small->id]);
        $this->assertSame([], $this->preview([$keep->id, $small->id])['blockers']);
    }

    public function test_preview_warns_about_price_names_and_tenders(): void
    {
        $this->actingAsRole('admin');
        $keep = $this->card('IF/016', 'Rękawice Grip czerwone', 'ANRO', 10.00);
        $this->link($keep, $this->anro, 'IF/016');
        $drop = $this->card('P4S-1', 'Rękawice Grip niebieskie', 'ANRO', 14.00);
        $this->link($drop, $this->p4s, 'p1');
        $item = $this->tenderItem($drop);

        $preview = $this->preview([$keep->id, $drop->id]);

        $this->assertTrue($preview['can_merge']);
        $warnings = collect($preview['warnings'])->keyBy('code');
        $this->assertSame(['price_diff', 'size_color', 'tender_items'], $warnings->keys()->all());
        $this->assertStringContainsString('40 %', $warnings['price_diff']['text']);
        $this->assertStringContainsString('kolor', $warnings['size_color']['text']);
        $this->assertStringContainsString('10,00 PLN zamiast 14,00 PLN', $warnings['tender_items']['text']);
        $this->assertFalse($warnings['tender_items']['requires_confirm']);
        $this->assertSame(1, $preview['moves']['tender_items']);

        $this->mergeRequest([$keep->id, $drop->id], $keep->id, $preview['plan_hash'])->assertOk();
        $this->assertSame($keep->id, (int) $item->fresh()->main_product_id);
    }

    public function test_merge_joins_three_cards_records_map_candidates_and_backup(): void
    {
        $admin = $this->actingAsRole('admin');
        [$owner, $p4s, $rawpol] = $this->trio();
        $rawpol->forceFill(['category' => 'Ochrona dróg oddechowych'])->save();
        // propozycja tej pary do decyzji — staje się połączoną
        $pending = CardMatchCandidate::query()->create([
            'source_product_id' => $p4s->id, 'target_product_id' => $owner->id, 'status' => CardMatchCandidate::STATUS_PENDING,
            'matched_by' => CardMatchCandidate::BY_EAN, 'matched_value' => '5901234567890', 'brand' => 'anro',
        ]);
        $sizeRow = ProductVariant::query()->where('product_id', $rawpol->id)->firstOrFail();

        $preview = $this->preview([$owner->id, $p4s->id, $rawpol->id]);
        $response = $this->mergeRequest([$owner->id, $p4s->id, $rawpol->id], $owner->id, $preview['plan_hash'], ['note' => 'ten sam wyrób wg karty katalogowej'])
            ->assertOk()
            ->assertJsonPath('keep_product_id', $owner->id)
            ->assertJsonPath('merged_product_ids', [$p4s->id, $rawpol->id]);

        $this->assertNull(Product::query()->find($p4s->id));
        $this->assertNull(Product::query()->find($rawpol->id));
        $kept = $owner->fresh();
        $this->assertSame('IF/016/F/PS', $kept->sku);
        $this->assertSame('Półmaska ANRO IF/016/F/PS z filtrem', $kept->name);
        $this->assertSame('ANRO', $kept->manufacturer);
        $this->assertSame('Ochrona dróg oddechowych', $kept->category);
        $this->assertSame(['P4S-IF016', 'RP-IF016'], $kept->enrichment_payload['merged_duplicate_skus']);
        $this->assertSame(3, B2bProductLink::query()->where('product_id', $owner->id)->count());
        $this->assertSame(3, ProductSourcePrice::query()->where('product_id', $owner->id)->count());
        $images = ProductImage::query()->where('product_id', $owner->id)->orderBy('sort_order')->get();
        $this->assertSame(['anro.jpg', 'p4s.jpg', 'rawpol.jpg'], $images->pluck('path')->all());
        $this->assertSame([true, false, false], $images->pluck('is_primary')->all());
        $this->assertSame($owner->id, (int) TenderItem::query()->value('main_product_id'));

        // mapa połączeń: pozycje obu dystrybutorów wskazują kartę, która zostaje
        $candidates = CardMatchCandidate::query()->orderBy('source_product_id')->get();
        $this->assertCount(2, $candidates);
        $this->assertSame($pending->id, $candidates[0]->id);
        $this->assertSame($response->json('candidate_ids'), $candidates->pluck('id')->all());
        foreach ([[$this->p4s, 'p-if016', $candidates[0]], [$this->rawpol, 'r-if016', $candidates[1]]] as [$account, $position, $candidate]) {
            $row = CardRedirect::query()->where('source_key', 'b2b:'.$account->id)->where('position_key', $position)->firstOrFail();
            $this->assertSame($owner->id, (int) $row->product_id);
            $this->assertSame(CardRedirect::REASON_MERGE, $row->reason);
            $this->assertSame($candidate->id, (int) $row->card_match_candidate_id);
        }

        // propozycje: połączone ręcznie, z decyzją i kopią zapasową
        $backupPath = (string) $response->json('backup_path');
        $this->assertFileExists($backupPath);
        $this->assertStringContainsString('card-match-manual-', $backupPath);
        foreach ($candidates as $i => $candidate) {
            $this->assertSame(CardMatchCandidate::STATUS_MERGED, $candidate->status);
            $this->assertSame(CardMatchCandidate::KIND_MERGE, $candidate->kind);
            $this->assertSame('manual', $candidate->matched_by);
            $this->assertNull($candidate->matched_value);
            $this->assertSame('połączone ręcznie', $candidate->reason);
            $this->assertSame($owner->id, (int) $candidate->target_product_id);
            $this->assertSame((string) $owner->id, $candidate->targets_key);
            $this->assertSame($admin->id, (int) $candidate->decided_by);
            $this->assertNotNull($candidate->decided_at);
            $this->assertSame($backupPath, $candidate->backup_path);
            $this->assertSame([
                'keep_product_id' => $owner->id,
                'drop_product_ids' => [$p4s->id, $rawpol->id],
                'note' => 'ten sam wyrób wg karty katalogowej',
                'confirm_brand' => false,
                'plan_hash' => $preview['plan_hash'],
            ], $candidate->decision_input);
            $this->assertSame([$p4s, $rawpol][$i]->sku, $candidate->source_snapshot['sku']);
        }

        // kopia: karty z rolami, wiersze rozmiarów z historią cen, propozycja tej pary sprzed decyzji
        $backup = json_decode((string) file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('card-match-manual', $backup['kind']);
        $this->assertSame($owner->id, $backup['keep_product_id']);
        $this->assertSame([$p4s->id, $rawpol->id], $backup['drop_product_ids']);
        $this->assertSame(['keep', 'drop', 'drop'], array_column($backup['cards'], 'role'));
        $rawpolRows = $backup['cards'][2]['rows'];
        $this->assertSame([$sizeRow->id], array_column($rawpolRows['product_variants'], 'id'));
        $this->assertCount(1, $rawpolRows['product_variant_price_history']);
        $this->assertSame(CardMatchCandidate::STATUS_PENDING, $backup['candidates_before'][0]['status']);
    }

    /** Przeniesienie wierszy rozmiarów robi ProductSizeMergeService::absorb (zmiana równoległa, agent B). */
    public function test_merge_moves_size_rows_with_history_to_kept_card(): void
    {
        $this->actingAsRole('admin');
        [$owner, $p4s, $rawpol] = $this->trio();
        $sizeRow = ProductVariant::query()->where('product_id', $rawpol->id)->firstOrFail();

        $preview = $this->preview([$owner->id, $p4s->id, $rawpol->id]);
        $this->mergeRequest([$owner->id, $p4s->id, $rawpol->id], $owner->id, $preview['plan_hash'])->assertOk();

        $this->assertSame($owner->id, (int) $sizeRow->fresh()?->product_id);
        $this->assertSame(1, ProductVariantPriceHistory::query()->where('product_variant_id', $sizeRow->id)->count());
    }

    public function test_other_brand_needs_confirmation(): void
    {
        $this->actingAsRole('admin');
        $keep = $this->card('IF/016', 'Półmaska filtrująca', 'ANRO', 10.00);
        $this->link($keep, $this->anro, 'IF/016');
        $drop = $this->card('X-016', 'Półmaska filtrująca', 'Inna Marka', 10.00);
        $this->link($drop, $this->p4s, 'p1');

        $preview = $this->preview([$keep->id, $drop->id]);
        $this->assertTrue($preview['can_merge']);
        $this->assertSame('brand', $preview['warnings'][0]['code']);

        $this->mergeRequest([$keep->id, $drop->id], $keep->id, $preview['plan_hash'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Wiem, łączę mimo innej marki'));
        $this->assertNotNull(Product::query()->find($drop->id));

        $this->mergeRequest([$keep->id, $drop->id], $keep->id, $preview['plan_hash'], ['confirm_brand' => true])->assertOk();
        $this->assertNull(Product::query()->find($drop->id));
        $this->assertTrue(CardMatchCandidate::query()->where('source_product_id', $drop->id)->value('decision_input')['confirm_brand']);
    }

    public function test_changed_data_since_preview_returns_409_with_fresh_preview(): void
    {
        $this->actingAsRole('admin');
        [$owner, $p4s] = $this->trio();
        $preview = $this->preview([$owner->id, $p4s->id]);
        ProductSourcePrice::query()->where('product_id', $p4s->id)->update(['purchase_price' => 9.10]);

        $response = $this->mergeRequest([$owner->id, $p4s->id], $owner->id, $preview['plan_hash'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'plan_changed');
        $fresh = $response->json('preview');
        $this->assertNotSame($preview['plan_hash'], $fresh['plan_hash']);
        $this->assertSame('9.10', $fresh['cards'][1]['sources'][0]['purchase_price']);
        $this->assertNotNull(Product::query()->find($p4s->id));
        $this->assertSame(0, CardMatchCandidate::query()->count());
        $this->assertSame([], array_values(array_diff(self::backups(), $this->backupsBefore)));

        $this->mergeRequest([$owner->id, $p4s->id], $owner->id, $fresh['plan_hash'])->assertOk();
    }

    public function test_rejected_pair_pointing_at_merged_card_moves_to_kept_card(): void
    {
        $this->actingAsRole('admin');
        [$owner, $p4s] = $this->trio();
        $other = $this->card('JSP-1', 'Półmaska innego dystrybutora', 'ANRO', 12.00);
        $rejected = CardMatchCandidate::query()->create([
            'source_product_id' => $other->id, 'target_product_id' => $p4s->id, 'status' => CardMatchCandidate::STATUS_REJECTED,
            'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE, 'matched_value' => 'IF016', 'reason' => 'inny wyrób',
        ]);
        $pendingOfOther = CardMatchCandidate::query()->create([
            'source_product_id' => $p4s->id, 'target_product_id' => $other->id, 'status' => CardMatchCandidate::STATUS_PENDING,
            'matched_by' => CardMatchCandidate::BY_EAN, 'matched_value' => '5901234567890',
        ]);

        $preview = $this->preview([$owner->id, $p4s->id]);
        $this->mergeRequest([$owner->id, $p4s->id], $owner->id, $preview['plan_hash'])->assertOk();

        $fresh = $rejected->fresh();
        $this->assertSame(CardMatchCandidate::STATUS_REJECTED, $fresh->status);
        $this->assertSame($owner->id, (int) $fresh->target_product_id);
        $this->assertSame((string) $owner->id, $fresh->targets_key);
        // propozycja do decyzji z kartą, która znikła — usunięta (odświeżenie policzy ją od nowa)
        $this->assertNull($pendingOfOther->fresh());
    }

    public function test_rejected_pair_of_kept_card_with_merged_card_is_removed_not_pointed_at_itself(): void
    {
        $this->actingAsRole('admin');
        [$owner, $p4s] = $this->trio();
        $selfPair = CardMatchCandidate::query()->create([
            'source_product_id' => $owner->id, 'target_product_id' => $p4s->id, 'status' => CardMatchCandidate::STATUS_REJECTED,
            'matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE, 'matched_value' => 'IF016', 'reason' => 'odrzucone wcześniej',
        ]);

        $preview = $this->preview([$owner->id, $p4s->id]);
        $this->mergeRequest([$owner->id, $p4s->id], $owner->id, $preview['plan_hash'])->assertOk();

        // para #owner → #owner nic by nie znaczyła; wiersz jest w kopii zapasowej
        $this->assertNull($selfPair->fresh());
        $this->assertSame(0, CardMatchCandidate::query()
            ->where('source_product_id', $owner->id)->where('target_product_id', $owner->id)->count());
    }

    public function test_backup_file_is_removed_when_merge_fails(): void
    {
        $this->actingAsRole('admin');
        [$owner, $p4s] = $this->trio();
        $preview = $this->preview([$owner->id, $p4s->id]);
        CardRedirect::saving(static function (): void {
            throw new RuntimeException('awaria zapisu mapy');
        });

        $this->mergeRequest([$owner->id, $p4s->id], $owner->id, $preview['plan_hash'])
            ->assertStatus(500)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'nic nie zmieniono'));

        $this->assertSame([], array_values(array_diff(self::backups(), $this->backupsBefore)));
        $this->assertNotNull(Product::query()->find($p4s->id));
        $this->assertSame(0, CardMatchCandidate::query()->count());
        $this->assertSame(1, B2bProductLink::query()->where('product_id', $p4s->id)->count());
    }

    public function test_requires_decide_permission_and_valid_input(): void
    {
        $this->actingAsRole('handlowiec')->givePermissionTo('card_matches.view');
        [$owner, $p4s] = $this->trio();
        $this->postJson('/api/card-matches/manual/preview', ['product_ids' => [$owner->id, $p4s->id]])->assertForbidden();
        $this->postJson('/api/card-matches/manual', [
            'product_ids' => [$owner->id, $p4s->id], 'keep_product_id' => $owner->id, 'plan_hash' => str_repeat('a', 40),
        ])->assertForbidden();

        $this->actingAsRole('admin');
        $this->postJson('/api/card-matches/manual/preview', ['product_ids' => [$owner->id]])->assertStatus(422);
        $this->postJson('/api/card-matches/manual/preview', ['product_ids' => [$owner->id, $owner->id]])->assertStatus(422);
        $this->postJson('/api/card-matches/manual/preview', ['product_ids' => range(1, 11)])->assertStatus(422);
        $this->postJson('/api/card-matches/manual', ['product_ids' => [$owner->id, $p4s->id], 'plan_hash' => str_repeat('a', 40)])->assertStatus(422);
        $this->postJson('/api/card-matches/manual', [
            'product_ids' => [$owner->id, $p4s->id], 'keep_product_id' => $owner->id, 'plan_hash' => str_repeat('a', 40), 'note' => str_repeat('x', 501),
        ])->assertStatus(422);
        // brak karty — blokada w podglądzie
        $missing = $this->preview([$owner->id, $p4s->id + 1000]);
        $this->assertTrue($this->hasBlocker($missing, 'Karta #'.($p4s->id + 1000).' już nie istnieje'));
        $this->assertFalse($missing['can_merge']);
    }

    /**
     * Karta producenta ANRO (konto Anro, opis, zdjęcie) i dwie karty dystrybutorów tego wyrobu: P4S (identyfikator
     * kodu) i Raw-Pol (wiersz rozmiaru z historią ceny, pozycja przetargu).
     *
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function trio(): array
    {
        $owner = $this->card('IF/016/F/PS', 'Półmaska ANRO IF/016/F/PS z filtrem', 'ANRO', 10.00,
            description: 'Półmaska filtrująca z zaworem wydechowym, klasa FFP2, rozmiar uniwersalny.');
        $this->link($owner, $this->anro, 'IF/016/F/PS');
        $this->slot($owner, $this->anro, 10.00);
        $this->image($owner, $this->anro, 'anro.jpg');

        $p4s = $this->card('P4S-IF016', 'Półmaska P4S IF/016/F/PS', 'ANRO', 9.50);
        $this->link($p4s, $this->p4s, 'p-if016');
        $this->slot($p4s, $this->p4s, 9.50);
        $this->image($p4s, $this->p4s, 'p4s.jpg');
        ProductIdentifier::query()->create([
            'product_id' => $p4s->id, 'type' => 'manufacturer_code', 'value' => 'IF/016/F/PS', 'normalized' => 'IF016FPS',
            'brand_key' => 'anro', 'source_key' => ProductSourcePrice::b2bKey($this->p4s->id), 'position_key' => 'p-if016',
        ]);

        $rawpol = $this->card('RP-IF016', 'Półmaska filtrująca IF/016 Raw-Pol', 'ANRO', 11.00);
        $this->link($rawpol, $this->rawpol, 'r-if016');
        $this->slot($rawpol, $this->rawpol, 11.00);
        $this->image($rawpol, $this->rawpol, 'rawpol.jpg');
        $row = $this->variant($rawpol, ProductVariant::KIND_SIZE, 'b2b:'.$this->rawpol->id, 'r-if016-u');
        ProductVariantPriceHistory::query()->create([
            'product_variant_id' => $row->id, 'purchase_price' => 11.00, 'currency' => 'PLN', 'source' => 'b2b:rawpol',
        ]);
        $this->tenderItem($rawpol);

        return [$owner, $p4s, $rawpol];
    }

    private function card(string $sku, string $name, string $manufacturer, float $price, ?string $description = null): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer, 'description' => $description,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
    }

    private function link(Product $product, B2bAccount $account, string $remoteId): void
    {
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => $remoteId, 'remote_sku' => $remoteId, 'product_id' => $product->id]);
    }

    private function slot(Product $product, B2bAccount $account, float $price): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id, 'source_key' => ProductSourcePrice::b2bKey($account->id), 'b2b_account_id' => $account->id,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
    }

    private function fileSlot(Product $product, PriceList $list): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $product->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10.00, 'purchase_price' => 10.00, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
    }

    private function priceList(string $manufacturer): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer, 'version' => 'v1', 'original_filename' => 'cennik.xlsx', 'rows_total' => 1,
            'products_created' => 1, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
    }

    private function image(Product $product, B2bAccount $account, string $path): void
    {
        ProductImage::query()->create([
            'product_id' => $product->id, 'b2b_account_id' => $account->id, 'path' => $path, 'is_primary' => true, 'sort_order' => 0,
            'checksum' => 'sum-'.$path,
        ]);
    }

    private function variant(Product $product, string $kind, string $source, string $remoteId, bool $removed = false): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id, 'kind' => $kind, 'source' => $source, 'remote_id' => $remoteId, 'label' => 'uniwersalny',
            'purchase_price' => 11.00, 'currency' => 'PLN', 'removed_at' => $removed ? now() : null,
        ]);
    }

    private function tenderItem(Product $product): TenderItem
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/'.random_int(1, 99999), 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);

        return TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Półmaska', 'main_product_id' => $product->id]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create(['username' => $connector, 'password' => 'x', 'sites' => [$connector.'.example'], 'connector' => $connector]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->withRole($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function preview(array $ids, ?int $keepId = null): array
    {
        return $this->postJson('/api/card-matches/manual/preview', array_filter([
            'product_ids' => $ids,
            'keep_product_id' => $keepId,
        ], static fn (mixed $v): bool => $v !== null))->assertOk()->json();
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $extra
     */
    private function mergeRequest(array $ids, int $keepId, string $hash, array $extra = []): TestResponse
    {
        return $this->postJson('/api/card-matches/manual', [
            'product_ids' => $ids, 'keep_product_id' => $keepId, 'plan_hash' => $hash, ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $preview */
    private function hasBlocker(array $preview, string $fragment): bool
    {
        foreach ($preview['blockers'] as $blocker) {
            if (str_contains((string) $blocker, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function backups(): array
    {
        return glob(storage_path('app/repair-backups/card-match-manual-*.json')) ?: [];
    }
}
