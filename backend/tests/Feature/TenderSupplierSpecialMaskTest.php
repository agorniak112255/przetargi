<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\BattlecardService;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderPricingService;
use App\Support\OfferPricing;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Przetargi a cena specjalna B2B (decyzja właściciela 30.09.2026): użytkownik bez prices.supplier_special.view liczy
 * oferty od ceny standardowej karty (211,37 zł zamiast 173,19 zł) na każdej ścieżce, widzi marżę bliźniaczą
 * (margin_percent_standard pod nazwą margin_percent) i ceny standardowe w widokach, eksportach i battlecardach.
 * Admin i dyrektor widzą prawdziwe ceny i marże. Zapisane ceny kart się nie zmieniają.
 */
final class TenderSupplierSpecialMaskTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    private const MARKUP = 18.0;

    private const REQUIREMENT = 'Rękawice robocze RNITZ-M ze ściągaczem';

    private Product $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSupplierSpecial();
        $this->card = $this->supplierSpecialCard('RNITZ-M', [
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ ze ściągaczem, dzianina bawełniana, powlekane nitrylem, do prac montażowych i magazynowych.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl', 'bawełna']],
            'enriched_at' => now(),
        ])['product'];
    }

    // ── oferty: każda ścieżka liczy od ceny widza ─────────────────────────────────────────────

    public function test_match_item_offer_follows_viewer_price(): void
    {
        $this->modelDown();
        foreach ($this->viewers() as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, self::REQUIREMENT);

            $this->postJson("/api/tenders/{$tender->id}/items/{$item->id}/match", ['force' => true])->assertOk();

            $item->refresh();
            $this->assertSame((int) $this->card->id, (int) $item->main_product_id);
            $this->assertOffer($purchase, $item->offer_price, $user->role);
            $this->assertCardUntouched();
        }
    }

    public function test_match_tender_offer_follows_viewer_price(): void
    {
        $this->modelDown();
        foreach ($this->viewers() as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, self::REQUIREMENT);

            $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])->assertOk();

            $item->refresh();
            $this->assertSame((int) $this->card->id, (int) $item->main_product_id);
            $this->assertOffer($purchase, $item->offer_price, $user->role);
            $this->assertCardUntouched();
        }
    }

    public function test_spreadsheet_import_offer_follows_viewer_price(): void
    {
        foreach ([[$this->userWithRole('przetargi'), self::SPECIAL_STANDARD], [$this->userWithRole('admin'), self::SPECIAL_PRICE]] as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender] = $this->tenderWith($user, null);

            $this->post("/api/tenders/{$tender->id}/import", [
                'file' => $this->xlsx([['wymaganie', 'sku', 'ilość'], [self::REQUIREMENT, 'RNITZ-M', 2]]),
                'replace' => true,
            ])->assertOk();

            $item = TenderItem::query()->where('tender_id', $tender->id)->sole();
            $this->assertOffer($purchase, $item->offer_price, $user->role);
            $this->assertCardUntouched();
        }
    }

    public function test_document_commit_offer_follows_viewer_price(): void
    {
        foreach ([[$this->userWithRole('przetargi'), self::SPECIAL_STANDARD], [$this->userWithRole('admin'), self::SPECIAL_PRICE]] as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender] = $this->tenderWith($user, null);

            $this->postJson("/api/tenders/{$tender->id}/documents/commit", [
                'items' => [['requirement' => self::REQUIREMENT, 'sku' => 'RNITZ-M', 'quantity' => 2]],
            ])->assertOk();

            $item = TenderItem::query()->where('tender_id', $tender->id)->sole();
            $this->assertOffer($purchase, $item->offer_price, $user->role);
            $this->assertCardUntouched();
        }
    }

    public function test_item_update_product_change_offer_follows_viewer_price(): void
    {
        foreach ($this->viewers() as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, self::REQUIREMENT);

            $response = $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", ['main_product_id' => $this->card->id])
                ->assertOk();

            $this->assertOffer($purchase, $item->fresh()->offer_price, $user->role);
            if ($user->role === 'handlowiec') {
                $this->assertNoSpecialLeak((string) $response->getContent());
                $this->assertSame(self::SPECIAL_STANDARD, $response->json('main_product.purchase_price'));
            } else {
                $this->assertSame(self::SPECIAL_PRICE, $response->json('main_product.purchase_price'));
            }
            $this->assertCardUntouched();
        }
    }

    public function test_item_update_variant_change_offer_follows_viewer_price(): void
    {
        $xl = $this->card->variants()->where('label', 'XL')->sole();
        foreach ([[$this->userWithRole('handlowiec'), self::SPECIAL_SIZE_MAX_MASKED], [$this->userWithRole('admin'), self::SPECIAL_SIZE_MAX]] as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, self::REQUIREMENT, ['main_product_id' => $this->card->id, 'status' => 'matched']);

            $response = $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", ['main_variant_id' => $xl->id])->assertOk();

            $this->assertOffer($purchase, $item->fresh()->offer_price, $user->role);
            if ($user->role === 'handlowiec') {
                $this->assertNoSpecialLeak((string) $response->getContent());
                $this->assertSame(self::SPECIAL_SIZE_MAX_MASKED, $response->json('main_variant.purchase_price'));
            }
            $this->assertCardUntouched();
        }
    }

    public function test_item_update_companion_offer_follows_viewer_price(): void
    {
        $main = $this->plainCard('MAIN-1', 40.0);
        foreach ($this->viewers() as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, self::REQUIREMENT, ['main_product_id' => $main->id, 'offer_price' => 50, 'status' => 'matched']);

            $response = $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", ['companion_product_id' => $this->card->id])
                ->assertOk();

            $this->assertOffer($purchase, $item->fresh()->companion_offer_price, $user->role);
            if ($user->role === 'handlowiec') {
                $this->assertNoSpecialLeak((string) $response->getContent());
            }
            $this->assertCardUntouched();
        }
    }

    public function test_apply_cheaper_substitutes_offer_follows_viewer_price(): void
    {
        $expensive = $this->plainCard('DROGIE-1', 300.0);
        ProductSubstitute::query()->create([
            'main_product_id' => $expensive->id,
            'substitute_product_id' => $this->card->id,
            'type' => 'tanszy',
            'match_percent' => 90,
            'approval_status' => 'zatwierdzony',
        ]);
        foreach ($this->viewers() as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, 'Rękawice ochronne', ['main_product_id' => $expensive->id, 'offer_price' => 354, 'status' => 'matched']);

            $response = $this->postJson("/api/tenders/{$tender->id}/items/apply-cheaper-substitutes", ['min_save_percent' => 3])
                ->assertOk();

            $this->assertSame(1, $response->json('applied_count'));
            $this->assertEqualsWithDelta((float) $purchase, (float) $response->json('candidates.0.purchase_price'), 0.001);
            $item->refresh();
            $this->assertSame((int) $this->card->id, (int) $item->main_product_id);
            $this->assertOffer($purchase, $item->offer_price, $user->role);
            if ($user->role === 'handlowiec') {
                $this->assertNoSpecialLeak((string) $response->getContent());
            }
            $this->assertCardUntouched();
        }
    }

    public function test_apply_cheaper_substitutes_is_atomic(): void
    {
        $expensive = $this->plainCard('DROGIE-2', 300.0);
        ProductSubstitute::query()->create([
            'main_product_id' => $expensive->id,
            'substitute_product_id' => $this->card->id,
            'type' => 'tanszy',
            'match_percent' => 90,
            'approval_status' => 'zatwierdzony',
        ]);
        $seller = $this->userWithRole('handlowiec');
        Sanctum::actingAs($seller);
        [$tender, $item] = $this->tenderWith($seller, 'Rękawice ochronne', ['main_product_id' => $expensive->id, 'offer_price' => 354, 'status' => 'matched']);
        // awaria przy zapisie sum przetargu — po zamianie pozycji i przeliczeniu jej marż
        Tender::saving(static function (): void {
            throw new RuntimeException('awaria zapisu przetargu');
        });

        $this->postJson("/api/tenders/{$tender->id}/items/apply-cheaper-substitutes", ['min_save_percent' => 3])->assertStatus(500);

        $fresh = $item->fresh();
        $this->assertSame((int) $expensive->id, (int) $fresh->main_product_id, 'zamiana wycofana razem z marżami');
        $this->assertSame('354.00', $fresh->offer_price);
        $this->assertNull($fresh->margin_percent_standard);
        $this->assertSame(0, $tender->activities()->where('action', 'item_updated')->count());
    }

    public function test_target_margin_change_prices_empty_offer_from_actor_view(): void
    {
        foreach ($this->viewers() as [$user, $purchase]) {
            Sanctum::actingAs($user);
            [$tender, $item] = $this->tenderWith($user, self::REQUIREMENT, ['main_product_id' => $this->card->id, 'status' => 'matched']);

            $this->patchJson("/api/tenders/{$tender->id}", ['target_margin_percent' => 25])->assertOk();

            $this->assertEqualsWithDelta(
                OfferPricing::fromPurchase((float) $purchase, 25.0),
                (float) $item->fresh()->offer_price,
                0.001,
                $user->role,
            );
            $this->assertCardUntouched();
        }
    }

    // ── marże: obie zapisane, widz dostaje swoją ───────────────────────────────────────────────

    public function test_both_margins_are_stored_and_viewers_get_their_own(): void
    {
        $seller = $this->userWithRole('handlowiec');
        Sanctum::actingAs($seller);
        [$tender, $item] = $this->tenderWith($seller, self::REQUIREMENT);
        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", ['main_product_id' => $this->card->id])->assertOk();

        $offer = OfferPricing::fromPurchase((float) self::SPECIAL_STANDARD, self::MARKUP);
        $real = round(($offer - (float) self::SPECIAL_PRICE) / $offer * 100, 2);
        $standard = round(($offer - (float) self::SPECIAL_STANDARD) / $offer * 100, 2);
        $item->refresh();
        $this->assertEqualsWithDelta($real, (float) $item->margin_percent, 0.001);
        $this->assertEqualsWithDelta($standard, (float) $item->margin_percent_standard, 0.001);
        $tender->refresh();
        $this->assertEqualsWithDelta($real, (float) $tender->margin_percent, 0.001);
        $this->assertEqualsWithDelta($standard, (float) $tender->margin_percent_standard, 0.001);

        // handlowiec: bliźniacza pod nazwą margin_percent — show, lista, edycja, zmiana statusu
        $show = $this->getJson("/api/tenders/{$tender->id}")->assertOk();
        $this->assertEqualsWithDelta($standard, (float) $show->json('tender.margin_percent'), 0.001);
        $this->assertEqualsWithDelta($standard, (float) $show->json('tender.items.0.margin_percent'), 0.001);
        $this->assertArrayNotHasKey('margin_percent_standard', $show->json('tender'));
        $this->assertNoSpecialLeak((string) $show->getContent());
        $index = $this->getJson('/api/tenders')->assertOk();
        $this->assertEqualsWithDelta($standard, (float) collect($index->json())->firstWhere('id', $tender->id)['margin_percent'], 0.001);
        $update = $this->patchJson("/api/tenders/{$tender->id}", ['title' => 'Nowy tytuł'])->assertOk();
        $this->assertEqualsWithDelta($standard, (float) $update->json('margin_percent'), 0.001);
        $transition = $this->postJson("/api/tenders/{$tender->id}/transition", ['status' => 'akceptacja_km'])->assertOk();
        $this->assertEqualsWithDelta($standard, (float) $transition->json('tender.margin_percent'), 0.001);
        $this->assertEqualsWithDelta($standard, (float) $transition->json('tender.items.0.margin_percent'), 0.001);
        $this->assertNoSpecialLeak((string) $transition->getContent());

        // dyrektor: prawdziwa marża i prawdziwa cena karty
        Sanctum::actingAs($this->userWithRole('dyrektor'));
        $show = $this->getJson("/api/tenders/{$tender->id}")->assertOk();
        $this->assertEqualsWithDelta($real, (float) $show->json('tender.margin_percent'), 0.001);
        $this->assertEqualsWithDelta($real, (float) $show->json('tender.items.0.margin_percent'), 0.001);
        $this->assertSame(self::SPECIAL_PRICE, $show->json('tender.items.0.main_product.purchase_price'));
        $index = $this->getJson('/api/tenders')->assertOk();
        $this->assertEqualsWithDelta($real, (float) collect($index->json())->firstWhere('id', $tender->id)['margin_percent'], 0.001);

        // admin: zmiana statusu i edycja z prawdziwą marżą
        Sanctum::actingAs($this->userWithRole('admin'));
        $transition = $this->postJson("/api/tenders/{$tender->id}/transition", ['status' => 'akceptacja_dyrektor'])->assertOk();
        $this->assertEqualsWithDelta($real, (float) $transition->json('tender.margin_percent'), 0.001);
        $update = $this->patchJson("/api/tenders/{$tender->id}", ['title' => 'Tytuł admina'])->assertOk();
        $this->assertEqualsWithDelta($real, (float) $update->json('margin_percent'), 0.001);
        $this->assertCardUntouched();
    }

    public function test_coverage_low_margin_follows_viewer_margin(): void
    {
        // oferta admina od ceny specjalnej: marża prawdziwa ok. 15,3%, od ceny standardowej ujemna
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);
        $seller = $this->userWithRole('handlowiec');
        [$tender, $item] = $this->tenderWith($seller, self::REQUIREMENT);
        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", ['main_product_id' => $this->card->id])->assertOk();
        $item->refresh();
        $this->assertGreaterThanOrEqual(15.0, (float) $item->margin_percent);
        $this->assertLessThan(0.0, (float) $item->margin_percent_standard);

        Sanctum::actingAs($seller);
        $this->assertSame(1, $this->getJson("/api/tenders/{$tender->id}/coverage")->assertOk()->json('low_margin'));
        $this->assertSame(1, $this->getJson("/api/tenders/{$tender->id}")->assertOk()->json('coverage.low_margin'));

        Sanctum::actingAs($this->userWithRole('dyrektor'));
        $this->assertSame(0, $this->getJson("/api/tenders/{$tender->id}/coverage")->assertOk()->json('low_margin'));
    }

    public function test_dashboard_and_reports_use_standard_margin_without_permission(): void
    {
        $owner = $this->userWithRole('handlowiec');
        [$tender] = $this->tenderWith($owner, null);
        $tender->forceFill(['margin_percent' => 20, 'margin_percent_standard' => 5, 'offer_value_net' => 1000])->save();

        Sanctum::actingAs($this->userWithRole('kierownik'));
        $this->assertEqualsWithDelta(5.0, (float) $this->getJson('/api/dashboard')->assertOk()->json('avg_margin_percent'), 0.001);
        $recent = $this->getJson('/api/dashboard')->json('recent_tenders.0');
        $this->assertEqualsWithDelta(5.0, (float) $recent['margin_percent'], 0.001);
        $this->assertArrayNotHasKey('margin_percent_standard', $recent);
        $summary = $this->getJson('/api/reports/summary')->assertOk();
        $this->assertEqualsWithDelta(5.0, (float) $summary->json('by_status.0.avg_margin'), 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $summary->json('by_owner.0.avg_margin'), 0.001);
        $csv = $this->get('/api/reports/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString(';5.00;', $csv);
        $this->assertStringNotContainsString(';20.00;', $csv);

        Sanctum::actingAs($this->userWithRole('dyrektor'));
        $this->assertEqualsWithDelta(20.0, (float) $this->getJson('/api/dashboard')->assertOk()->json('avg_margin_percent'), 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $this->getJson('/api/reports/summary')->json('by_status.0.avg_margin'), 0.001);
        $this->assertStringContainsString(';20.00;', $this->get('/api/reports/csv')->assertOk()->streamedContent());
    }

    // ── eksport i battlecard ────────────────────────────────────────────────────────────────

    public function test_excel_export_as_seller_shows_standard_prices_only(): void
    {
        [$tender, $seller] = $this->sellerTender();

        $xlsx = $this->get("/api/tenders/{$tender->id}/export/excel")->assertOk()->streamedContent();

        $cells = $this->spreadsheetText($xlsx);
        $this->assertNoSpecialLeak($cells);
        $this->assertStringContainsString(self::SPECIAL_STANDARD, $cells);
        $this->assertStringNotContainsString((string) $this->realMargin(), $cells);
        $this->assertStringContainsString((string) $this->standardMargin(), $cells);
    }

    public function test_pdf_export_as_seller_shows_standard_prices_only(): void
    {
        [$tender] = $this->sellerTender();
        $captured = null;
        $pdf = Mockery::mock(DomPdf::class);
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturn(new Response('%PDF'));
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(static function (string $view, array $data) use (&$captured, $pdf) {
            $captured = [$view, $data];

            return $pdf;
        });

        $this->get("/api/tenders/{$tender->id}/export/pdf")->assertOk();

        $this->assertNotNull($captured);
        $html = view($captured[0], $captured[1])->render();
        $this->assertNoSpecialLeak($html);
        $this->assertStringContainsString('211,37', $html);
        $this->assertStringContainsString('<strong>Marża:</strong> '.number_format($this->standardMargin(), 2, '.', '').'%', $html);
    }

    public function test_battlecard_prices_and_highlights_follow_viewer_price(): void
    {
        // zamiennik tańszy od ceny standardowej (211,37), ale nie od specjalnej (173,19)
        $substitute = $this->plainCard('ZAMIENNIK-1', 200.0);
        ProductSubstitute::query()->create([
            'main_product_id' => $this->card->id,
            'substitute_product_id' => $substitute->id,
            'type' => 'tanszy',
            'match_percent' => 90,
            'approval_status' => 'zatwierdzony',
        ]);
        $seller = $this->userWithRole('handlowiec');
        [$tender, $item] = $this->tenderWith($seller, 'Rękawice ochronne', ['main_product_id' => $this->card->id, 'offer_price' => 249.42, 'status' => 'matched']);

        Sanctum::actingAs($seller);
        $masked = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk();
        $this->assertNoSpecialLeak((string) $masked->getContent());
        $this->assertEqualsWithDelta((float) self::SPECIAL_STANDARD, (float) $masked->json('battlecard.ours.purchase_price'), 0.001);
        $this->assertEqualsWithDelta(OfferPricing::fromPurchase((float) self::SPECIAL_STANDARD, self::MARKUP), (float) $masked->json('battlecard.ours.suggested_offer_price'), 0.001);
        $this->assertStringContainsString('Zamiennik ZAMIENNIK-1 (REJS) tańszy', implode(' ', $masked->json('battlecard.highlights')));

        Sanctum::actingAs($this->userWithRole('dyrektor'));
        $real = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk();
        $this->assertEqualsWithDelta((float) self::SPECIAL_PRICE, (float) $real->json('battlecard.ours.purchase_price'), 0.001);
        $this->assertStringNotContainsString('tańszy', implode(' ', $real->json('battlecard.highlights')));
        $this->assertCardUntouched();
    }

    public function test_stored_battlecard_is_sorted_on_read_by_viewer_prices(): void
    {
        [$tender, $item, $seller] = $this->battlecardWithTwoSubstitutes();
        $row = static fn (int $id): array => ['product_id' => $id, 'match_percent' => 90, 'source' => 'relation', 'match_basis' => 'relation', 'substitute_type' => 'tanszy', 'approval_status' => 'zatwierdzony', 'reason' => null];
        // obie kolejności zapisu — odczyt zawsze od najtańszego w cenach widza
        foreach ([[$this->card->id, $this->other->id], [$this->other->id, $this->card->id]] as $order) {
            $item->forceFill(['battlecard_substitutes' => array_map($row, $order)])->save();

            Sanctum::actingAs($seller);
            $masked = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk();
            $this->assertSame(['INNA-200', 'RNITZ-M'], $this->substituteSkus($masked->json('battlecard')));
            $this->assertNoSpecialLeak((string) $masked->getContent());

            Sanctum::actingAs($this->userWithRole('dyrektor'));
            $real = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")->assertOk();
            $this->assertSame(['RNITZ-M', 'INNA-200'], $this->substituteSkus($real->json('battlecard')));
        }
    }

    public function test_battlecard_built_by_seller_is_stored_in_real_order_for_the_director(): void
    {
        [, $item, $seller] = $this->battlecardWithTwoSubstitutes();
        $battlecards = app(BattlecardService::class);

        $card = $battlecards->forItem($item, SupplierSpecialMask::forUser($seller), true, false);

        $this->assertSame(['INNA-200', 'RNITZ-M'], $this->substituteSkus($card), 'handlowiec: od najtańszego w cenach standardowych');
        $this->assertNoSpecialLeak((string) json_encode($card));
        $stored = array_column($item->fresh()->battlecard_substitutes, 'product_id');
        $this->assertSame([(int) $this->card->id, (int) $this->other->id], array_values(array_intersect($stored, [(int) $this->card->id, (int) $this->other->id])), 'zapis z prawdziwych cen');
        $director = $battlecards->forItem($item->fresh(), SupplierSpecialMask::forUser($this->userWithRole('dyrektor')));
        $this->assertSame(['RNITZ-M', 'INNA-200'], $this->substituteSkus($director));
    }

    public function test_battlecard_built_by_director_is_read_in_standard_order_by_the_seller(): void
    {
        [, $item, $seller] = $this->battlecardWithTwoSubstitutes();
        $battlecards = app(BattlecardService::class);

        $card = $battlecards->forItem($item, SupplierSpecialMask::forUser($this->userWithRole('dyrektor')), true, false);

        $this->assertSame(['RNITZ-M', 'INNA-200'], $this->substituteSkus($card));
        $masked = $battlecards->forItem($item->fresh(), SupplierSpecialMask::forUser($seller));
        $this->assertSame(['INNA-200', 'RNITZ-M'], $this->substituteSkus($masked));
        $this->assertNoSpecialLeak((string) json_encode($masked));
    }

    public function test_batch_twin_margins_do_not_query_cards_per_item(): void
    {
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);
        [$tender] = $this->tenderWith($admin, null);
        $cards = [];
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $cards[$i] = $this->plainCard('SERIA-'.$i, 10.0 * $i);
            $item = TenderItem::query()->create([
                'tender_id' => $tender->id, 'line_no' => $i, 'requirement' => 'Rękawice '.$i, 'quantity' => 1, 'status' => 'brak',
            ]);
            $rows[] = ['id' => $item->id, 'main_product_id' => $cards[$i]->id, 'quantity' => 2, 'offer_price' => 20.0 * $i];
        }

        $slotQueries = $this->countSlotQueries(fn () => $this->postJson("/api/tenders/{$tender->id}/items/bulk", ['items' => $rows])->assertOk());
        $this->assertSame(1, $slotQueries, 'marża bliźniacza 6 pozycji: sloty kart jednym zapytaniem, nie na pozycję');
        $this->assertSame(6, TenderItem::query()->where('tender_id', $tender->id)->whereNotNull('margin_percent_standard')->count());

        $import = [['wymaganie', 'sku', 'ilość']];
        for ($i = 1; $i <= 6; $i++) {
            $import[] = ['Rękawice '.$i, 'SERIA-'.$i, 1];
        }
        $slotQueries = $this->countSlotQueries(fn () => $this->post("/api/tenders/{$tender->id}/import", ['file' => $this->xlsx($import), 'replace' => true])->assertOk());
        $this->assertSame(1, $slotQueries, 'import 6 pozycji: sloty kart jednym zapytaniem');
        $this->assertSame(6, TenderItem::query()->where('tender_id', $tender->id)->whereNotNull('margin_percent_standard')->count());
    }

    // ── przegląd wszystkich odczytów przetargu ──────────────────────────────────────────────

    public function test_seller_sees_no_special_price_on_any_tender_read(): void
    {
        [$tender, $seller] = $this->sellerTender();
        $item = $tender->items()->sole();
        ProductSubstitute::query()->create([
            'main_product_id' => $this->plainCard('INNY-GLOWNY', 50.0)->id,
            'substitute_product_id' => $this->card->id,
            'type' => 'tanszy',
            'match_percent' => 80,
            'approval_status' => 'oczekuje',
        ]);
        ProductSubstitute::query()->create([
            'main_product_id' => $this->card->id,
            'substitute_product_id' => $this->plainCard('ZAMIENNIK-X', 260.0)->id,
            'type' => 'premium',
            'match_percent' => 80,
            'approval_status' => 'oczekuje',
        ]);

        $urls = [
            '/api/tenders',
            "/api/tenders/{$tender->id}",
            "/api/tenders/{$tender->id}/coverage",
            "/api/tenders/{$tender->id}/conflicts",
            "/api/tenders/{$tender->id}/activities",
            "/api/tenders/{$tender->id}/comments",
            "/api/tenders/{$tender->id}/documents",
            "/api/tenders/{$tender->id}/conditions",
            "/api/tenders/{$tender->id}/invitations",
            "/api/tenders/{$tender->id}/match/progress",
            "/api/tenders/{$tender->id}/items/{$item->id}/battlecard",
            '/api/dashboard',
        ];
        $real = (string) $this->realMargin();
        foreach ($urls as $url) {
            $response = $this->getJson($url);
            $this->assertTrue($response->isOk(), $url.' → '.$response->getStatusCode());
            $json = (string) $response->getContent();
            $this->assertNoSpecialLeak($json);
            $this->assertStringNotContainsString($real, $json, $url.' zdradza prawdziwą marżę');
        }
        $show = $this->getJson("/api/tenders/{$tender->id}")->json();
        $this->assertSame(self::SPECIAL_STANDARD, $show['tender']['items'][0]['main_product']['purchase_price']);
        $this->assertSame(self::SPECIAL_STANDARD, $show['tender']['items'][0]['main_product']['catalog_price_net']);
        $this->assertEqualsWithDelta((float) self::SPECIAL_STANDARD, (float) $show['tender']['items'][0]['main_product']['purchase_price_pln'], 0.001);
        $this->assertContains(self::SPECIAL_SIZE_MAX_MASKED, array_column($show['tender']['items'][0]['main_product']['active_variants'], 'purchase_price'));
        $this->assertCardUntouched();
    }

    public function test_pricing_service_never_saves_masked_copies(): void
    {
        $seller = $this->userWithRole('handlowiec');
        [$tender, $item] = $this->tenderWith($seller, self::REQUIREMENT, ['main_product_id' => $this->card->id, 'offer_price' => 249.42, 'status' => 'matched']);
        $pricing = app(TenderPricingService::class);

        $pricing->recalculateItemMargin($item->fresh());
        $pricing->recalculateTenderTotals($tender->fresh());
        $this->assertEqualsWithDelta((float) self::SPECIAL_STANDARD, $pricing->mainPurchasePln($item->fresh(), SupplierSpecialMask::hiding()), 0.001);
        $this->assertEqualsWithDelta((float) self::SPECIAL_PRICE, $pricing->mainPurchasePln($item->fresh(), SupplierSpecialMask::revealing()), 0.001);
        $this->assertCardUntouched();
    }

    // ── pomocnicze ─────────────────────────────────────────────────────────────────────────

    private ?Product $other = null;

    /**
     * Pozycja z kartą 300 zł i dwoma zatwierdzonymi zamiennikami: kartą specjalną (173,19 / standard 211,37) i kartą
     * 200 zł — kolejność od najtańszego zależy od tego, czyje ceny się liczy.
     *
     * @return array{0: Tender, 1: TenderItem, 2: User}
     */
    private function battlecardWithTwoSubstitutes(): array
    {
        $this->other = $this->plainCard('INNA-200', 200.0);
        $main = $this->plainCard('GLOWNA-300', 300.0);
        foreach ([$this->card, $this->other] as $substitute) {
            ProductSubstitute::query()->create([
                'main_product_id' => $main->id,
                'substitute_product_id' => $substitute->id,
                'type' => 'tanszy',
                'match_percent' => 90,
                'approval_status' => 'zatwierdzony',
            ]);
        }
        $seller = $this->userWithRole('handlowiec');
        [$tender, $item] = $this->tenderWith($seller, 'Rękawice ochronne', ['main_product_id' => $main->id, 'offer_price' => 354, 'status' => 'matched']);

        return [$tender, $item, $seller];
    }

    /**
     * Kolejność dwóch badanych zamienników (katalog może dołożyć inne karty).
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    private function substituteSkus(array $card): array
    {
        return array_values(array_intersect(array_column($card['substitutes'], 'sku'), ['INNA-200', 'RNITZ-M']));
    }

    /** Liczba zapytań o sloty cen kart (product_source_prices) w czasie $call. */
    private function countSlotQueries(callable $call): int
    {
        $count = 0;
        DB::listen(static function (QueryExecuted $query) use (&$count): void {
            if (str_contains($query->sql, 'product_source_prices')) {
                $count++;
            }
        });
        $call();

        return $count;
    }

    /** @return list<array{0: User, 1: string}> [użytkownik, cena zakupu, od której liczy ofertę] */
    private function viewers(): array
    {
        return [
            [$this->userWithRole('handlowiec'), self::SPECIAL_STANDARD],
            [$this->userWithRole('admin'), self::SPECIAL_PRICE],
        ];
    }

    private function assertOffer(string $purchase, mixed $offer, ?string $who): void
    {
        $this->assertNotNull($offer, (string) $who);
        $this->assertEqualsWithDelta(
            OfferPricing::fromPurchase((float) $purchase, self::MARKUP),
            (float) $offer,
            0.001,
            'oferta '.$who,
        );
    }

    /** Cena karty i slotu w bazie zostaje prawdziwa po każdym zapisie użytkownika bez uprawnienia. */
    private function assertCardUntouched(): void
    {
        $card = $this->card->fresh();
        $this->assertSame(self::SPECIAL_PRICE, $card->purchase_price);
        $this->assertSame(self::SPECIAL_PRICE, $card->catalog_price_net);
        $this->assertSame([self::SPECIAL_PRICE, self::SPECIAL_SIZE_MAX], $card->variants()->orderBy('sort_order')->pluck('purchase_price')->all());
    }

    private function realMargin(): float
    {
        $offer = OfferPricing::fromPurchase((float) self::SPECIAL_STANDARD, self::MARKUP);

        return round(($offer - (float) self::SPECIAL_PRICE) / $offer * 100, 2);
    }

    private function standardMargin(): float
    {
        $offer = OfferPricing::fromPurchase((float) self::SPECIAL_STANDARD, self::MARKUP);

        return round(($offer - (float) self::SPECIAL_STANDARD) / $offer * 100, 2);
    }

    /**
     * Przetarg handlowca z pozycją, której kartę wybrał on sam (oferta od ceny standardowej).
     *
     * @return array{0: Tender, 1: User}
     */
    private function sellerTender(): array
    {
        $seller = $this->userWithRole('handlowiec');
        Sanctum::actingAs($seller);
        [$tender, $item] = $this->tenderWith($seller, self::REQUIREMENT);
        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", ['main_product_id' => $this->card->id])->assertOk();

        return [$tender->fresh(), $seller];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: Tender, 1: ?TenderItem}
     */
    private function tenderWith(User $owner, ?string $requirement, array $item = []): array
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/SPEC/'.uniqid(),
            'title' => 'Cena specjalna',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => self::MARKUP,
            'last_activity_at' => now(),
        ]);
        if ($requirement === null) {
            return [$tender, null];
        }
        $row = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => $requirement,
            'quantity' => 10,
            'status' => 'brak',
            ...$item,
        ]);

        return [$tender, $row];
    }

    private function plainCard(string $sku, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice ochronne '.$sku,
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice ochronne robocze '.$sku.'.',
            'catalog_price_net' => $purchase,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /** Model nie odpowiada — kod SKU w wymaganiu (RNITZ-M) rozstrzyga bez modelu (TenderMatchModelStateTest). */
    private function modelDown(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_fill(0, count($sets), []));
        $llm->shouldReceive('chatJson')->andThrow(new RuntimeException('model niedostępny'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /** @param  list<list<mixed>>  $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'spec').'.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'pozycje.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Wszystkie komórki wszystkich arkuszy jako tekst (wartości liczbowe w zapisie z kropką). */
    private function spreadsheetText(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $binary);
        $book = IOFactory::load($path);
        $out = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, false, false, false) as $row) {
                foreach ($row as $cell) {
                    if ($cell === null) {
                        continue;
                    }
                    $out[] = is_float($cell) ? number_format($cell, 2, '.', '') : (string) $cell;
                }
            }
        }
        @unlink($path);

        return implode(' | ', $out);
    }
}
