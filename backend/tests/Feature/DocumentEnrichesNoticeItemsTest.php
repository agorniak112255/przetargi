<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderActivity;
use App\Models\TenderDocument;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderPricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Dokument postępowania uzupełnia pozycje z treści ogłoszenia (source = 'notice_text') zamiast je dublować:
 * propozycja 1:1 w podglądzie (replaces_item_id), zapis aktualizuje pozycję (wymaganie i ilość z dokumentu,
 * dopasowanie wyczyszczone, chyba że wybrał je człowiek), usunięcie pozycji rozpisanej przez dokument na kilka.
 */
final class DocumentEnrichesNoticeItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->withRole('przetargi')->create();
        Sanctum::actingAs($this->user);
    }

    public function test_one_to_one_proposal_and_commit_updates_notice_items(): void
    {
        $tender = $this->tender();
        $helmet = $this->noticeItem($tender, 1, 'Hełm strażacki — EN 443', 23);
        $suit = $this->noticeItem($tender, 2, 'Ubranie specjalne', 23);
        $boots = $this->noticeItem($tender, 3, 'Buty strażackie', 10);

        $preview = $this->analyze($tender, [
            ['Hełm strażacki typu A zgodny z EN 443:2008, kolor biały', 23],
            // ilości w dokumencie brak — zostaje ilość z ogłoszenia
            ['Ubranie specjalne trzyczęściowe z membraną', null],
            // bez odpowiednika w ogłoszeniu — nowa pozycja
            ['Rękawice strażackie pięciopalcowe', 30],
            // ilość inna niż w ogłoszeniu — tylko „możliwe”, niezaznaczone
            ['Buty strażackie skórzane, S3', 12],
        ])->assertOk();

        $items = $preview->json('items');
        $this->assertSame([$helmet->id, $suit->id, null, null], array_column($items, 'replaces_item_id'));
        $this->assertSame([[$helmet->id], [$suit->id], [], [$boots->id]], array_column($items, 'replaces_options'));
        $this->assertSame('Hełm strażacki · ilość 23', $items[0]['replaces_label']);
        $this->assertSame([false, true, false, false], array_column($items, 'quantity_missing'));
        $this->assertSame([$helmet->id, $suit->id, $boots->id], array_column($preview->json('notice_items'), 'id'));

        // człowiek wybiera „możliwe” dla butów
        $items[3]['replaces_item_id'] = $boots->id;
        $this->commit($tender, ['items' => $this->forCommit($items)])
            ->assertOk()
            ->assertJsonPath('items_created', 1)
            ->assertJsonPath('items_updated', 3)
            ->assertJsonPath('items_removed', 0);

        $helmet->refresh();
        $suit->refresh();
        $boots->refresh();
        $this->assertSame('Hełm strażacki typu A zgodny z EN 443:2008, kolor biały', $helmet->requirement);
        $this->assertSame([23, 23, 12], [(int) $helmet->quantity, (int) $suit->quantity, (int) $boots->quantity]);
        $this->assertSame([1, 2, 3], [(int) $helmet->line_no, (int) $suit->line_no, (int) $boots->line_no]);
        $this->assertSame(['document', 'document', 'document'], [$helmet->source, $suit->source, $boots->source]);
        $this->assertSame(4, TenderItem::query()->where('tender_id', $tender->id)->count());
        $new = TenderItem::query()->where('tender_id', $tender->id)->where('line_no', 4)->sole();
        $this->assertSame('Rękawice strażackie pięciopalcowe', $new->requirement);
        $this->assertSame('document', $new->source);

        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'items_updated_from_document')->sole();
        $this->assertCount(3, $activity->meta['items']);
        $this->assertSame('Hełm strażacki — EN 443', $activity->meta['items'][0]['before']['requirement']);
        $this->assertStringContainsString('Dokument uzupełnił 3 pozycje', $activity->meta['note']);

        // uzupełniona pozycja nie jest już pozycją z ogłoszenia — drugi dokument jej nie podmieni
        $again = $this->analyze($tender, [['Hełm strażacki typu A zgodny z EN 443:2008', 23]])->assertOk();
        $this->assertNull($again->json('items.0.replaces_item_id'));
        $this->assertSame([], $again->json('notice_items'));
    }

    public function test_tie_between_two_document_rows_is_not_selected(): void
    {
        $tender = $this->tender();
        $gloves = $this->noticeItem($tender, 1, 'Rękawice', 100);

        $items = $this->analyze($tender, [
            ['Rękawice nitrylowe', 100],
            ['Rękawice skórzane', 100],
        ])->assertOk()->json('items');

        $this->assertSame([null, null], array_column($items, 'replaces_item_id'));
        $this->assertSame([[$gloves->id], [$gloves->id]], array_column($items, 'replaces_options'));
    }

    public function test_tie_between_two_notice_items_is_not_selected(): void
    {
        $tender = $this->tender();
        $first = $this->noticeItem($tender, 1, 'Rękawice', 100);
        $second = $this->noticeItem($tender, 2, 'Rękawice', 100);

        $items = $this->analyze($tender, [['Rękawice nitrylowe', 100]])->assertOk()->json('items');

        $this->assertNull($items[0]['replaces_item_id']);
        $this->assertSame([$first->id, $second->id], $items[0]['replaces_options']);
    }

    public function test_set_split_into_jacket_and_trousers_can_remove_notice_item(): void
    {
        $tender = $this->tender();
        $set = $this->noticeItem($tender, 1, 'Ubranie robocze', 5);

        $preview = $this->analyze($tender, [
            ['Kurtka robocza', 5],
            ['Spodnie robocze', 5],
        ])->assertOk();
        $this->assertSame([null, null], array_column($preview->json('items'), 'replaces_item_id'));
        $this->assertSame([[], []], array_column($preview->json('items'), 'replaces_options'));
        $this->assertSame([$set->id], array_column($preview->json('notice_items'), 'id'));

        $this->commit($tender, [
            'items' => $this->forCommit($preview->json('items')),
            'remove_item_ids' => [$set->id],
        ])->assertOk()
            ->assertJsonPath('items_created', 2)
            ->assertJsonPath('items_removed', 1);

        $this->assertNull(TenderItem::query()->find($set->id));
        $this->assertSame(
            ['Kurtka robocza', 'Spodnie robocze'],
            TenderItem::query()->where('tender_id', $tender->id)->orderBy('line_no')->pluck('requirement')->all(),
        );
        $activity = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'items_removed_by_document')->sole();
        $this->assertSame($set->id, $activity->meta['items'][0]['id']);
        $this->assertStringContainsString('dokument rozpisuje je na kilka pozycji', $activity->meta['note']);
    }

    public function test_human_choice_is_kept_and_model_match_is_cleared_completely(): void
    {
        $tender = $this->tender();
        $card = $this->card('HELM-1');
        $other = $this->card('PAS-1');
        $manual = $this->noticeItem($tender, 1, 'Hełm strażacki', 2, [
            'main_product_id' => $card->id, 'match_source' => 'manual', 'offer_price' => 50, 'ai_match_percent' => 90, 'status' => 'matched',
        ]);
        $custom = $this->noticeItem($tender, 2, 'Latarka kątowa', 2, [
            'custom_name' => 'Latarka z hurtowni', 'match_source' => 'custom', 'offer_price' => 30, 'status' => 'matched',
        ]);
        $priced = $this->noticeItem($tender, 3, 'Pas strażacki', 2, [
            'main_product_id' => $other->id, 'match_source' => 'ai', 'offer_price' => 35, 'ai_match_percent' => 70, 'status' => 'matched',
        ]);
        // cena oferty zmieniona ręcznie przy tej samej karcie
        TenderActivity::query()->create([
            'tender_id' => $tender->id,
            'tender_item_id' => $priced->id,
            'user_id' => $this->user->id,
            'action' => 'item_updated',
            'meta' => [
                'before' => ['main_product_id' => $other->id, 'offer_price' => '40.00'],
                'after' => ['main_product_id' => $other->id, 'offer_price' => '35.00'],
            ],
        ]);
        $model = $this->noticeItem($tender, 4, 'Kominiarka niepalna', 2, [
            'main_product_id' => $card->id,
            'companion_product_id' => $other->id,
            'companion_offer_price' => 5,
            'match_source' => 'ai',
            'offer_price' => 40,
            'margin_percent' => 12.5,
            'ai_match_percent' => 80,
            'ai_match_reasons' => [['code' => 'x', 'label' => 'powód', 'points' => 80]],
            'battlecard_substitutes' => [['sku' => 'X']],
            'status' => 'matched',
        ]);

        $preview = $this->analyze($tender, [
            ['Hełm strażacki z osłoną twarzy', 2],
            ['Latarka kątowa akumulatorowa', 2],
            ['Pas strażacki bojowy', 2],
            ['Kominiarka niepalna z aramidu', 2],
        ])->assertOk();
        $this->assertSame([$manual->id, $custom->id, $priced->id, $model->id], array_column($preview->json('items'), 'replaces_item_id'));
        $this->assertSame([true, true, true, false], array_column($preview->json('notice_items'), 'keeps_product'));

        $this->commit($tender, ['items' => $this->forCommit($preview->json('items'))])
            ->assertOk()
            ->assertJsonPath('items_updated', 4)
            ->assertJsonPath('items_created', 0);

        $manual->refresh();
        $this->assertSame($card->id, (int) $manual->main_product_id);
        $this->assertSame('manual', $manual->match_source);
        $this->assertSame('Hełm strażacki z osłoną twarzy', $manual->requirement);
        // marża zachowanego produktu liczona z pełnej karty (nie z okrojonej relacji z samą nazwą)
        $this->assertNotNull($manual->margin_percent);
        $this->assertEqualsWithDelta(
            (float) app(TenderPricingService::class)->itemMargin($manual, SupplierSpecialMask::revealing()),
            (float) $manual->margin_percent,
            0.01,
        );
        $custom->refresh();
        $this->assertSame('Latarka z hurtowni', $custom->custom_name);
        $priced->refresh();
        $this->assertSame($other->id, (int) $priced->main_product_id);
        $this->assertSame('35.00', (string) $priced->offer_price);

        $model->refresh();
        $this->assertNull($model->main_product_id);
        $this->assertNull($model->companion_product_id);
        $this->assertNull($model->companion_offer_price);
        $this->assertNull($model->offer_price);
        $this->assertNull($model->margin_percent);
        $this->assertNull($model->ai_match_percent);
        $this->assertNull($model->ai_match_reasons);
        $this->assertNull($model->match_source);
        $this->assertNull($model->battlecard_substitutes);
        $this->assertSame('brak', $model->status);
        $this->assertSame('Kominiarka niepalna z aramidu', $model->requirement);

        $meta = TenderActivity::query()->where('tender_id', $tender->id)->where('action', 'items_updated_from_document')->sole()->meta;
        $this->assertSame([null, null, null, $card->name], array_column($meta['items'], 'removed_product'));
        $this->assertSame([$card->name, 'Latarka z hurtowni', $other->name, null], array_column($meta['items'], 'kept_product'));
    }

    public function test_accessory_with_helmet_in_name_does_not_take_the_helmet(): void
    {
        $tender = $this->tender();
        $helmet = $this->noticeItem($tender, 1, 'Hełm strażacki', 23);

        // formularz bez kolumny ilości (jak tabela opisów w Wordzie) — warunek ilości nic tu nie rozstrzyga
        $items = $this->analyze($tender, [
            ['Latarka do hełmu strażackiego', null],
            ['Hełm strażacki typu A z osłoną karku', null],
        ])->assertOk()->json('items');
        $this->assertSame([null, $helmet->id], array_column($items, 'replaces_item_id'));
        $this->assertSame([$helmet->id], $items[0]['replaces_options']);
        $this->assertNotNull($items[0]['replaces_hint']);

        // sama latarka w dokumencie — możliwa, ale nie zaznaczona (inny rzeczownik, akcesorium zamiast hełmu)
        $alone = $this->analyze($tender, [['Latarka do hełmu strażackiego', null]])->assertOk()->json('items.0');
        $this->assertNull($alone['replaces_item_id']);
        $this->assertSame([$helmet->id], $alone['replaces_options']);
        $this->assertSame('nazwy zaczynają się od innego rzeczownika', $alone['replaces_hint']);
    }

    public function test_price_edit_on_earlier_card_does_not_protect_current_model_card(): void
    {
        // ręczna cena przy karcie A, potem „Dopasuj AI” podmienił kartę na B (bez wpisu item_updated) — B nie jest
        // wyborem człowieka (przegląd runda 2)
        $tender = $this->tender();
        $old = $this->card('HELM-A');
        $new = $this->card('HELM-B');
        $item = $this->noticeItem($tender, 1, 'Hełm strażacki', 2, [
            'main_product_id' => $new->id, 'match_source' => 'ai', 'offer_price' => 50, 'ai_match_percent' => 80, 'status' => 'matched',
        ]);
        TenderActivity::query()->create([
            'tender_id' => $tender->id,
            'tender_item_id' => $item->id,
            'user_id' => $this->user->id,
            'action' => 'item_updated',
            'meta' => [
                'before' => ['main_product_id' => $old->id, 'offer_price' => '40.00'],
                'after' => ['main_product_id' => $old->id, 'offer_price' => '45.00'],
            ],
        ]);

        $preview = $this->analyze($tender, [['Hełm strażacki z osłoną karku', 2]])->assertOk();
        $this->assertSame([false], array_column($preview->json('notice_items'), 'keeps_product'));
    }

    public function test_short_head_noun_must_match(): void
    {
        $tender = $this->tender();
        $belt = $this->noticeItem($tender, 1, 'Pas strażacki', 2);

        $items = $this->analyze($tender, [
            ['Hełm strażacki', null],
            ['Pasy strażackie bojowe', null],
        ])->assertOk()->json('items');
        $this->assertSame([[], [$belt->id]], array_column($items, 'replaces_options'));
        $this->assertSame([null, $belt->id], array_column($items, 'replaces_item_id'));

        // „pasek” to zdrobnienie, nie odmiana „pas” — a akcesorium hełmu nie zastępuje pasa (przegląd runda 2)
        $items = $this->analyze($tender, [
            ['Pasek podbródkowy do hełmu strażackiego', null],
        ])->assertOk()->json('items');
        $this->assertNull($items[0]['replaces_item_id']);
        $this->assertSame([], $items[0]['replaces_options']);
    }

    public function test_notice_items_from_several_lots_are_only_possible(): void
    {
        $tender = $this->tender();
        $first = $this->noticeItem($tender, 1, 'Hełm strażacki', 23, ['source_ref' => '2026/BZP 00123456/01|część 1']);
        $this->noticeItem($tender, 2, 'Rękawice strażackie', 23, ['source_ref' => '2026/BZP 00123456/01|część 2']);

        $preview = $this->analyze($tender, [['Hełm strażacki typu A', 23]])->assertOk();
        $this->assertNull($preview->json('items.0.replaces_item_id'));
        $this->assertSame([$first->id], $preview->json('items.0.replaces_options'));
        $this->assertSame('pozycje z ogłoszenia pochodzą z kilku części zamówienia', $preview->json('items.0.replaces_hint'));
        $this->assertSame('Hełm strażacki · ilość 23 · część 1', $preview->json('notice_items.0.label'));
        $this->assertSame([1, 2], array_column($preview->json('notice_items'), 'lot_no'));
    }

    public function test_product_picked_by_user_in_search_is_kept_and_automatic_hint_is_cleared(): void
    {
        $tender = $this->tender();
        $card = $this->card('HELM-2');
        // wybór w oknie wyszukiwania: match_source 'ai', ale wpis historii od użytkownika ze zmianą karty na obecną
        $picked = $this->noticeItem($tender, 1, 'Hełm strażacki', 2, [
            'main_product_id' => $card->id, 'match_source' => 'ai', 'offer_price' => 30, 'status' => 'matched',
        ]);
        TenderActivity::query()->create([
            'tender_id' => $tender->id,
            'tender_item_id' => $picked->id,
            'user_id' => $this->user->id,
            'action' => 'item_updated',
            'meta' => ['before' => ['main_product_id' => null, 'offer_price' => null], 'after' => ['main_product_id' => $card->id, 'offer_price' => '30.00']],
        ]);
        // podpowiedź automatu (produkt spoza katalogu) to nie wybór człowieka
        $external = $this->noticeItem($tender, 2, 'Latarka kątowa', 2, [
            'custom_name' => 'Latarka ze sklepu', 'custom_url' => 'https://example.test/latarka', 'match_source' => 'external', 'status' => 'matched',
        ]);

        $preview = $this->analyze($tender, [
            ['Hełm strażacki z osłoną karku', 2],
            ['Latarka kątowa akumulatorowa', 2],
        ])->assertOk();
        $this->assertSame([true, false], array_column($preview->json('notice_items'), 'keeps_product'));

        $this->commit($tender, ['items' => $this->forCommit($preview->json('items'))])->assertOk()->assertJsonPath('items_updated', 2);
        $this->assertSame($card->id, (int) $picked->fresh()->main_product_id);
        $external->refresh();
        $this->assertNull($external->custom_name);
        $this->assertNull($external->custom_url);
        $this->assertNull($external->match_source);
        $this->assertSame('brak', $external->status);
    }

    public function test_old_saved_analysis_quantity_one_does_not_overwrite_notice_quantity(): void
    {
        $tender = $this->tender();
        $helmet = $this->noticeItem($tender, 1, 'Hełm strażacki', 23);
        // odczyt zapisany przed wdrożeniem: bez znacznika quantity_missing, domyślna jedynka
        $document = TenderDocument::query()->create([
            'tender_id' => $tender->id,
            'uploaded_by' => $this->user->id,
            'original_name' => 'opis.pdf',
            'extension' => 'pdf',
            'mode' => 'ai',
            'targets' => ['items'],
            'extracted_text' => 'Hełm strażacki typu A',
            'analysis_json' => ['items' => [['sku' => null, 'name' => 'Hełm strażacki typu A', 'requirement' => 'Hełm strażacki typu A', 'quantity' => 1, 'selected' => true]], 'conditions' => []],
        ]);

        $show = $this->getJson("/api/tenders/{$tender->id}/documents/{$document->id}")->assertOk();
        $this->assertTrue($show->json('analysis_json.items.0.quantity_missing'));
        $this->assertSame($helmet->id, $show->json('analysis_json.items.0.replaces_item_id'));

        $this->commit($tender, ['document_id' => $document->id, 'items' => $this->forCommit($show->json('analysis_json.items'))])
            ->assertOk()->assertJsonPath('items_updated', 1);
        $this->assertSame(23, (int) $helmet->fresh()->quantity);
    }

    public function test_article_number_from_document_picks_new_card_with_fresh_margin(): void
    {
        $tender = $this->tender();
        $old = $this->card('STARY-1');
        $new = $this->card('NOWY-1', 50);
        $item = $this->noticeItem($tender, 1, 'Hełm strażacki', 2, [
            'main_product_id' => $old->id, 'match_source' => 'ai', 'offer_price' => 25, 'ai_match_percent' => 70, 'status' => 'matched',
        ]);
        $item->load('mainProduct');

        $this->commit($tender, ['items' => [
            ['sku' => 'NOWY-1', 'requirement' => 'Hełm strażacki NOWY-1', 'quantity' => 2, 'replaces_item_id' => $item->id],
        ]])->assertOk()->assertJsonPath('items_updated', 1);

        $item = TenderItem::query()->findOrFail($item->id);
        $this->assertSame($new->id, (int) $item->main_product_id);
        $this->assertSame('matched', $item->status);
        $this->assertNotNull($item->offer_price);
        // marża z nowej karty, nie ze zdjętej
        $expected = app(TenderPricingService::class)->itemMargin($item, SupplierSpecialMask::revealing());
        $this->assertNotNull($expected);
        $this->assertEqualsWithDelta($expected, (float) $item->margin_percent, 0.01);
    }

    public function test_saved_document_preview_computes_replacements_now(): void
    {
        $tender = $this->tender();
        $documentId = $this->analyze($tender, [['Hełm strażacki typu A', 23]], 'full')->assertOk()->json('document_id');
        $this->assertNotNull($documentId);

        // pozycja z ogłoszenia dodana po odczycie pliku — podgląd zapisanego pliku liczy propozycję na bieżąco
        $helmet = $this->noticeItem($tender, 1, 'Hełm strażacki', 23);
        $show = $this->getJson("/api/tenders/{$tender->id}/documents/{$documentId}")->assertOk();
        $this->assertSame($helmet->id, $show->json('analysis_json.items.0.replaces_item_id'));
        $this->assertSame([$helmet->id], array_column($show->json('notice_items'), 'id'));

        // commit z dokumentu zapisuje pochodzenie z numerem dokumentu
        $this->commit($tender, [
            'document_id' => $documentId,
            'items' => $this->forCommit($show->json('analysis_json.items')),
        ])->assertOk()->assertJsonPath('items_updated', 1);
        $this->assertSame('dokument #'.$documentId, $helmet->fresh()->source_ref);
    }

    public function test_replace_items_ignores_replacements(): void
    {
        $tender = $this->tender();
        $helmet = $this->noticeItem($tender, 1, 'Hełm strażacki', 23);

        $items = $this->analyze($tender, [['Hełm strażacki typu A', 23]])->assertOk()->json('items');
        $this->assertSame($helmet->id, $items[0]['replaces_item_id']);

        $this->commit($tender, ['items' => $this->forCommit($items), 'replace_items' => true, 'remove_item_ids' => [$helmet->id]])
            ->assertOk()
            ->assertJsonPath('items_created', 1)
            ->assertJsonPath('items_updated', 0)
            ->assertJsonPath('items_removed', 0);

        $this->assertNull(TenderItem::query()->find($helmet->id));
        $this->assertSame(['Hełm strażacki typu A'], TenderItem::query()->where('tender_id', $tender->id)->pluck('requirement')->all());
        $this->assertFalse(TenderActivity::query()->where('action', 'items_updated_from_document')->exists());
    }

    public function test_foreign_manual_and_repeated_ids_become_new_items(): void
    {
        $tender = $this->tender();
        $otherTender = $this->tender();
        $foreign = $this->noticeItem($otherTender, 1, 'Hełm strażacki', 23);
        $manual = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Kurtka', 'quantity' => 4, 'status' => 'brak',
        ]);
        $own = $this->noticeItem($tender, 2, 'Pas strażacki', 2);

        $this->commit($tender, ['items' => [
            ['requirement' => 'Hełm strażacki A', 'quantity' => 23, 'replaces_item_id' => $foreign->id],
            ['requirement' => 'Kurtka zimowa', 'quantity' => 4, 'replaces_item_id' => $manual->id],
            ['requirement' => 'Pas strażacki bojowy', 'quantity' => 2, 'replaces_item_id' => $own->id],
            ['requirement' => 'Pas strażacki ratowniczy', 'quantity' => 2, 'replaces_item_id' => $own->id],
        ]])->assertOk()
            ->assertJsonPath('items_created', 3)
            ->assertJsonPath('items_updated', 1);

        $this->assertSame('Hełm strażacki', $foreign->fresh()->requirement);
        $this->assertSame('Kurtka', $manual->fresh()->requirement);
        $this->assertSame('Pas strażacki bojowy', $own->fresh()->requirement);
        $this->assertSame(5, TenderItem::query()->where('tender_id', $tender->id)->count());
        $this->assertSame(1, TenderItem::query()->where('tender_id', $otherTender->id)->count());
    }

    public function test_import_without_notice_items_is_unchanged(): void
    {
        $tender = $this->tender();
        $manual = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Hełm strażacki', 'quantity' => 23, 'status' => 'brak',
        ]);

        $preview = $this->analyze($tender, [['Hełm strażacki typu A', 23]])->assertOk();
        $this->assertSame([], $preview->json('notice_items'));
        $this->assertArrayNotHasKey('replaces_item_id', $preview->json('items.0'));

        $this->commit($tender, ['items' => $this->forCommit($preview->json('items'))])
            ->assertOk()
            ->assertJsonPath('items_created', 1)
            ->assertJsonPath('items_updated', 0);
        $this->assertSame('Hełm strażacki', $manual->fresh()->requirement);
        $this->assertNull($manual->fresh()->source);
        $this->assertSame(2, TenderItem::query()->where('tender_id', $tender->id)->count());

        // tekst wklejony w trybie prostym — pozycje bez pochodzenia (jak dodane ręcznie)
        $this->commit($tender, ['simple_text' => "Okulary ochronne\nGogle", 'simple_as' => 'items'])->assertOk()
            ->assertJsonPath('items_created', 2);
        $this->assertSame(
            [null, 'document', null, null],
            TenderItem::query()->where('tender_id', $tender->id)->orderBy('line_no')->pluck('source')->all(),
        );
    }

    private function tender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/OGL/'.uniqid(),
            'title' => 'Sprzęt strażacki',
            'client_id' => Client::query()->create(['name' => 'Gmina'])->id,
            'owner_id' => $this->user->id,
            'status' => 'wycena',
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function noticeItem(Tender $tender, int $lineNo, string $requirement, int $quantity, array $extra = []): TenderItem
    {
        $item = new TenderItem([
            'tender_id' => $tender->id,
            'line_no' => $lineNo,
            'requirement' => $requirement,
            'quantity' => $quantity,
            'status' => 'brak',
            'source' => 'notice_text',
            'source_ref' => '2026/BZP 00123456/01',
            ...$extra,
        ]);
        if (array_key_exists('margin_percent', $extra)) {
            $item->margin_percent = $extra['margin_percent'];
        }
        $item->save();

        return $item;
    }

    private function card(string $sku, float $purchase = 20): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Karta '.$sku,
            'manufacturer' => 'REJS',
            'category' => 'Ochrona',
            'description' => 'Opis '.$sku.'.',
            'catalog_price_net' => $purchase,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /**
     * Formularz cenowy z dokumentu (arkusz): nazwa i ilość (null — pusta komórka).
     *
     * @param  list<array{0: string, 1: ?int}>  $rows
     */
    private function analyze(Tender $tender, array $rows, string $mode = 'simple'): TestResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $data = [['Lp.', 'Nazwa przedmiotu', 'Ilość', 'J.m.']];
        foreach ($rows as $i => [$name, $qty]) {
            $data[] = [$i + 1, $name, $qty, 'szt.'];
        }
        $sheet->fromArray($data);
        $path = tempnam(sys_get_temp_dir(), 'form').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            return $this->post("/api/tenders/{$tender->id}/documents/analyze", [
                'file' => new UploadedFile($path, 'formularz.xlsx', null, null, true),
                'mode' => $mode,
                'targets' => ['items'],
            ], ['Accept' => 'application/json']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Pozycje podglądu tak, jak wysyła je kreator (commitDocument).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function forCommit(array $items): array
    {
        return array_map(static fn (array $i): array => [
            'sku' => $i['sku'] ?? null,
            'name' => $i['name'] ?? $i['requirement'],
            'requirement' => $i['requirement'],
            'quantity' => $i['quantity'],
            'quantity_missing' => $i['quantity_missing'] ?? false,
            'replaces_item_id' => $i['replaces_item_id'] ?? null,
        ], $items);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function commit(Tender $tender, array $body): TestResponse
    {
        return $this->postJson("/api/tenders/{$tender->id}/documents/commit", $body);
    }
}
