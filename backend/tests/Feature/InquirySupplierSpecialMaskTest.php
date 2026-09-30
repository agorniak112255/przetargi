<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductInquirySearch;
use App\Support\OfferPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Zapytania klientów a cena specjalna konta B2B (prices.supplier_special.view, decyzja właściciela 30.09.2026):
 * handlowiec bez uprawnienia dostaje kandydatów, ceny oferty i list liczone od ceny standardowej (211,37 zł zamiast
 * 173,19 zł). Autor zapytania to ten, kto ofertę przygotowuje — analiza w tle i inquiries:rematch biorą jego widok.
 */
final class InquirySupplierSpecialMaskTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    private const QUERY = 'rękawice UVEX 60148';

    private Product $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSupplierSpecial();
        $this->card = $this->supplierSpecialCard()['product'];
    }

    public function test_analysis_by_salesperson_stores_standard_price_and_letter_from_it(): void
    {
        $handlowiec = $this->userWithRole('handlowiec');
        $res = $this->analyzeAs($handlowiec, [$this->row()]);

        $this->assertNoSpecialLeak((string) $res->getContent());
        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        $stored = $inquiry->analysis['matches'][0]['products'][0];
        $this->assertSame($this->card->id, $stored['id']);
        $this->assertSame(211.37, $stored['catalog_pln']);
        $this->assertSame('211.37', $stored['catalog_price_net']);
        $this->assertSame($this->standardOffer(), $stored['offer_pln']);
        // domyślnie cena oferty (zakup + marża) — w liście od ceny standardowej
        $this->assertStringContainsString($this->pln($this->standardOffer()), (string) $inquiry->reply_body);
        $this->assertNoSpecialLeak((string) json_encode($inquiry->only(['analysis', 'answers', 'reply_body', 'reply_html'])));
        $res->assertJsonPath('items.0.candidates.0.offer_pln', $this->standardOffer())
            ->assertJsonPath('items.0.candidates.0.catalog_pln', 211.37)
            ->assertJsonPath('items.0.candidates.0.order_quantity.size_price_max', self::SPECIAL_SIZE_MAX_MASKED);
    }

    public function test_analysis_by_director_stores_real_values(): void
    {
        $dyrektor = $this->userWithRole('dyrektor');
        $res = $this->analyzeAs($dyrektor, [$this->row()]);

        $inquiry = ClientInquiry::query()->findOrFail($res->json('id'));
        $stored = $inquiry->analysis['matches'][0]['products'][0];
        $this->assertSame(173.19, $stored['catalog_pln']);
        $this->assertSame($this->realOffer(), $stored['offer_pln']);
        $this->assertStringContainsString($this->pln($this->realOffer()), (string) $inquiry->reply_body);
        $res->assertJsonPath('items.0.candidates.0.offer_pln', $this->realOffer())
            ->assertJsonPath('items.0.candidates.0.catalog_pln', 173.19)
            ->assertJsonPath('items.0.candidates.0.order_quantity.size_price_max', self::SPECIAL_SIZE_MAX);
    }

    public function test_pick_product_by_salesperson_is_masked(): void
    {
        $handlowiec = $this->userWithRole('handlowiec');
        $id = (int) $this->analyzeAs($handlowiec, [])->json('id');

        $res = $this->postJson("/api/inquiries/{$id}/pick-product", ['item_id' => 'item_1', 'product_id' => $this->card->id])
            ->assertOk()
            ->assertJsonPath('items.0.chosen', 'p:'.$this->card->id)
            ->assertJsonPath('items.0.candidates.0.offer_pln', $this->standardOffer());

        $this->assertNoSpecialLeak((string) $res->getContent());
        $inquiry = ClientInquiry::query()->findOrFail($id);
        $manual = $inquiry->analysis['manual_candidates']['item_1'][0];
        $this->assertSame(211.37, $manual['catalog_pln']);
        $this->assertSame($this->standardOffer(), $manual['offer_pln']);
        $this->assertStringContainsString($this->pln($this->standardOffer()), (string) $inquiry->reply_body);
    }

    public function test_pick_product_by_director_keeps_real_price(): void
    {
        $dyrektor = $this->userWithRole('dyrektor');
        $id = (int) $this->analyzeAs($dyrektor, [])->json('id');

        $this->postJson("/api/inquiries/{$id}/pick-product", ['item_id' => 'item_1', 'product_id' => $this->card->id])
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.offer_pln', $this->realOffer());
        $this->assertSame(173.19, ClientInquiry::query()->findOrFail($id)->analysis['manual_candidates']['item_1'][0]['catalog_pln']);
    }

    /** Kierownik z inquiries.view_others otwiera zapytanie dyrektora: zapisane ceny specjalne przeliczone w widoku. */
    public function test_manager_viewing_directors_inquiry_gets_remasked_rows(): void
    {
        $dyrektor = $this->userWithRole('dyrektor');
        $id = (int) $this->analyzeAs($dyrektor, [$this->row()])->json('id');
        $before = ClientInquiry::query()->findOrFail($id)->only(['analysis', 'answers', 'reply_body', 'reply_html', 'updated_at']);

        Sanctum::actingAs($this->userWithRole('kierownik'));
        $res = $this->getJson("/api/inquiries/{$id}")
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.offer_pln', $this->standardOffer())
            ->assertJsonPath('items.0.candidates.0.catalog_pln', 211.37)
            ->assertJsonPath('items.0.candidates.0.order_quantity.size_price_max', self::SPECIAL_SIZE_MAX_MASKED);
        // list dyrektora (reply_body/reply_html) idzie, jaki jest — to treść autora dla klienta
        $this->assertNoSpecialLeak((string) json_encode($res->json('items')));
        // podgląd niczego nie zapisuje
        $this->assertEquals($before, ClientInquiry::query()->findOrFail($id)->only(['analysis', 'answers', 'reply_body', 'reply_html', 'updated_at']));

        Sanctum::actingAs($dyrektor);
        $this->getJson("/api/inquiries/{$id}")
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.offer_pln', $this->realOffer())
            ->assertJsonPath('items.0.candidates.0.catalog_pln', 173.19)
            ->assertJsonPath('items.0.candidates.0.order_quantity.size_price_max', self::SPECIAL_SIZE_MAX);
    }

    /**
     * Karta, która dziś nie ma już ceny specjalnej (konto podniosło cenę), a w zapisanej analizie dyrektora stoi
     * dawna cena specjalna — jak historia cen konta z oceną (D1): widz bez uprawnienia dostaje bieżącą cenę karty.
     */
    public function test_stored_old_special_price_is_replaced_by_current_price_for_masked_viewer(): void
    {
        $dyrektor = $this->userWithRole('dyrektor');
        $id = (int) $this->analyzeAs($dyrektor, [$this->row()])->json('id');
        $this->card->forceFill(['catalog_price_net' => '215.00', 'purchase_price' => '215.00'])->save();
        ProductSourcePrice::query()->where('product_id', $this->card->id)->update(['catalog_price_net' => '215.00', 'purchase_price' => '215.00', 'size_price_max' => '240.00']);

        Sanctum::actingAs($this->userWithRole('kierownik'));
        $res = $this->getJson("/api/inquiries/{$id}")
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.catalog_pln', 215)
            ->assertJsonPath('items.0.candidates.0.offer_pln', OfferPricing::fromPurchase(215.0));
        $this->assertNoSpecialLeak((string) json_encode($res->json('items')));
    }

    /**
     * Zapytanie handlowca zapisane z ceną specjalną (sprzed ukrywania albo autor stracił uprawnienie): po otwarciu
     * przez autora list przelicza się od ceny standardowej, a widok nie pokazuje ceny specjalnej.
     */
    public function test_legacy_inquiry_of_salesperson_is_remasked_and_letter_refreshed_on_open(): void
    {
        $dyrektor = $this->userWithRole('dyrektor');
        $id = (int) $this->analyzeAs($dyrektor, [$this->row()])->json('id');
        $handlowiec = $this->userWithRole('handlowiec');
        ClientInquiry::query()->whereKey($id)->update(['user_id' => $handlowiec->id]);
        $this->assertStringContainsString($this->pln($this->realOffer()), (string) ClientInquiry::query()->findOrFail($id)->reply_body);

        Sanctum::actingAs($handlowiec);
        $res = $this->getJson("/api/inquiries/{$id}")->assertOk();

        $this->assertNoSpecialLeak((string) $res->getContent());
        $this->assertStringContainsString($this->pln($this->standardOffer()), (string) $res->json('reply_body'));
    }

    public function test_composed_letter_of_masked_author_has_no_special_price(): void
    {
        $handlowiec = $this->userWithRole('handlowiec');
        $id = (int) $this->analyzeAs($handlowiec, [$this->row()])->json('id');

        foreach (['catalog' => '211,37 zł', 'catalog_margin' => $this->pln($this->standardOffer())] as $mode => $expected) {
            $res = $this->postJson("/api/inquiries/{$id}/compose", ['answers' => ['price' => ['option_id' => $mode, 'custom' => '18']]])
                ->assertOk();
            $this->assertNoSpecialLeak((string) $res->getContent());
            $this->assertStringContainsString($expected, (string) $res->json('reply_body'));
            $this->assertStringContainsString($expected, (string) $res->json('reply_html'));
        }

        // inna marża: cena oferty przeliczona od ceny standardowej
        $res = $this->postJson("/api/inquiries/{$id}/compose", ['answers' => ['price' => ['option_id' => 'catalog_margin', 'custom' => '30']]])
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.offer_pln', OfferPricing::fromPurchase(211.37, 30.0));
        $this->assertNoSpecialLeak((string) $res->getContent());
    }

    /** Polecenie CLI nie ma zalogowanego — liczy widokiem autora, nawet gdy w procesie ktoś inny jest zalogowany. */
    public function test_rematch_cli_uses_authors_mask(): void
    {
        $handlowiec = $this->userWithRole('handlowiec');
        $handlowcaId = (int) $this->analyzeAs($handlowiec, [])->json('id');
        $dyrektor = $this->userWithRole('dyrektor');
        $dyrektoraId = (int) $this->analyzeAs($dyrektor, [])->json('id');

        // zalogowany dyrektor nie może odsłonić ceny w zapytaniu handlowca
        Sanctum::actingAs($dyrektor);
        $this->searchReturns([$this->row()]);
        $this->artisan('inquiries:rematch', ['ids' => [$handlowcaId], '--apply' => true])->assertSuccessful();
        $masked = ClientInquiry::query()->findOrFail($handlowcaId);
        $this->assertSame(211.37, $masked->analysis['matches'][0]['products'][0]['catalog_pln']);
        $this->assertSame($this->standardOffer(), $masked->analysis['matches'][0]['products'][0]['offer_pln']);
        $this->assertStringContainsString($this->pln($this->standardOffer()), (string) $masked->reply_body);
        $this->assertNoSpecialLeak((string) json_encode($masked->only(['analysis', 'reply_body', 'reply_html'])));

        Sanctum::actingAs($handlowiec);
        $this->searchReturns([$this->row()]);
        $this->artisan('inquiries:rematch', ['ids' => [$dyrektoraId], '--apply' => true])->assertSuccessful();
        $real = ClientInquiry::query()->findOrFail($dyrektoraId);
        $this->assertSame(173.19, $real->analysis['matches'][0]['products'][0]['catalog_pln']);
        $this->assertStringContainsString($this->pln($this->realOffer()), (string) $real->reply_body);
    }

    public function test_get_endpoints_do_not_leak_special_price_to_salesperson(): void
    {
        $handlowiec = $this->userWithRole('handlowiec');
        $id = (int) $this->analyzeAs($handlowiec, [$this->row()], '<msg-uvex@example.pl>')->json('id');
        $this->postJson("/api/inquiries/{$id}/queue-reply", ['queued' => true])->assertOk();
        // stary list bez tabeli HTML: kolejka Thunderbirda odtwarza ją z odpowiedzi
        ClientInquiry::query()->whereKey($id)->update(['reply_html' => null]);

        $responses = [
            $this->getJson('/api/inquiries'),
            $this->getJson('/api/inquiries?scope=mine&status=all'),
            $this->getJson("/api/inquiries/{$id}"),
            $this->getJson('/api/inquiries/preferences'),
            $this->getJson('/api/inquiries/queued'),
            $this->getJson('/api/inquiries/queued?with_offers=1'),
            $this->getJson('/api/inquiries/message-ids'),
            $this->postJson('/api/inquiries/lookup', ['message_ids' => ['<msg-uvex@example.pl>']]),
        ];
        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertNoSpecialLeak((string) $response->getContent());
        }
        $this->assertStringContainsString($this->pln($this->standardOffer()), (string) $responses[4]->json('0.reply_html'));
    }

    /** Usługa żyje długo (worker kolejki): widok wraca po wyjściu z zakresu, także po wyjątku i przy zagnieżdżeniu. */
    public function test_price_mask_scope_restores_previous_mask(): void
    {
        $service = app(ClientInquiryService::class);
        $row = $this->row();

        // bez zakresu — ukrywa
        $this->assertSame(211.37, $service->safeProduct($row)['catalog_pln']);

        [$inner, $outer] = $service->withPriceMask(SupplierSpecialMask::revealing(), fn (): array => [
            $service->withPriceMask(SupplierSpecialMask::hiding(), fn (): ?array => $service->safeProduct($row)),
            $service->safeProduct($row),
        ]);
        $this->assertSame(211.37, $inner['catalog_pln']);
        $this->assertSame(173.19, $outer['catalog_pln']);

        try {
            $service->withPriceMask(SupplierSpecialMask::revealing(), function (): never {
                throw new RuntimeException('przerwane');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(211.37, $service->safeProduct($row)['catalog_pln']);
    }

    /**
     * @param  list<array<string, mixed>>  $products  wiersze wyszukiwarki; pusto = model nie odpowiedział
     */
    private function analyzeAs(User $user, array $products, ?string $messageId = null): TestResponse
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice UVEX',
                'questions' => [],
                'product_queries' => [self::QUERY],
                'cards' => [],
            ]);
        });
        $this->searchReturns($products, $products === [] ? 'unavailable' : null);
        Sanctum::actingAs($user);

        return $this->postJson('/api/inquiries', array_filter([
            'body' => 'Dzień dobry, proszę o ofertę na rękawice UVEX 60148.',
            'tone' => 'handlowy',
            'source_message_id' => $messageId,
            // ten sam mail u drugiego autora to świadoma kopia
            'force' => true,
        ]))->assertCreated();
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function searchReturns(array $products, ?string $modelState = null): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock) use ($products, $modelState): void {
            $group = ['query' => self::QUERY, 'products' => $products];
            if ($modelState !== null) {
                $group['model_state'] = $modelState;
            }
            $mock->shouldReceive('findMany')->once()->andReturn([$group]);
        });
    }

    /**
     * Wiersz wyszukiwarki tak, jak go oddaje ProductAiSearchService (karta toArray() z ceną w PLN).
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => $this->card->id,
            'sku' => $this->card->sku,
            'name' => $this->card->name,
            'manufacturer' => $this->card->manufacturer,
            'catalog_price_net' => self::SPECIAL_PRICE,
            'purchase_price' => self::SPECIAL_PRICE,
            'discount_percent' => '0.00',
            'currency' => 'PLN',
            'price_pln' => (float) self::SPECIAL_PRICE,
            'purchase_price_pln' => (float) self::SPECIAL_PRICE,
            'stock' => 1,
            'ai_match_percent' => 92,
        ];
    }

    private function standardOffer(): float
    {
        return (float) OfferPricing::fromPurchase((float) self::SPECIAL_STANDARD);
    }

    private function realOffer(): float
    {
        return (float) OfferPricing::fromPurchase((float) self::SPECIAL_PRICE);
    }

    private function pln(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' zł';
    }
}
