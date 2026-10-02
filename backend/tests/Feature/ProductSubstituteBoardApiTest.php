<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSubstitute;
use App\Models\User;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\Substitutes\SubstituteBoardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Ekran zamienników (moduł Zamienniki v2): /substitutes/summary, /substitutes/board, rozszerzone byMain
 * i decyzje człowieka nad propozycjami automatu (notatka, odrzucenie zamiast kasowania, przejęcie wiersza).
 */
final class ProductSubstituteBoardApiTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // role z katalogu i kurs NBP bez sieci (EUR 4,00)
        $this->setUpSupplierSpecial();
    }

    public function test_board_groups_mains_with_pending_first_and_orders_rows(): void
    {
        $admin = $this->actingAsRole('admin');
        // Beta: tylko zatwierdzony — po grupach z oczekującymi mimo wcześniejszej litery w nazwie
        $beta = $this->product('Rękawice robocze A-beta', 'Beta', 20);
        $betaSub = $this->product('Rękawice robocze B-beta', 'Gamma', 18);
        $this->pair($beta, $betaSub, ['approval_status' => 'zatwierdzony', 'approved_by' => $admin->id]);

        $main = $this->product('Rękawice robocze powlekane nitrylem', 'Alfa', 20, [
            'description' => str_repeat('Rękawice robocze powlekane nitrylem, ściągacz, EN 388. ', 3),
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        ProductImage::query()->create(['product_id' => $main->id, 'path' => 'products/a.jpg', 'is_primary' => false, 'sort_order' => 0, 'checksum' => 'a']);
        $primary = ProductImage::query()->create(['product_id' => $main->id, 'path' => 'products/b.jpg', 'is_primary' => true, 'sort_order' => 1, 'checksum' => 'b']);

        $rejected = $this->pair($main, $this->product('Rękawice robocze odrzucone', 'X', 5), ['approval_status' => 'odrzucony', 'source' => 'automat', 'decision_note' => 'Inna powłoka']);
        $premium = $this->pair($main, $this->product('Rękawice robocze premium', 'X', 30), ['type' => 'premium', 'source' => 'automat', 'evidence' => $this->evidence()]);
        $preferredExpensive = $this->pair($main, $this->product('Rękawice robocze drogie', 'X', 25), ['source' => 'automat']);
        $preferredCheap = $this->pair($main, $this->product('Rękawice robocze tanie', 'X', 15), ['source' => 'automat', 'evidence' => $this->evidence(stale: true)]);
        $approved = $this->pair($main, $this->product('Rękawice robocze zatwierdzone', 'X', 22), [
            'type' => 'premium', 'approval_status' => 'zatwierdzony', 'approved_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/substitutes/board')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('data.0.main.id', $main->id)
            ->assertJsonPath('data.1.main.id', $beta->id);

        $group = $response->json('data.0');
        $this->assertSame(
            [$approved->id, $preferredCheap->id, $preferredExpensive->id, $premium->id, $rejected->id],
            array_column($group['substitutes'], 'id'),
            'zatwierdzony, oczekuje (preferowany od najtańszego, potem premium), odrzucony',
        );
        $this->assertSame([
            'id' => $main->id,
            'sku' => $main->sku,
            'name' => 'Rękawice robocze powlekane nitrylem',
            'manufacturer' => 'Alfa',
            'family' => 'gloves',
            'family_label' => 'Rękawice',
            'thumb_url' => $primary->thumbUrl(),
            'price_pln' => 20,
            'currency' => 'PLN',
            'has_description' => true,
        ], $group['main']);
        // pierwszy wiersz z dowodami (tańszy preferowany), bez rodzaju wyrobu, najwyżej 4
        $this->assertSame(['EN 388:2016 4131X', 'EN 407 X1XXXX', 'Kat. II', 'Mankiet ściągacz'], $group['chips']);

        $cheap = $group['substitutes'][1];
        $this->assertSame('automat', $cheap['source']);
        $this->assertTrue($cheap['stale']);
        $this->assertSame(['params' => 6, 'equal' => 4, 'higher' => 1], $cheap['summary']);
        $this->assertSame('2026-10-02T20:00:00+00:00', $cheap['generated_at']);
        $this->assertSame(['main_pln' => 20, 'sub_pln' => 15, 'diff_percent' => -25, 'comparable' => true, 'note' => null], $cheap['price']);
        $this->assertSame('Rękawice robocze tanie', $cheap['product']['name']);
        $this->assertFalse($cheap['product']['has_description']);

        $this->assertSame(['id' => $admin->id, 'name' => $admin->name], $group['substitutes'][0]['approver']);
        $this->assertSame('reczny', $group['substitutes'][0]['source']);
        $this->assertSame(['params' => 0, 'equal' => 0, 'higher' => 0], $group['substitutes'][0]['summary']);
        $this->assertFalse($group['substitutes'][0]['stale']);
        $this->assertSame('Inna powłoka', $group['substitutes'][4]['decision_note']);
        $this->assertSame([], $response->json('data.1.chips'));
    }

    public function test_board_filters_keep_only_matching_rows_in_group(): void
    {
        $this->actingAsRole('handlowiec');
        $gloves = $this->product('Rękawice robocze główne', '3M', 20);
        $glovesAuto = $this->pair($gloves, $this->product('Rękawice robocze automat', 'X', 18, ['sku' => 'SZUKANY-1']), ['source' => 'automat']);
        $glovesManual = $this->pair($gloves, $this->product('Rękawice robocze ręczne', 'X', 19), ['type' => 'tanszy', 'approval_status' => 'zatwierdzony']);
        $shoes = $this->product('Półbuty robocze S3 SRC', 'Uvex', 200);
        $shoesRow = $this->pair($shoes, $this->product('Półbuty robocze S3 inne', 'Y', 180), ['type' => 'premium']);
        $other = $this->product('Taśma ostrzegawcza', 'Uvex', 10);
        $otherRow = $this->pair($other, $this->product('Taśma ostrzegawcza żółta', 'Y', 9));

        $ids = fn (string $query): array => collect($this->getJson('/api/substitutes/board'.$query)->assertOk()->json('data'))
            ->map(static fn (array $group): array => [$group['main']['id'], array_column($group['substitutes'], 'id')])
            ->all();

        $this->assertSame([[$gloves->id, [$glovesAuto->id]]], $ids('?source=automat'));
        $this->assertSame([[$gloves->id, [$glovesManual->id]]], $ids('?status=zatwierdzony'));
        $this->assertSame([[$gloves->id, [$glovesManual->id]]], $ids('?type=tanszy'));
        $this->assertSame([[$shoes->id, [$shoesRow->id]]], $ids('?family=footwear'));
        $this->assertSame([[$other->id, [$otherRow->id]]], $ids('?family=inne'));
        $this->assertSame([[$shoes->id, [$shoesRow->id]], [$other->id, [$otherRow->id]]], $ids('?manufacturer=Uvex'));
        // q po kodzie zamiennika: w grupie tylko pasujący wiersz; po nazwie głównej — cała grupa
        $this->assertSame([[$gloves->id, [$glovesAuto->id]]], $ids('?q=SZUKANY'));
        $this->assertSame([[$gloves->id, [$glovesManual->id, $glovesAuto->id]]], $ids('?q=główne'));
        $this->assertSame([], $ids('?q=nic-takiego'));
    }

    public function test_board_pages_by_main_cards(): void
    {
        $this->actingAsRole('handlowiec');
        for ($i = 1; $i <= 23; $i++) {
            $main = $this->product(sprintf('Rękawice robocze %02d', $i), 'Alfa', 10);
            // dwa wiersze na kartę główną — strona liczy karty główne, nie wiersze
            $this->pair($main, $this->product(sprintf('Rękawice zamiennik %02d-a', $i), 'X', 9));
            $this->pair($main, $this->product(sprintf('Rękawice zamiennik %02d-b', $i), 'X', 8));
        }

        $first = $this->getJson('/api/substitutes/board')->assertOk()
            ->assertJsonPath('total', 23)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(20, 'data');
        $this->assertSame('Rękawice robocze 01', $first->json('data.0.main.name'));
        $this->assertCount(2, $first->json('data.0.substitutes'));

        $second = $this->getJson('/api/substitutes/board?page=2')->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(3, 'data');
        $this->assertSame('Rękawice robocze 21', $second->json('data.0.main.name'));
    }

    public function test_summary_counts_rows_mains_families_and_manufacturers(): void
    {
        $this->actingAsRole('handlowiec');
        $gloves = $this->product('Rękawice robocze główne', '3M', 20);
        $gloves2 = $this->product('Rękawice robocze drugie', 'Ansell', 20);
        $shoes = $this->product('Półbuty robocze S3', '3M', 200);
        $tape = $this->product('Taśma ostrzegawcza', '', 10);
        $this->pair($gloves, $this->product('Rękawice a', 'X', 1), ['source' => 'automat', 'evidence' => $this->evidence(stale: true), 'approval_status' => 'zatwierdzony']);
        $this->pair($gloves, $this->product('Rękawice b', 'X', 1), ['source' => 'automat', 'evidence' => $this->evidence()]);
        $this->pair($gloves2, $this->product('Rękawice c', 'X', 1), ['source' => 'automat', 'approval_status' => 'odrzucony']);
        $this->pair($shoes, $this->product('Półbuty d', 'X', 1));
        $this->pair($tape, $this->product('Taśma e', 'X', 1));

        $this->getJson('/api/substitutes/summary')->assertOk()
            ->assertExactJson([
                'totals' => ['mains' => 4, 'rows' => 5, 'pending' => 3, 'approved' => 1, 'rejected' => 1, 'auto' => 3, 'manual' => 2, 'stale' => 1],
                'families' => [
                    ['key' => 'gloves', 'label' => 'Rękawice', 'mains' => 2],
                    ['key' => 'footwear', 'label' => 'Obuwie', 'mains' => 1],
                    ['key' => null, 'label' => 'Inne', 'mains' => 1],
                ],
                'manufacturers' => [
                    ['name' => '3M', 'mains' => 2],
                    ['name' => 'Ansell', 'mains' => 1],
                ],
            ]);
    }

    public function test_by_main_keeps_old_fields_and_adds_cards_price_and_summary(): void
    {
        $this->actingAsRole('handlowiec');
        $main = $this->product('Rękawice robocze główne', '3M', 20);
        $expensive = $this->pair($main, $this->product('Rękawice robocze drogie', 'X', 30), ['match_percent' => 95]);
        $cheap = $this->pair($main, $this->product('Rękawice robocze tanie', 'X', 10), ['match_percent' => 60, 'source' => 'automat', 'evidence' => $this->evidence()]);

        $response = $this->getJson('/api/products/'.$main->id.'/substitutes')->assertOk()
            ->assertJsonPath('main_product.id', $main->id)
            ->assertJsonPath('main_card.id', $main->id)
            ->assertJsonPath('main_card.family_label', 'Rękawice')
            ->assertJsonPath('main_card.price_pln', 20)
            // kolejność jak na ekranie zamienników (tańszy preferowany pierwszy), nie po match_percent
            ->assertJsonPath('substitutes.0.id', $cheap->id)
            ->assertJsonPath('substitutes.1.id', $expensive->id)
            ->assertJsonPath('substitutes.0.substitute_product.sku', $cheap->substituteProduct->sku)
            ->assertJsonPath('substitutes.0.match_percent', 60)
            ->assertJsonPath('substitutes.0.source', 'automat')
            ->assertJsonPath('substitutes.0.evidence.family', 'gloves')
            ->assertJsonPath('substitutes.0.decision_note', null)
            ->assertJsonPath('substitutes.0.card.id', $cheap->substitute_product_id)
            ->assertJsonPath('substitutes.0.price.diff_percent', -50)
            ->assertJsonPath('substitutes.0.summary', ['params' => 6, 'equal' => 4, 'higher' => 1])
            ->assertJsonPath('substitutes.1.summary', ['params' => 0, 'equal' => 0, 'higher' => 0])
            ->assertJsonPath('substitutes.1.source', 'reczny');
        $this->assertArrayHasKey('generated_at', $response->json('substitutes.0'));
    }

    public function test_approve_saves_note_and_pending_clears_it(): void
    {
        $user = $this->actingAsRole('kierownik');
        $row = $this->pair($this->product('Rękawice główne', 'A', 10), $this->product('Rękawice inne', 'B', 9), ['source' => 'automat']);

        $this->patchJson('/api/substitutes/'.$row->id.'/approve', ['approval_status' => 'odrzucony', 'note' => '  Inna norma EN 407  '])
            ->assertOk()
            ->assertJsonPath('approval_status', 'odrzucony')
            ->assertJsonPath('approved_by', $user->id)
            ->assertJsonPath('decision_note', 'Inna norma EN 407');

        $this->patchJson('/api/substitutes/'.$row->id.'/approve', ['approval_status' => 'oczekuje', 'note' => 'nie zapisze się'])
            ->assertOk()
            ->assertJsonPath('approved_by', null)
            ->assertJsonPath('decision_note', null);

        $this->patchJson('/api/substitutes/'.$row->id.'/approve', ['approval_status' => 'zatwierdzony', 'note' => str_repeat('x', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_delete_of_automat_proposal_rejects_it_and_manual_row_is_deleted(): void
    {
        $user = $this->actingAsRole('handlowiec');
        $main = $this->product('Rękawice główne', 'A', 10);
        $auto = $this->pair($main, $this->product('Rękawice automat', 'B', 9), ['source' => 'automat', 'evidence' => $this->evidence()]);
        $manual = $this->pair($main, $this->product('Rękawice ręczne', 'B', 9));

        $this->deleteJson('/api/substitutes/'.$auto->id)->assertOk()->assertExactJson(['ok' => true, 'rejected' => true]);
        $auto->refresh();
        $this->assertSame('odrzucony', $auto->approval_status);
        $this->assertSame($user->id, (int) $auto->approved_by);
        $this->assertNotNull($auto->evidence);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'substitute.rejected']);

        $this->deleteJson('/api/substitutes/'.$manual->id)->assertOk()->assertExactJson(['ok' => true]);
        $this->assertDatabaseMissing('product_substitutes', ['id' => $manual->id]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'substitute.deleted']);
    }

    public function test_content_change_hands_automat_row_over_to_human(): void
    {
        $this->actingAsRole('kierownik');
        $row = $this->pair($this->product('Rękawice główne', 'A', 10), $this->product('Rękawice inne', 'B', 9), [
            'source' => 'automat', 'evidence' => $this->evidence(), 'approval_status' => 'zatwierdzony',
        ]);

        // bez zmiany treści wiersz zostaje automatu
        $this->patchJson('/api/substitutes/'.$row->id, ['reason' => $row->reason])->assertOk()
            ->assertJsonPath('source', 'automat')
            ->assertJsonPath('approval_status', 'zatwierdzony');

        $this->patchJson('/api/substitutes/'.$row->id, ['reason' => 'Poprawione przez handlowca'])->assertOk()
            ->assertJsonPath('source', 'reczny')
            ->assertJsonPath('approval_status', 'oczekuje')
            ->assertJsonPath('evidence.family', 'gloves');
    }

    public function test_board_and_by_main_show_standard_price_to_user_without_permission(): void
    {
        $card = $this->supplierSpecialCard()['product'];
        $main = $this->product('Rękawice robocze główne', 'Ansell', 250);
        $row = $this->pair($main, $card, ['source' => 'automat', 'evidence' => $this->evidence()]);

        $this->actingAsRole('admin');
        $this->getJson('/api/substitutes/board')->assertOk()
            ->assertJsonPath('data.0.substitutes.0.product.price_pln', 173.19);

        foreach (['handlowiec', 'kierownik'] as $role) {
            $this->actingAsRole($role);
            $board = $this->getJson('/api/substitutes/board')->assertOk()
                ->assertJsonPath('data.0.substitutes.0.id', $row->id)
                ->assertJsonPath('data.0.substitutes.0.product.price_pln', 211.37)
                ->assertJsonPath('data.0.substitutes.0.price.sub_pln', 211.37);
            $this->assertNoSpecialLeak((string) $board->getContent());

            $byMain = $this->getJson('/api/products/'.$main->id.'/substitutes')->assertOk()
                ->assertJsonPath('substitutes.0.card.price_pln', 211.37)
                ->assertJsonPath('substitutes.0.substitute_product.catalog_price_net', self::SPECIAL_STANDARD);
            $this->assertNoSpecialLeak((string) $byMain->getContent());

            $mainView = $this->getJson('/api/products/'.$card->id.'/substitutes')->assertOk()
                ->assertJsonPath('main_card.price_pln', 211.37);
            $this->assertNoSpecialLeak((string) $mainView->getContent());
        }
    }

    public function test_price_is_compared_only_for_the_same_sales_unit(): void
    {
        $presenter = new SubstituteBoardPresenter(SupplierSpecialMask::revealing());
        $cases = [
            // [nazwa głównej, cena, nazwa zamiennika, cena, porównywalne]
            'rękawice wielorazowe' => ['Rękawice robocze powlekane nitrylem', 10, 'Rękawice robocze powlekane lateksem', 12, true],
            'rękawice jednorazowe' => ['Rękawice nitrylowe jednorazowe', 30, 'Rękawice nitrylowe jednorazowe inne', 28, false],
            'różne opakowania' => ['Rękawice powlekane op. 12 par', 60, 'Rękawice powlekane', 6, false],
            'te same opakowania' => ['Rękawice powlekane op. 12 par', 60, 'Rękawice powlekane 12 par', 55, true],
            'opakowanie bez spacji po „op.”' => ['Rękawice powlekane op.12 par', 60, 'Rękawice powlekane 12 par', 58, true],
            'różne opakowania bez spacji' => ['Rękawice powlekane op.12 par', 60, 'Rękawice powlekane op.6 par', 31, false],
            'opakowanie tylko u zamiennika' => ['Rękawice powlekane', 6, 'Rękawice powlekane 10 par', 7, false],
            'iloraz poza 0,4–2,5' => ['Rękawice robocze powlekane nitrylem', 10, 'Rękawice robocze powlekane PU', 30, false],
            'obuwie' => ['Półbuty robocze S3 SRC', 200, 'Półbuty robocze S3 ESD', 150, true],
            'nauszniki' => ['Nauszniki przeciwhałasowe SNR 30 dB', 100, 'Nauszniki przeciwhałasowe SNR 31 dB', 90, true],
            'wkładki' => ['Stopery przeciwhałasowe SNR 37', 1, 'Stopery przeciwhałasowe SNR 35', 1.2, false],
            'okulary' => ['Okulary ochronne bezbarwne', 20, 'Okulary ochronne przyciemniane', 18, false],
            'inna rodzina zamiennika' => ['Rękawice robocze powlekane nitrylem', 10, 'Półbuty robocze S1', 12, false],
        ];
        $pairs = [];
        foreach ($cases as $label => [$mainName, $mainPrice, $subName, $subPrice]) {
            $pairs[$label] = [$this->product($mainName, 'A', $mainPrice), $this->product($subName, 'B', $subPrice)];
        }
        $noPrice = $this->product('Rękawice robocze bez ceny', 'B', 0);
        $presenter->load([...collect($pairs)->flatten()->pluck('id')->all(), $noPrice->id]);

        foreach ($cases as $label => [, $mainPrice, , $subPrice, $comparable]) {
            [$main, $sub] = $pairs[$label];
            $price = $presenter->price((int) $main->id, (int) $sub->id);
            $this->assertSame($comparable, $price['comparable'], $label);
            if ($comparable) {
                $this->assertSame(round(($subPrice - $mainPrice) / $mainPrice * 100, 1), $price['diff_percent'], $label);
                $this->assertNull($price['note'], $label);
            } else {
                $this->assertNull($price['diff_percent'], $label);
                $this->assertSame(SubstituteBoardPresenter::NOTE_UNITS, $price['note'], $label);
            }
        }

        $missing = $presenter->price((int) $pairs['obuwie'][0]->id, (int) $noPrice->id);
        $this->assertSame(['main_pln' => 200.0, 'sub_pln' => null, 'diff_percent' => null, 'comparable' => false, 'note' => 'Brak ceny.'], $missing);
    }

    public function test_foreign_currency_card_is_priced_in_pln(): void
    {
        $this->actingAsRole('admin');
        $main = $this->product('Rękawice robocze w euro', 'A', 5, ['currency' => 'EUR']);
        $this->pair($main, $this->product('Rękawice robocze w złotych', 'B', 18));

        $this->getJson('/api/substitutes/board')->assertOk()
            ->assertJsonPath('data.0.main.currency', 'EUR')
            ->assertJsonPath('data.0.main.price_pln', 20)
            ->assertJsonPath('data.0.substitutes.0.price.diff_percent', -10);
    }

    public function test_board_requires_products_view(): void
    {
        Sanctum::actingAs($this->userWithCustomRole('bez-katalogu', ['dashboard.view']));

        $this->getJson('/api/substitutes/board')->assertForbidden();
        $this->getJson('/api/substitutes/summary')->assertForbidden();
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->userWithRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function product(string $name, string $manufacturer, float $price, array $extra = []): Product
    {
        $this->seq++;

        return Product::query()->create([
            'sku' => 'SKU-'.$this->seq,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => $price,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'stock' => 1,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pair(Product $main, Product $sub, array $attributes = []): ProductSubstitute
    {
        $auto = ($attributes['source'] ?? null) === 'automat';

        return ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'preferowany',
            'match_percent' => 80,
            'approval_status' => 'oczekuje',
            'reason' => 'Ten sam poziom ochrony',
            'generated_at' => $auto ? '2026-10-02 20:00:00' : null,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(bool $stale = false): array
    {
        $param = static fn (string $key, string $text, string $relation): array => [
            'key' => $key,
            'label' => $key,
            'main' => ['value' => $text, 'text' => $text, 'source' => 'norms', 'quote' => null, 'inferred' => false],
            'sub' => ['value' => $text, 'text' => $text, 'source' => 'description', 'quote' => null, 'inferred' => false],
            'relation' => $relation,
            'note' => null,
        ];

        return [
            'version' => 1,
            'rules' => 'sha1',
            'generated_at' => '2026-10-02T20:00:00+02:00',
            'family' => 'gloves',
            'family_label' => 'Rękawice',
            'verdict' => 'preferowany',
            'params' => [
                $param('article_type', 'Rękawice powlekane', 'equal'),
                $param('en388', 'EN 388:2016 4131X', 'equal'),
                $param('en407', 'EN 407 X1XXXX', 'higher'),
                $param('ppe_category', 'Kat. II', 'equal'),
                $param('cuff', 'Mankiet ściągacz', 'meets'),
                $param('coating', 'Powłoka nitryl', 'equal'),
            ],
            'extra_in_sub' => [],
            'not_checked' => ['rozmiarówka'],
            'fingerprints' => ['main' => 'a', 'sub' => 'b'],
            'stale' => $stale ? ['at' => '2026-10-02T21:00:00+02:00', 'reason' => 'Zmieniła się karta zamiennika'] : null,
        ];
    }
}
